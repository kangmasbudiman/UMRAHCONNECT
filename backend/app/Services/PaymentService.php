<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * BP-06 Pembayaran (FR-FIN-01..06, FR-FIN-14):
 * - bukti bayar masuk antrean (pending), diputuskan PPIU dengan nominal terverifikasi;
 * - kelebihan bayar dipecah ke invoice berikutnya (split_from_payment_id);
 * - setiap pembayaran terverifikasi mendapat kwitansi bernomor;
 * - DP lunas → dp_verified (kursi ditahan → terkonfirmasi); total lunas → fully_paid.
 */
class PaymentService
{
    private const OPEN_BOOKING = ['awaiting_dp', 'dp_verified'];

    public function __construct(private CommissionService $commissions, private NotificationService $notify) {}

    public function submitProof(Invoice $invoice, array $d, User $actor): Payment
    {
        return DB::transaction(function () use ($invoice, $d, $actor) {
            $booking = Booking::withoutTenantScope()->whereKey($invoice->booking_id)->lockForUpdate()->firstOrFail();
            $this->assertPayable($booking, $invoice);
            $remaining = $booking->total_amount - $booking->paid_amount;
            if ($d['amount'] > $remaining) {
                throw new ApiException(422, 'amount_exceeds_remaining', 'Nominal melebihi sisa tagihan booking ('.number_format($remaining, 0, ',', '.').').', errors: ['amount' => ['Melebihi sisa tagihan.']]);
            }
            $p = Payment::create([
                'tenant_id' => $booking->tenant_id, 'invoice_id' => $invoice->id, 'method' => 'bank_transfer',
                'claimed_amount' => $d['amount'], 'paid_on' => $d['paid_on'], 'sender_bank' => $d['sender_bank'] ?? null,
                'sender_name' => $d['sender_name'] ?? null, 'proof_file_id' => $d['proof_file_id'], 'status' => 'pending', 'created_by' => $actor->id,
            ]);
            Audit::log('payment.submitted', $p, null, ['invoice' => $invoice->number, 'amount' => $d['amount']], tenantId: $booking->tenant_id, context: 'pilgrim');
            $this->notify->toTenant($booking->tenant_id, ['owner', 'finance'], 'payment.submitted', ['body' => "Bukti bayar Rp".number_format($d['amount'], 0, ',', '.')." untuk booking {$booking->code} menunggu verifikasi.", 'url' => "/dasbor/ppiu/{$booking->tenant_id}/pembayaran"]);

            return $p;
        });
    }

