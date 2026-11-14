<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Services\PaymentService;
use App\Support\BookingPresenter as BP;
use App\Support\OffsetCursor;
use App\Support\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pembayaran, antrean verifikasi, invoice & kwitansi PDF (BP-06, FR-JMH-08/09, FR-FIN-01..06). */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $service) {}

    // ---------- Jamaah & agen: unggah bukti ----------

    public function myUpload(Request $request, string $invoice): JsonResponse
    {
        $inv = Invoice::withoutTenantScope()->whereKey($invoice)
            ->whereIn('booking_id', Booking::withoutTenantScope()->where('booker_user_id', $request->user()->id)->select('id'))->firstOrFail();

        return $this->upload($request, $inv);
    }

    public function agentUpload(Request $request, string $invoice): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $inv = Invoice::withoutTenantScope()->whereKey($invoice)
            ->whereIn('booking_id', Booking::withoutTenantScope()->where('agent_id', $agent->id)->select('id'))->firstOrFail();

        return $this->upload($request, $inv);
    }

    private function upload(Request $request, Invoice $inv): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2026-01-01'],
            'sender_bank' => ['nullable', 'string', 'max:50'],
            'sender_name' => ['nullable', 'string', 'max:150'],
            'proof_file_id' => ['required', 'uuid'],
        ], ['paid_on.before_or_equal' => 'Tanggal transfer tidak boleh di masa depan.', 'proof_file_id.required' => 'Unggah foto atau PDF bukti transfer.']);
        $file = FileController::claimPersonal($request, $data['proof_file_id'], 'payment_proof');
        $file->forceFill(['tenant_id' => $inv->tenant_id])->save();
        $p = $this->service->submitProof($inv, $data, $request->user());

        return response()->json(['data' => BP::payment($p->fresh(), true)], 201);
    }

    public function myInvoicePdf(Request $request, string $invoice)
    {
        $inv = Invoice::withoutTenantScope()->whereKey($invoice)
            ->whereIn('booking_id', Booking::withoutTenantScope()->where('booker_user_id', $request->user()->id)->select('id'))->firstOrFail();

        return Pdf::invoice($inv);
    }

    public function myReceiptPdf(Request $request, string $receipt)
    {
        $r = Receipt::withoutTenantScope()->whereKey($receipt)->firstOrFail();
        $bookingId = Invoice::withoutTenantScope()->whereKey($r->payment->invoice_id)->value('booking_id');
        if (! Booking::withoutTenantScope()->whereKey($bookingId)->where('booker_user_id', $request->user()->id)->exists()) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
        }

        return Pdf::receipt($r);
    }

    // ---------- PPIU ----------

    public function tenantIndex(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['filter.status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])]]);
        $q = Payment::where('tenant_id', $tenant->id)->whereNull('split_from_payment_id')
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['invoice', 'receipt', 'proof'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderBy('created_at')->orderBy('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 50, 100);
        $bookings = Booking::withoutTenantScope()->with('booker')->whereIn('id', $rows->pluck('invoice.booking_id'))->get()->keyBy('id');
        $counts = Payment::where('tenant_id', $tenant->id)->whereNull('split_from_payment_id')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => $rows->map(function ($p) use ($bookings) {
            $b = $bookings[$p->invoice->booking_id];

            return BP::payment($p, true) + ['booking' => [
                'id' => $b->id, 'code' => $b->code, 'status' => $b->status, 'booker' => $b->booker->name,
                'total_amount' => $b->total_amount, 'paid_amount' => $b->paid_amount, 'hold_expires_at' => $b->hold_expires_at?->toIso8601String(),
            ], 'invoice_amount' => $p->invoice->amount, 'invoice_paid_amount' => $p->invoice->paid_amount, 'invoice_type' => $p->invoice->type];
        })->values(), 'meta' => $meta + ['counts' => $counts]]);
    }

    public function verify(Request $request, Tenant $tenant, string $payment): JsonResponse
    {
        $p = Payment::where('tenant_id', $tenant->id)->whereKey($payment)->firstOrFail();
        $data = $request->validate(['verified_amount' => ['required', 'integer', 'min:1']]);
        $done = $this->service->verify($p, $data['verified_amount'], $request->user());

        return response()->json(['data' => [
            'payments' => collect($done)->map(fn ($x) => BP::payment($x->fresh(['invoice', 'receipt']), true))->values(),
            'booking' => $this->bookingState($p->fresh()->invoice->booking_id),
        ]]);
    }

    public function reject(Request $request, Tenant $tenant, string $payment): JsonResponse
    {
        $p = Payment::where('tenant_id', $tenant->id)->whereKey($payment)->firstOrFail();
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => BP::payment($this->service->reject($p, $data['reason'], $request->user()), true)]);
    }

    /** Catat pembayaran langsung (tunai di kantor / transfer yang sudah dicek di mutasi) — FR-FIN-06. */
    public function record(Request $request, Tenant $tenant, string $invoice): JsonResponse
    {
        $inv = Invoice::where('tenant_id', $tenant->id)->whereKey($invoice)->firstOrFail();
        $data = $request->validate([
            'method' => ['required', 'in:bank_transfer,cash'],
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sender_name' => ['nullable', 'string', 'max:150'],
            'proof_file_id' => ['nullable', 'uuid'],
        ]);
        if (! empty($data['proof_file_id'])) {
            FileController::claimPersonal($request, $data['proof_file_id'], 'payment_proof')->forceFill(['tenant_id' => $tenant->id])->save();
        }
        $done = $this->service->recordDirect($inv, $data, $request->user());

        return response()->json(['data' => [
            'payments' => collect($done)->map(fn ($x) => BP::payment($x->fresh(['invoice', 'receipt']), true))->values(),
            'booking' => $this->bookingState($inv->booking_id),
        ]], 201);
    }

    public function invoicePdf(Tenant $tenant, string $invoice)
    {
        return Pdf::invoice(Invoice::where('tenant_id', $tenant->id)->whereKey($invoice)->firstOrFail());
    }

    public function receiptPdf(Tenant $tenant, string $receipt)
    {
        return Pdf::receipt(Receipt::where('tenant_id', $tenant->id)->whereKey($receipt)->firstOrFail());
    }

    private function bookingState(string $bookingId): array
    {
        $b = Booking::withoutTenantScope()->findOrFail($bookingId);

        return ['id' => $b->id, 'code' => $b->code, 'status' => $b->status, 'paid_amount' => $b->paid_amount, 'total_amount' => $b->total_amount];
    }
}
