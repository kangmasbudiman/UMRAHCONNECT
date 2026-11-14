<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * BP-07 Komisi (FR-FIN-07..09, CR-02): estimated saat booking → earned saat DP terverifikasi / lunas (sesuai snapshot)
 * → approved oleh Finance → paid lewat payout. Booking kedaluwarsa/batal membatalkan komisi yang belum dibayar.
 */
class CommissionService
{
    public function __construct(private NotificationService $notify) {}

    public static function amountFor(Booking $b): int
    {
        $s = $b->commission_snapshot;
        if (! $s) {
            return 0;
        }

        return $s['type'] === 'flat'
            ? (int) $s['flat_amount'] * $b->participant_count
            : (int) round($b->total_amount * (int) $s['percent_bp'] / 10000);
    }

    /** Dipanggil saat booking dibuat. */
    public function estimate(Booking $b): ?Commission
    {
        if (! $b->agent_id || ! $b->commission_snapshot) {
            return null;
        }

        return Commission::create([
            'tenant_id' => $b->tenant_id, 'booking_id' => $b->id, 'agent_id' => $b->agent_id, 'partnership_id' => $b->partnership_id,
            'amount' => self::amountFor($b), 'status' => 'estimated',
        ]);
    }

    /** Dipanggil setelah status booking berubah karena pembayaran. */
    public function onPaymentProgress(Booking $b): void
    {
        $c = Commission::withoutTenantScope()->where('booking_id', $b->id)->where('status', 'estimated')->lockForUpdate()->first();
        if (! $c) {
            return;
        }
        $earnOn = $b->commission_snapshot['earn_on'] ?? 'fully_paid';
        $reached = $earnOn === 'dp_verified' ? in_array($b->status, ['dp_verified', 'fully_paid'], true) : $b->status === 'fully_paid';
        if (! $reached) {
            return;
        }
        $c->update(['status' => 'earned', 'earned_at' => now()]);
        Audit::log('commission.earned', $c, ['status' => 'estimated'], ['status' => 'earned', 'amount' => $c->amount], tenantId: $c->tenant_id, context: 'system');
        $agent = $c->agent()->with('user')->first();
        $this->notify->send($agent->user, 'commission.earned', ['body' => "Komisi Rp".number_format($c->amount, 0, ',', '.')." dari booking {$b->code} diperoleh dan menunggu persetujuan PPIU.", 'url' => '/agen/komisi'], $c->tenant_id, true);
    }

    /** Booking kedaluwarsa/dibatalkan: komisi yang belum dibayar ikut batal. */
    public function onBookingVoided(Booking $b): void
    {
        $c = Commission::withoutTenantScope()->where('booking_id', $b->id)->whereIn('status', ['estimated', 'earned', 'approved'])->whereNull('payout_id')->first();
        if ($c) {
            $old = $c->status;
            $c->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            Audit::log('commission.cancelled', $c, ['status' => $old], ['status' => 'cancelled'], 'Booking '.$b->status, $c->tenant_id, 'system');
        }
    }

