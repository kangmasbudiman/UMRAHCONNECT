<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Booking;
use App\Models\BookingNote;
use App\Models\Commission;
use App\Models\Partnership;
use App\Models\User;
use App\Support\Audit;
use App\Support\Commission as CommissionRule;
use Illuminate\Support\Facades\DB;

/**
 * FR-PPIU-14 / BR-09: Owner mengubah agen pada booking yang belum lunas, alasan wajib, tercatat di audit log (AC-12).
 * Komisi lama yang belum disetujui ikut berpindah: dihitung ulang dari skema yang berlaku untuk agen baru (CR-02).
 */
class AttributionService
{
    public function __construct(private CommissionService $commissions, private NotificationService $notify) {}

    public function change(Booking $booking, ?string $agentId, string $reason, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $agentId, $reason, $actor) {
            $b = Booking::withoutTenantScope()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($b->status, ['awaiting_dp', 'dp_verified'], true)) {
                throw new ApiException(409, 'booking_not_changeable', 'Atribusi hanya bisa diubah selama booking belum lunas.');
            }
            if ($agentId === $b->agent_id) {
                throw new ApiException(422, 'attribution_unchanged', 'Agen yang dipilih sama dengan atribusi saat ini.', errors: ['agent_id' => ['Pilih agen lain atau "tanpa agen".']]);
            }

            $commission = Commission::withoutTenantScope()->where('booking_id', $b->id)->lockForUpdate()->first();
            if ($commission && (in_array($commission->status, ['approved', 'paid'], true) || $commission->payout_id)) {
                throw new ApiException(409, 'commission_locked', 'Komisi booking ini sudah disetujui atau dicairkan, sehingga atribusi tidak bisa diubah.');
            }

            $agent = null;
            $partnership = null;
            if ($agentId) {
                $agent = Agent::with('user')->find($agentId);
                $partnership = $agent && $agent->status === 'active'
                    ? Partnership::withoutTenantScope()->where('tenant_id', $b->tenant_id)->where('agent_id', $agent->id)->where('status', 'active')->first()
                    : null;
                if (! $partnership) {
                    throw new ApiException(422, 'agent_not_partner', 'Agen yang dipilih bukan mitra aktif PPIU ini.', errors: ['agent_id' => ['Bukan mitra aktif.']]);
                }
                if ($agent->user_id === $b->booker_user_id) {
                    throw new ApiException(422, 'self_attribution', 'Agen tidak bisa mendapat atribusi atas booking dirinya sendiri.', errors: ['agent_id' => ['Agen ini adalah pemesan booking.']]);
                }
            }

            $oldAgent = $b->agent_id ? Agent::with('user')->find($b->agent_id) : null;
            $old = ['agent_id' => $b->agent_id, 'attribution_source' => $b->attribution_source, 'commission_amount' => $commission?->amount];

            $snapshot = null;
            if ($agent) {
                $s = CommissionRule::effective($b->tenant_id, $agent->id, $b->package_id);
                if (! $s) {
                    throw new ApiException(422, 'scheme_required', 'Belum ada skema komisi yang berlaku untuk agen ini. Atur skema komisi dulu.');
                }
                $snapshot = ['scheme_id' => $s->id, 'scope' => $s->scope, 'type' => $s->type, 'flat_amount' => $s->flat_amount, 'percent_bp' => $s->percent_bp, 'earn_on' => $s->earn_on];
            }

            $b->update([
                'agent_id' => $agent?->id, 'partnership_id' => $partnership?->id, 'referral_link_id' => null,
                'attribution_source' => $agent ? 'ppiu_assigned' : 'none', 'commission_snapshot' => $snapshot,
            ]);

            // commissions.booking_id unik: komisi lama dihapus (jejaknya ada di audit log), lalu dihitung ulang untuk agen baru.
            if ($commission) {
                Audit::log('commission.cancelled', $commission, ['status' => $commission->status, 'agent_id' => $commission->agent_id], ['status' => 'removed'], 'Atribusi booking diubah: '.$reason, $b->tenant_id, 'tenant');
                $commission->delete();
            }
            if ($agent) {
                $this->commissions->estimate($b);
                $this->commissions->onPaymentProgress($b);
            }
            $newAmount = Commission::withoutTenantScope()->where('booking_id', $b->id)->value('amount');

            Audit::log('booking.attribution_changed', $b, $old,
                ['agent_id' => $agent?->id, 'attribution_source' => $b->attribution_source, 'commission_amount' => $newAmount], $reason, $b->tenant_id, 'tenant');
            BookingNote::create(['tenant_id' => $b->tenant_id, 'booking_id' => $b->id, 'author_id' => $actor->id,
                'body' => 'Atribusi agen diubah dari '.($oldAgent ? "{$oldAgent->user?->name} ({$oldAgent->code})" : 'tanpa agen').' ke '.($agent ? "{$agent->user?->name} ({$agent->code})" : 'tanpa agen').'. Alasan: '.$reason]);

            if ($oldAgent?->user) {
                $this->notify->send($oldAgent->user, 'booking.attribution_changed', ['body' => "Booking {$b->code} tidak lagi tercatat atas nama Anda. Alasan dari PPIU: {$reason}", 'url' => '/agen/booking'], $b->tenant_id, true);
            }
            if ($agent?->user) {
                $this->notify->send($agent->user, 'booking.attribution_changed', ['body' => "Booking {$b->code} kini tercatat atas nama Anda.", 'url' => "/agen/booking/{$b->id}"], $b->tenant_id, true);
            }

            return $b->fresh();
        });
    }
}
