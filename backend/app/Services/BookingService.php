<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\BankAccount;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Departure;
use App\Models\Invoice;
use App\Models\Partnership;
use App\Models\Pilgrim;
use App\Models\ReferralLink;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Commission;
use App\Support\DocumentNumber;
use App\Support\Otp\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BP-05 Booking: menahan kursi dengan penguncian baris keberangkatan (CR-03), menetapkan atribusi agen (CR-01),
 * menyimpan snapshot komisi (CR-02), dan menerbitkan invoice DP + pelunasan (CR-04).
 */
class BookingService
{
    public const MAX_PARTICIPANTS = 10;

    public function __construct(private BookingExpiry $expiry, private CommissionService $commissions, private NotificationService $notify) {}

    /**
     * @param  array{departure_id:string, participants:array, referral_code?:?string, agent_code?:?string}  $input
     * @param  array{channel:string, booker:User, actor:User, agent?:?Agent, staff_agent_id?:?string, tenant?:?Tenant}  $ctx
     */
    public function create(array $input, array $ctx): Booking
    {
        $n = count($input['participants']);
        if ($n < 1 || $n > self::MAX_PARTICIPANTS) {
            throw ApiException::unprocessable('participants_invalid', 'Jumlah peserta 1–'.self::MAX_PARTICIPANTS.' orang per booking.');
        }
        $seatsError = fn (int $remaining) => new ApiException(409, 'seats_unavailable', $remaining === 0
            ? 'Maaf, kursi keberangkatan ini sudah habis.'
            : "Sisa kursi tinggal {$remaining}. Kurangi jumlah peserta atau pilih keberangkatan lain.", ['seats_remaining' => $remaining]);

        // 0. Tanpa kunci: tolak cepat bila kursi jelas habis (dan tidak ada hold kedaluwarsa yang bisa dilepas),
        //    supaya permintaan yang pasti gagal tidak ikut mengantre di kunci keberangkatan (TC-PERF-02).
        $pre = Departure::withoutTenantScope()->whereKey($input['departure_id'])->first()
            ?? throw new ApiException(404, 'not_found', 'Keberangkatan tidak ditemukan.');
        if (isset($ctx['tenant']) && $ctx['tenant']->id !== $pre->tenant_id) {
            throw new ApiException(404, 'not_found', 'Keberangkatan tidak ditemukan.');
        }
        if ($pre->seatsRemaining() < $n && ! $this->expiry->hasDue($pre)) {
            throw $seatsError($pre->seatsRemaining());
        }

        return DB::transaction(function () use ($input, $ctx, $n, $pre, $seatsError) {
            // Pekerjaan yang tidak bergantung pada sisa kursi dikerjakan sebelum kunci diambil.
            $prices = $pre->prices()->withoutTenantScope()->pluck('price_amount', 'room_type');
            foreach ($input['participants'] as $i => $p) {
                if (! isset($prices[$p['room_type']])) {
                    throw new ApiException(422, 'room_type_unavailable', 'Tipe kamar ini tidak dijual untuk keberangkatan tersebut.', errors: ["participants.$i.room_type" => ['Tidak tersedia.']]);
                }
            }
            $booker = $ctx['booker'];
            $pilgrims = $this->resolvePilgrims($input['participants'], $booker, $ctx['actor']);

            // 1. Kunci baris keberangkatan: booking bersamaan untuk kursi yang sama antre di sini.
            $dep = Departure::withoutTenantScope()->whereKey($pre->id)->lockForUpdate()->first()
                ?? throw new ApiException(404, 'not_found', 'Keberangkatan tidak ditemukan.');
            $tenant = Tenant::findOrFail($dep->tenant_id);
            $pkg = $dep->package()->withoutTenantScope()->first();
            $this->assertSellable($tenant, $pkg, $dep, $ctx['channel']);

            // Hold kedaluwarsa di keberangkatan ini dilepas dulu (JOB-01 juga berjalan terjadwal).
            $this->expiry->expireForLockedDeparture($dep);

            $remaining = $dep->seatsRemaining();
            if ($remaining < $n) {
                throw $seatsError($remaining);
            }
            // Harga dibaca ulang di bawah kunci agar perubahan harga yang baru saja disimpan ikut terpakai (AC-11).
            $prices = $dep->prices()->withoutTenantScope()->pluck('price_amount', 'room_type');
            foreach ($input['participants'] as $i => $p) {
                if (! isset($prices[$p['room_type']])) {
                    throw new ApiException(422, 'room_type_unavailable', 'Tipe kamar ini tidak dijual untuk keberangkatan tersebut.', errors: ["participants.$i.room_type" => ['Tidak tersedia.']]);
                }
            }
            $already = BookingParticipant::withoutTenantScope()->where('departure_id', $dep->id)->where('is_active', true)
                ->whereIn('pilgrim_id', collect($pilgrims)->pluck('id'))->with('pilgrim')->get();
            if ($already->isNotEmpty()) {
                throw new ApiException(409, 'pilgrim_already_booked', 'Jamaah berikut sudah terdaftar di keberangkatan ini: '.$already->pluck('pilgrim.full_name')->join(', ').'.',
                    ['pilgrim_ids' => $already->pluck('pilgrim_id')->values()]);
            }

            [$agent, $partnership, $source, $link] = $this->attribution($input, $ctx, $tenant, $booker);
            $snapshot = null;
            if ($agent) {
                $s = Commission::effective($tenant->id, $agent->id, $pkg->id);
                $snapshot = $s ? ['scheme_id' => $s->id, 'scope' => $s->scope, 'type' => $s->type, 'flat_amount' => $s->flat_amount, 'percent_bp' => $s->percent_bp, 'earn_on' => $s->earn_on] : null;
            }

            $total = 0;
            foreach ($input['participants'] as $p) {
                $total += (int) $prices[$p['room_type']];
            }
            $holdUntil = now()->addHours($dep->dp_deadline_hours);
            // CR-04: booking yang dibuat setelah batas pelunasan (atau batasnya jatuh sebelum tenggat DP) dibayar penuh sekaligus.
            $finalDue = \Illuminate\Support\Carbon::parse($dep->final_payment_due_on->toDateString(), 'Asia/Jakarta')->endOfDay();
            $fullOnly = $finalDue->lessThanOrEqualTo($holdUntil);
            $dp = $fullOnly ? $total : min($total, $dep->dp_min_per_pax_amount * $n);

            $booking = Booking::create([
                'tenant_id' => $tenant->id,
                'code' => DocumentNumber::bookingCode(),
                'departure_id' => $dep->id,
                'package_id' => $pkg->id,
                'booker_user_id' => $booker->id,
                'agent_id' => $agent?->id,
                'partnership_id' => $partnership?->id,
                'attribution_source' => $source,
                'referral_link_id' => $link?->id,
                'channel' => $ctx['channel'],
                'status' => 'awaiting_dp',
                'participant_count' => $n,
                'total_amount' => $total,
                'dp_amount' => $dp,
                'paid_amount' => 0,
                'commission_snapshot' => $snapshot,
                'hold_expires_at' => $holdUntil,
                'confirmed_at' => now(),
                'created_by' => $ctx['actor']->id,
            ]);
            foreach ($input['participants'] as $i => $p) {
                BookingParticipant::create([
                    'tenant_id' => $tenant->id, 'booking_id' => $booking->id, 'departure_id' => $dep->id,
                    'pilgrim_id' => $pilgrims[$i]->id, 'room_type' => $p['room_type'], 'price_amount' => (int) $prices[$p['room_type']],
                ]);
            }

            $dep->seats_held += $n;
            if ($dep->seatsRemaining() === 0 && $dep->sales_status === 'open') {
                $dep->sales_status = 'full';
            }
            $dep->save();

            $bank = BankAccount::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', 'verified')->orderByDesc('is_default')->first()
                ?? throw ApiException::unprocessable('tenant_no_bank_account', 'PPIU belum memiliki rekening terverifikasi. Hubungi PPIU.');
            $mk = fn (string $type, int $amount, $due) => Invoice::create([
                'tenant_id' => $tenant->id, 'booking_id' => $booking->id, 'number' => DocumentNumber::invoiceNumber($tenant->id, $tenant->code),
                'type' => $type, 'amount' => $amount, 'due_at' => $due, 'bank_account_id' => $bank->id, 'status' => 'unpaid',
            ]);
            if ($fullOnly) {
                $mk('full', $total, $holdUntil);
            } else {
                $mk('dp', $dp, $holdUntil);
                if ($total > $dp) {
                    $mk('final', $total - $dp, $finalDue->utc());
                }
            }

            $this->commissions->estimate($booking);
            $this->notifyCreated($booking, $tenant, $pkg);

            Audit::log('booking.created', $booking, null, [
                'code' => $booking->code, 'channel' => $booking->channel, 'participants' => $n, 'total' => $total, 'attribution' => $source, 'agent_id' => $agent?->id,
            ], tenantId: $tenant->id, context: match ($ctx['channel']) { 'agent_portal' => 'agent', 'ppiu_dashboard' => 'tenant', default => 'pilgrim' });

            return $booking;
        });
    }