    /** @return Payment[] pembayaran terverifikasi (asli + pecahan) */
    public function verify(Payment $payment, int $amount, User $actor): array
    {
        return DB::transaction(function () use ($payment, $amount, $actor) {
            $p = Payment::withoutTenantScope()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'pending') {
                throw ApiException::conflict('already_processed', 'Pembayaran ini sudah diputuskan oleh pengguna lain. Muat ulang antrean.');
            }
            if ($amount < 1) {
                throw ApiException::unprocessable('validation_failed', 'Nominal terverifikasi minimal Rp1.');
            }
            $invoice = Invoice::withoutTenantScope()->findOrFail($p->invoice_id);
            $booking = Booking::withoutTenantScope()->whereKey($invoice->booking_id)->lockForUpdate()->firstOrFail();
            if (! in_array($booking->status, self::OPEN_BOOKING, true)) {
                throw ApiException::conflict('booking_not_active', 'Booking ini sudah '.($booking->status === 'expired' ? 'kedaluwarsa' : ($booking->status === 'cancelled' ? 'dibatalkan' : 'lunas')).'; pembayaran tidak bisa diverifikasi. Tolak bukti ini dan tangani manual.');
            }

            return $this->apply($booking, $p, $amount, $actor);
        });
    }

    public function reject(Payment $payment, string $reason, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $p = Payment::withoutTenantScope()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'pending') {
                throw ApiException::conflict('already_processed', 'Pembayaran ini sudah diputuskan oleh pengguna lain.');
            }
            $p->update(['status' => 'rejected', 'reject_reason' => $reason]);
            Audit::log('payment.rejected', $p, ['status' => 'pending'], ['status' => 'rejected'], $reason, $p->tenant_id, 'tenant');
            $b = Booking::withoutTenantScope()->with('booker')->find(Invoice::withoutTenantScope()->whereKey($p->invoice_id)->value('booking_id'));
            if ($b?->booker && $b->booker->status !== 'unclaimed') {
                $this->notify->send($b->booker, 'payment.rejected', ['body' => "Bukti bayar untuk booking {$b->code} ditolak: {$reason}. Silakan kirim ulang bukti yang benar.", 'url' => "/akun/booking/{$b->id}"], $b->tenant_id, true);
            }

            return $p;
        });
    }

    /** Pembayaran langsung oleh staf (tunai/transfer yang dicek di mutasi) atau pengalihan dana: langsung terverifikasi. */
    public function recordDirect(Invoice $invoice, array $d, User $actor): array
    {
        return DB::transaction(function () use ($invoice, $d, $actor) {
            $booking = Booking::withoutTenantScope()->whereKey($invoice->booking_id)->lockForUpdate()->firstOrFail();
            $this->assertPayable($booking, $invoice);
            $p = Payment::create([
                'tenant_id' => $booking->tenant_id, 'invoice_id' => $invoice->id, 'method' => $d['method'],
                'claimed_amount' => $d['amount'], 'paid_on' => $d['paid_on'], 'sender_name' => $d['sender_name'] ?? null,
                'sender_bank' => $d['sender_bank'] ?? null, 'proof_file_id' => $d['proof_file_id'] ?? null, 'status' => 'pending', 'created_by' => $actor->id,
            ]);

            return $this->apply($booking, $p, (int) $d['amount'], $actor);
        });
    }

    private function assertPayable(Booking $booking, Invoice $invoice): void
    {
        if (! in_array($booking->status, self::OPEN_BOOKING, true)) {
            throw ApiException::conflict('booking_not_active', match ($booking->status) {
                'fully_paid' => 'Booking ini sudah lunas.',
                'expired' => 'Booking ini sudah kedaluwarsa. Hubungi PPIU.',
                'cancelled' => 'Booking ini sudah dibatalkan.',
                default => 'Booking ini tidak menerima pembayaran.',
            });
        }
        if (! in_array($invoice->status, ['unpaid', 'partial'], true)) {
            throw ApiException::conflict('invoice_closed', 'Tagihan ini sudah lunas atau dibatalkan.');
        }
    }

    /**
     * Terapkan nominal terverifikasi ke invoice terbuka berurutan; sisa dipecah ke invoice berikutnya.
     *
     * @return Payment[]
     */
    private function apply(Booking $booking, Payment $p, int $amount, User $actor): array
    {
        $remainingTotal = $booking->total_amount - $booking->paid_amount;
        if ($amount > $remainingTotal) {
            throw new ApiException(422, 'overpayment', 'Nominal melebihi sisa tagihan booking ('.number_format($remainingTotal, 0, ',', '.').'). Kelebihan dikembalikan di luar sistem lalu verifikasi sesuai sisa tagihan.', ['remaining' => $remainingTotal]);
        }
        $open = Invoice::withoutTenantScope()->where('booking_id', $booking->id)->whereIn('status', ['unpaid', 'partial'])
            ->orderByRaw("CASE type WHEN 'dp' THEN 0 WHEN 'full' THEN 0 ELSE 1 END")->orderBy('created_at')->lockForUpdate()->get();
        // Mulai dari invoice pembayaran ini bila masih terbuka.
        $open = $open->sortBy(fn ($i) => $i->id === $p->invoice_id ? -1 : 0)->values();

        $left = $amount;
        $done = [];
        $tenant = Tenant::findOrFail($booking->tenant_id);
        foreach ($open as $idx => $inv) {
            if ($left <= 0) {
                break;
            }
            $portion = min($left, $inv->amount - $inv->paid_amount);
            if ($portion <= 0) {
                continue;
            }
            if ($done === []) {
                $p->update(['invoice_id' => $inv->id, 'verified_amount' => $portion, 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
                $cur = $p;
            } else {
                $cur = Payment::create([
                    'tenant_id' => $booking->tenant_id, 'invoice_id' => $inv->id, 'method' => $p->method, 'claimed_amount' => $portion,
                    'verified_amount' => $portion, 'paid_on' => $p->paid_on, 'sender_bank' => $p->sender_bank, 'sender_name' => $p->sender_name,
                    'proof_file_id' => $p->proof_file_id, 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now(),
                    'split_from_payment_id' => $p->id, 'created_by' => $actor->id,
                ]);
            }
            $inv->paid_amount += $portion;
            $inv->status = $inv->paid_amount >= $inv->amount ? 'paid' : 'partial';
            $inv->save();
            Receipt::create(['tenant_id' => $booking->tenant_id, 'payment_id' => $cur->id, 'number' => DocumentNumber::receiptNumber($tenant->id, $tenant->code), 'amount' => $portion]);
            $left -= $portion;
            $done[] = $cur;
        }

        $old = $booking->status;
        $booking->paid_amount += $amount;
        if ($booking->status === 'awaiting_dp' && $booking->paid_amount >= $booking->dp_amount) {
            $this->confirmSeats($booking);
            $booking->status = 'dp_verified';
            $booking->dp_verified_at = now();
            $booking->hold_expires_at = null;
        }
        if ($booking->paid_amount >= $booking->total_amount) {
            $booking->status = 'fully_paid';
            $booking->fully_paid_at = now();
        }
        $booking->save();
        $this->commissions->onPaymentProgress($booking);
        $booker = \App\Models\User::find($booking->booker_user_id);
        if ($booker && $booker->status !== 'unclaimed') {
            $this->notify->send($booker, 'payment.verified', ['body' => 'Pembayaran Rp'.number_format($amount, 0, ',', '.')." untuk booking {$booking->code} terverifikasi. Kwitansi bisa diunduh di halaman booking.", 'url' => "/akun/booking/{$booking->id}"], $booking->tenant_id, true);
        }

        Audit::log('payment.verified', $p, ['booking_status' => $old], [
            'verified_amount' => $amount, 'split_count' => count($done) - 1, 'booking_status' => $booking->status, 'method' => $p->method,
        ], tenantId: $booking->tenant_id, context: 'tenant');

        return $done;
    }

    /** CR-03: DP terverifikasi → kursi pindah dari ditahan ke terkonfirmasi. */
    private function confirmSeats(Booking $booking): void
    {
        $dep = Departure::withoutTenantScope()->whereKey($booking->departure_id)->lockForUpdate()->firstOrFail();
        $dep->seats_held = max(0, $dep->seats_held - $booking->participant_count);
        $dep->seats_confirmed += $booking->participant_count;
        $dep->save();
    }
}