    /** @return Commission[] */
    public function approve(string $tenantId, array $ids, User $actor): array
    {
        return DB::transaction(function () use ($tenantId, $ids, $actor) {
            $rows = Commission::withoutTenantScope()->with('agent.user')->where('tenant_id', $tenantId)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($rows->count() !== count(array_unique($ids))) {
                throw ApiException::unprocessable('commission_invalid', 'Sebagian komisi tidak ditemukan di PPIU ini.');
            }
            if ($rows->contains(fn ($c) => $c->status !== 'earned')) {
                throw ApiException::conflict('commission_not_earned', 'Hanya komisi berstatus Diperoleh yang bisa disetujui.');
            }
            if ($rows->contains(fn ($c) => $c->agent->user_id === $actor->id)) {
                throw new ApiException(403, 'own_commission', 'Anda tidak boleh menyetujui komisi milik Anda sendiri.');
            }
            foreach ($rows as $c) {
                $c->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id]);
                Audit::log('commission.approved', $c, ['status' => 'earned'], ['status' => 'approved'], tenantId: $tenantId, context: 'tenant');
            }
            foreach ($rows->groupBy('agent_id') as $group) {
                $sum = $group->sum('amount');
                $this->notify->send($group->first()->agent->user, 'commission.approved', ['body' => count($group).' komisi senilai Rp'.number_format($sum, 0, ',', '.').' disetujui. Ajukan pencairan dari menu Komisi.', 'url' => '/agen/komisi'], $tenantId, true);
            }

            return $rows->all();
        });
    }

    /** Agen mengajukan, atau Finance membuat payout langsung. Semua komisi harus approved milik agen yang sama. */
    public function createPayout(string $tenantId, string $agentId, array $ids, User $actor): CommissionPayout
    {
        return DB::transaction(function () use ($tenantId, $agentId, $ids, $actor) {
            $rows = Commission::withoutTenantScope()->where('tenant_id', $tenantId)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($rows->isEmpty() || $rows->count() !== count(array_unique($ids))) {
                throw ApiException::unprocessable('commission_invalid', 'Pilih komisi yang valid dari PPIU ini.');
            }
            if ($rows->contains(fn ($c) => $c->agent_id !== $agentId || $c->status !== 'approved' || $c->payout_id)) {
                throw ApiException::conflict('commission_not_payable', 'Hanya komisi berstatus Disetujui yang belum diajukan yang bisa dicairkan.');
            }
            $p = CommissionPayout::create([
                'tenant_id' => $tenantId, 'agent_id' => $agentId, 'total_amount' => $rows->sum('amount'), 'status' => 'requested',
                'requested_by' => $actor->id, 'requested_at' => now(),
            ]);
            Commission::withoutTenantScope()->whereIn('id', $rows->pluck('id'))->update(['payout_id' => $p->id, 'updated_at' => now()]);
            Audit::log('payout.requested', $p, null, ['total' => $p->total_amount, 'count' => $rows->count()], tenantId: $tenantId, context: $actor->agent?->id === $agentId ? 'agent' : 'tenant');
            $this->notify->toTenant($tenantId, ['owner', 'finance'], 'payout.requested', ['body' => 'Pengajuan pencairan komisi Rp'.number_format($p->total_amount, 0, ',', '.').' menunggu pembayaran.', 'url' => "/dasbor/ppiu/{$tenantId}/komisi"]);

            return $p;
        });
    }

    public function pay(CommissionPayout $payout, string $paidAt, string $proofFileId, User $actor): CommissionPayout
    {
        return DB::transaction(function () use ($payout, $paidAt, $proofFileId, $actor) {
            $p = CommissionPayout::withoutTenantScope()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'requested') {
                throw ApiException::conflict('already_processed', 'Pencairan ini sudah diproses.');
            }
            $p->update(['status' => 'paid', 'paid_at' => $paidAt, 'paid_by' => $actor->id, 'proof_file_id' => $proofFileId]);
            Commission::withoutTenantScope()->where('payout_id', $p->id)->update(['status' => 'paid', 'paid_at' => $paidAt, 'updated_at' => now()]);
            Audit::log('payout.paid', $p, ['status' => 'requested'], ['status' => 'paid', 'total' => $p->total_amount], tenantId: $p->tenant_id, context: 'tenant');
            $agent = $p->agent()->with('user')->first();
            $this->notify->send($agent->user, 'payout.paid', ['body' => 'Komisi Rp'.number_format($p->total_amount, 0, ',', '.').' sudah ditransfer ke rekening Anda.', 'url' => '/agen/komisi'], $p->tenant_id, true);

            return $p;
        });
    }

    public function reject(CommissionPayout $payout, string $reason): CommissionPayout
    {
        return DB::transaction(function () use ($payout, $reason) {
            $p = CommissionPayout::withoutTenantScope()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'requested') {
                throw ApiException::conflict('already_processed', 'Pencairan ini sudah diproses.');
            }
            $p->update(['status' => 'rejected', 'reject_reason' => $reason]);
            Commission::withoutTenantScope()->where('payout_id', $p->id)->update(['payout_id' => null, 'updated_at' => now()]);
            Audit::log('payout.rejected', $p, ['status' => 'requested'], ['status' => 'rejected'], $reason, $p->tenant_id, 'tenant');
            $agent = $p->agent()->with('user')->first();
            $this->notify->send($agent->user, 'payout.rejected', ['body' => "Pengajuan pencairan ditolak: {$reason}. Komisi kembali berstatus Disetujui.", 'url' => '/agen/komisi'], $p->tenant_id, true);

            return $p;
        });
    }
}