    private function notifyCreated(Booking $b, Tenant $tenant, $pkg): void
    {
        $booker = User::find($b->booker_user_id);
        $body = "Booking {$b->code} untuk {$pkg->name} ({$b->participant_count} jamaah) dibuat. Bayar DP Rp".number_format($b->dp_amount, 0, ',', '.')
            .' sebelum '.$b->hold_expires_at->setTimezone('Asia/Jakarta')->translatedFormat('j M Y H:i').' WIB ke rekening resmi '.$tenant->brand_name.'.';
        if ($booker && $booker->status !== 'unclaimed') {
            $this->notify->send($booker, 'booking.created', ['body' => $body, 'url' => "/akun/booking/{$b->id}"], $tenant->id, true);
        } elseif ($booker) {
            $phone = $booker->phone;
            DB::afterCommit(fn () => \App\Support\WhatsApp::send($phone, $body.' Lacak: '.rtrim(config('app.web_url'), '/').'/lacak?kode='.$b->code));
        }
        $this->notify->toTenant($tenant->id, ['owner', 'admin'], 'booking.created_staff', ['body' => "Booking baru {$b->code}: {$pkg->name}, {$b->participant_count} jamaah.", 'url' => "/dasbor/ppiu/{$tenant->id}/booking/{$b->id}"]);
    }

