<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Departure;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** BP-08 Pembatalan dan refund (FR-JMH-10, FR-FIN-13). Nominal refund diputuskan PPIU sesuai kebijakan paket. */
class CancellationService
{
    public function __construct(private PaymentService $payments, private CommissionService $commissions, private NotificationService $notify, private BillingService $billing) {}

    public function request(Booking $booking, string $reason): Booking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $b = Booking::withoutTenantScope()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($b->status, Booking::ACTIVE, true)) {
                throw ApiException::conflict('booking_not_active', 'Booking ini sudah tidak aktif.');
            }
            if ($b->cancel_request_status === 'pending') {
                throw ApiException::conflict('cancel_request_pending', 'Pengajuan pembatalan sebelumnya masih diproses PPIU.');
            }
            $b->update(['cancel_request_status' => 'pending', 'cancel_requested_at' => now(), 'cancel_request_reason' => $reason]);
            Audit::log('booking.cancel_requested', $b, null, ['reason' => $reason], $reason, $b->tenant_id, 'pilgrim');
            $this->notify->toTenant($b->tenant_id, ['owner', 'admin'], 'booking.cancel_requested', ['body' => "Jamaah mengajukan pembatalan booking {$b->code}: {$reason}", 'url' => "/dasbor/ppiu/{$b->tenant_id}/booking/{$b->id}"], true);

            return $b;
        });
    }

    public function rejectRequest(Booking $booking, string $reason): Booking
    {
        if ($booking->cancel_request_status !== 'pending') {
            throw ApiException::conflict('no_pending_request', 'Tidak ada pengajuan pembatalan yang menunggu.');
        }
        $booking->update(['cancel_request_status' => 'rejected']);
        Audit::log('booking.cancel_request_rejected', $booking, null, null, $reason, $booking->tenant_id, 'tenant');

        return $booking;
    }

    public function cancel(Booking $booking, string $reason, int $refund, string $basis, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $refund, $basis, $actor) {
            $b = Booking::withoutTenantScope()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($b->status, Booking::ACTIVE, true)) {
                throw ApiException::conflict('booking_not_active', 'Booking ini sudah tidak aktif.');
            }
            if ($refund > $b->paid_amount) {
                throw new ApiException(422, 'refund_exceeds_paid', 'Refund tidak boleh melebihi total yang sudah dibayar ('.number_format($b->paid_amount, 0, ',', '.').').', errors: ['refund_amount' => ['Melebihi yang sudah dibayar.']]);
            }
            $dep = Departure::withoutTenantScope()->whereKey($b->departure_id)->lockForUpdate()->firstOrFail();
            if ($b->status === 'awaiting_dp') {
                $dep->seats_held = max(0, $dep->seats_held - $b->participant_count);
            } else {
                $dep->seats_confirmed = max(0, $dep->seats_confirmed - $b->participant_count);
            }
            if ($dep->sales_status === 'full' && $dep->seatsRemaining() > 0) {
                $dep->sales_status = 'open';
            }
            $dep->save();

            BookingParticipant::withoutTenantScope()->where('booking_id', $b->id)->update(['is_active' => false, 'updated_at' => now()]);
            $invoiceIds = Invoice::withoutTenantScope()->where('booking_id', $b->id)->pluck('id');
            Invoice::withoutTenantScope()->whereIn('id', $invoiceIds)->whereIn('status', ['unpaid', 'partial'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            Payment::withoutTenantScope()->whereIn('invoice_id', $invoiceIds)->where('status', 'pending')
                ->update(['status' => 'rejected', 'reject_reason' => 'Booking dibatalkan', 'updated_at' => now()]);

            $old = $b->status;
            $b->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason,
                'refund_decided_amount' => $refund, 'refund_basis' => $basis,
                'cancel_request_status' => $b->cancel_request_status === 'pending' ? 'approved' : $b->cancel_request_status,
            ]);
            $this->commissions->onBookingVoided($b);
            $this->billing->waiveTransactionFee($b);
            $booker = User::find($b->booker_user_id);
            if ($booker && $booker->status !== 'unclaimed') {
                $this->notify->send($booker, 'booking.cancelled', ['body' => "Booking {$b->code} dibatalkan. Refund yang diputuskan: Rp".number_format($refund, 0, ',', '.')." ({$basis}).", 'url' => "/akun/booking/{$b->id}"], $b->tenant_id, true);
            }
            Audit::log('booking.cancelled', $b, ['status' => $old], ['status' => 'cancelled', 'refund_decided_amount' => $refund], $reason, $b->tenant_id, 'tenant');

            return $b;
        });
    }

    /** Catat refund transfer atau pengalihan dana ke booking lain (tidak boleh melebihi nominal yang diputuskan). */
    public function recordRefund(Booking $booking, array $d, User $actor): Refund
    {
        return DB::transaction(function () use ($booking, $d, $actor) {
            $b = Booking::withoutTenantScope()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($b->status !== 'cancelled' || $b->refund_decided_amount === null) {
                throw ApiException::conflict('booking_not_cancelled', 'Refund hanya untuk booking yang sudah dibatalkan dengan nominal refund.');
            }
            $done = (int) Refund::withoutTenantScope()->where('booking_id', $b->id)->sum('amount');
            if ($done + $d['amount'] > $b->refund_decided_amount) {
                throw new ApiException(422, 'refund_exceeds_paid', 'Total refund melebihi nominal yang diputuskan (sisa '.number_format($b->refund_decided_amount - $done, 0, ',', '.').').', ['remaining' => $b->refund_decided_amount - $done], ['amount' => ['Melebihi sisa refund.']]);
            }
            $target = null;
            if ($d['type'] === 'reallocation') {
                $target = Booking::withoutTenantScope()->where('tenant_id', $b->tenant_id)->whereKey($d['target_booking_id'] ?? null)->first()
                    ?? throw new ApiException(422, 'target_invalid', 'Booking tujuan tidak ditemukan di PPIU ini.', errors: ['target_booking_id' => ['Tidak ditemukan.']]);
                $inv = Invoice::withoutTenantScope()->where('booking_id', $target->id)->whereIn('status', ['unpaid', 'partial'])
                    ->orderByRaw("CASE type WHEN 'final' THEN 1 ELSE 0 END")->first()
                    ?? throw ApiException::unprocessable('target_invalid', 'Booking tujuan tidak punya tagihan terbuka.');
                $this->payments->recordDirect($inv, ['method' => 'reallocation', 'amount' => $d['amount'], 'paid_on' => $d['paid_on'], 'sender_name' => 'Pengalihan dari '.$b->code], $actor);
            }
            $r = Refund::create([
                'tenant_id' => $b->tenant_id, 'booking_id' => $b->id, 'type' => $d['type'], 'amount' => $d['amount'],
                'target_booking_id' => $target?->id, 'paid_on' => $d['paid_on'], 'proof_file_id' => $d['proof_file_id'] ?? null,
                'note' => $d['note'], 'recorded_by' => $actor->id,
            ]);
            Audit::log('refund.recorded', $r, null, ['type' => $d['type'], 'amount' => $d['amount'], 'target' => $target?->code], $d['note'], $b->tenant_id, 'tenant');

            return $r;
        });
    }
}
