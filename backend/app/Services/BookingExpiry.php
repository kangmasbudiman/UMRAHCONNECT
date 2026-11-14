<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Departure;
use App\Models\Invoice;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * JOB-01: booking menunggu DP yang melewati tenggat dan belum ada pembayaran terverifikasi menjadi expired;
 * kursi dilepas, peserta nonaktif, invoice dibatalkan. Booking dengan pembayaran sebagian tidak diproses
 * otomatis (isu terbuka TST-01) — PPIU memutuskan manual.
 */
class BookingExpiry
{
    public function __construct(private CommissionService $commissions, private NotificationService $notify) {}

    /** Dipanggil saat baris keberangkatan sudah dikunci (di dalam transaksi). */
    public function expireForLockedDeparture(Departure $dep): int
    {
        $due = $this->dueQuery()->where('departure_id', $dep->id)->lockForUpdate()->get();
        foreach ($due as $b) {
            $this->expireOne($b, $dep);
        }
        if ($due->isNotEmpty()) {
            $dep->save();
        }

        return $due->count();
    }

    /** Untuk perintah terjadwal: proses semua keberangkatan yang punya hold kedaluwarsa. */
    public function expireAllDue(): int
    {
        $depIds = $this->dueQuery()->distinct()->pluck('departure_id');
        $count = 0;
        foreach ($depIds as $id) {
            $count += DB::transaction(function () use ($id) {
                $dep = Departure::withoutTenantScope()->whereKey($id)->lockForUpdate()->first();

                return $dep ? $this->expireForLockedDeparture($dep) : 0;
            });
        }

        return $count;
    }

    /**
     * Menunggu DP, lewat tenggat, belum ada pembayaran terverifikasi, dan tidak ada bukti bayar yang masih menunggu
     * (bukti yang diunggah sebelum tenggat diputuskan dulu oleh PPIU; bila ditolak, booking kedaluwarsa di putaran berikutnya).
     */
    /** Ada hold kedaluwarsa yang akan dilepas saat keberangkatan ini dikunci? (tanpa kunci) */
    public function hasDue(Departure $dep): bool
    {
        return $this->dueQuery()->where('departure_id', $dep->id)->exists();
    }

    private function dueQuery()
    {
        return Booking::withoutTenantScope()->where('status', 'awaiting_dp')->where('paid_amount', 0)->where('hold_expires_at', '<', now())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
                ->whereColumn('invoices.booking_id', 'bookings.id')->where('payments.status', 'pending'));
    }

    private function expireOne(Booking $b, Departure $dep): void
    {
        $b->update(['status' => 'expired', 'hold_expires_at' => $b->hold_expires_at]);
        BookingParticipant::withoutTenantScope()->where('booking_id', $b->id)->update(['is_active' => false, 'updated_at' => now()]);
        Invoice::withoutTenantScope()->where('booking_id', $b->id)->whereIn('status', ['unpaid', 'partial'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        $dep->seats_held = max(0, $dep->seats_held - $b->participant_count);
        if ($dep->sales_status === 'full' && $dep->seatsRemaining() > 0) {
            $dep->sales_status = 'open';
        }
        $this->commissions->onBookingVoided($b->fresh());
        $booker = \App\Models\User::find($b->booker_user_id);
        if ($booker && $booker->status !== 'unclaimed') {
            $this->notify->send($booker, 'booking.expired', ['body' => "Booking {$b->code} kedaluwarsa karena DP belum dibayar sebelum tenggat. Kursi sudah dilepas.", 'url' => "/akun/booking/{$b->id}"], $b->tenant_id, true);
        }
        Audit::log('booking.expired', $b, ['status' => 'awaiting_dp'], ['status' => 'expired'], 'Tenggat DP terlewati', $b->tenant_id, 'system');
    }
}