    /** Pemesan untuk booking agen/staf: cari pengguna dari nomor HP atau buat akun unclaimed (BR-04). */
    public function bookerFromInput(array $booker): User
    {
        $phone = OtpService::normalizePhone($booker['phone']);
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            throw new ApiException(422, 'validation_failed', 'Nomor HP pemesan tidak valid.', errors: ['booker.phone' => ['Contoh: 081234567890.']]);
        }

        return User::where('phone', $phone)->first()
            ?? User::create(['name' => $booker['name'], 'phone' => $phone, 'status' => 'unclaimed']);
    }

    private function assertSellable(Tenant $tenant, $pkg, Departure $dep, string $channel): void
    {
        $today = now('Asia/Jakarta')->toDateString();
        $staff = $channel === 'ppiu_dashboard';
        $pkgOk = $staff ? in_array($pkg->status, ['draft', 'published'], true) : $pkg->status === 'published';
        if ($tenant->verification_status !== 'verified' || ! $pkgOk) {
            throw ApiException::unprocessable('departure_unavailable', 'Paket ini sedang tidak dijual.');
        }
        if ($dep->status !== 'scheduled' || $dep->departure_date->toDateString() <= $today) {
            throw ApiException::unprocessable('departure_unavailable', 'Keberangkatan ini sudah tidak menerima pendaftaran.');
        }
        if ($dep->sales_status === 'closed') {
            throw ApiException::unprocessable('sales_closed', 'Penjualan keberangkatan ini sedang ditutup oleh PPIU.');
        }
    }

    /** @return Pilgrim[] indeks sama dengan participants */
    private function resolvePilgrims(array $participants, User $booker, User $actor): array
    {
        $out = [];
        $seen = [];
        foreach ($participants as $i => $p) {
            if (! empty($p['pilgrim_id'])) {
                $pil = Pilgrim::where('managed_by_user_id', $booker->id)->whereKey($p['pilgrim_id'])->first()
                    ?? throw new ApiException(422, 'pilgrim_invalid', 'Data jamaah tidak ditemukan.', errors: ["participants.$i.pilgrim_id" => ['Tidak ditemukan.']]);
            } else {
                $pil = PilgrimService::upsert($booker, $p['pilgrim']);
            }
            if (isset($seen[$pil->id])) {
                throw new ApiException(422, 'pilgrim_duplicate', 'Satu jamaah hanya boleh tercantum sekali dalam booking.', errors: ["participants.$i" => ['Duplikat.']]);
            }
            $seen[$pil->id] = true;
            $out[$i] = $pil;
        }

        return $out;
    }

    /**
     * CR-01: agen dari portal agen → agent_created; staf memilih agen → ppiu_assigned;
     * kode agen diketik pemesan → manual_code; cookie link referral (≤30 hari, dicek web) → referral_link; selain itu none.
     * Agen tidak mendapat atribusi atas booking dirinya sendiri.
     *
     * @return array{0:?Agent,1:?Partnership,2:string,3:?ReferralLink}
     */
    private function attribution(array $input, array $ctx, Tenant $tenant, User $booker): array
    {
        $activePartner = fn (?Agent $a) => $a && $a->status === 'active'
            ? Partnership::withoutTenantScope()->where('tenant_id', $tenant->id)->where('agent_id', $a->id)->where('status', 'active')->first()
            : null;

        if ($ctx['channel'] === 'agent_portal') {
            $p = $activePartner($ctx['agent']) ?? throw new ApiException(403, 'partnership_inactive', 'Anda belum bermitra aktif dengan PPIU ini.');

            return [$ctx['agent'], $p, 'agent_created', null];
        }
        if ($ctx['channel'] === 'ppiu_dashboard') {
            if (! empty($ctx['staff_agent_id'])) {
                $a = Agent::find($ctx['staff_agent_id']);
                $p = $activePartner($a) ?? throw new ApiException(422, 'agent_not_partner', 'Agen yang dipilih bukan mitra aktif PPIU ini.', errors: ['agent_id' => ['Bukan mitra aktif.']]);

                return [$a, $p, 'ppiu_assigned', null];
            }

            return [null, null, 'none', null];
        }

        $code = Str::upper(trim((string) ($input['agent_code'] ?? '')));
        if ($code !== '') {
            $a = Agent::where('code', $code)->first();
            $p = $activePartner($a);
            if (! $p) {
                throw new ApiException(422, 'agent_code_invalid', 'Kode agen tidak dikenal atau bukan mitra PPIU ini. Kosongkan bila tidak ada.', errors: ['agent_code' => ['Kode tidak valid untuk PPIU ini.']]);
            }

            return $a->user_id === $booker->id ? [null, null, 'none', null] : [$a, $p, 'manual_code', null];
        }
        $ref = Str::upper(trim((string) ($input['referral_code'] ?? '')));
        if ($ref !== '') {
            $link = ReferralLink::withoutTenantScope()->with('agent')->where('code', $ref)->where('is_active', true)->where('tenant_id', $tenant->id)->first();
            $p = $link ? $activePartner($link->agent) : null;
            if ($p && $p->id === $link->partnership_id && $link->agent->user_id !== $booker->id) {
                return [$link->agent, $p, 'referral_link', $link];
            }
        }

        return [null, null, 'none', null];
    }
}
