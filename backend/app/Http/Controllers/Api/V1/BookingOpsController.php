<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingNote;
use App\Models\BookingParticipant;
use App\Models\Pilgrim;
use App\Models\PilgrimDocument;
use App\Models\PilgrimDocumentReview;
use App\Models\Refund;
use App\Models\Tenant;
use App\Services\CancellationService;
use App\Support\Audit;
use App\Support\BookingPresenter as BP;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Operasional booking: pembatalan, refund, catatan internal, identitas & dokumen jamaah (BP-08, BP-09, FR-PPIU-21/22). */
class BookingOpsController extends Controller
{
    public function __construct(private CancellationService $cancel) {}

    public function myCancelRequest(Request $request, string $booking): JsonResponse
    {
        $b = Booking::withoutTenantScope()->where('booker_user_id', $request->user()->id)->whereKey($booking)->firstOrFail();
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return response()->json(['data' => BP::full($this->cancel->request($b, $data['reason'])->fresh()->load('tenant'))]);
    }

    public function cancel(Request $request, Tenant $tenant, string $booking): JsonResponse
    {
        $b = $this->find($tenant, $booking);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'refund_amount' => ['required', 'integer', 'min:0'],
            'refund_basis' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['refund_basis.required' => 'Tulis dasar perhitungan refund sesuai kebijakan pembatalan paket.']);

        return response()->json(['data' => BP::full($this->cancel->cancel($b, $data['reason'], $data['refund_amount'], $data['refund_basis'], $request->user())->fresh()->load('tenant'), true)]);
    }

    public function rejectCancelRequest(Request $request, Tenant $tenant, string $booking): JsonResponse
    {
        $b = $this->find($tenant, $booking);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->cancel->rejectRequest($b, $data['reason']);
        BookingNote::create(['tenant_id' => $tenant->id, 'booking_id' => $b->id, 'author_id' => $request->user()->id, 'body' => 'Pengajuan pembatalan ditolak: '.$data['reason']]);

        return response()->json(['data' => BP::full($b->fresh()->load('tenant'), true)]);
    }

    /** FR-PPIU-14 / BR-09: hanya Owner (booking.attribution.change), alasan wajib (AC-12, AZ-05). */
    public function changeAttribution(Request $request, Tenant $tenant, string $booking, \App\Services\AttributionService $svc): JsonResponse
    {
        $b = $this->find($tenant, $booking);
        $data = $request->validate([
            'agent_id' => ['present', 'nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['reason.required' => 'Tulis alasan perubahan atribusi.', 'reason.min' => 'Alasan minimal 5 karakter.']);

        return response()->json(['data' => BP::full($svc->change($b, $data['agent_id'], $data['reason'], $request->user())->load('tenant'), true)]);
    }

    public function refunds(Tenant $tenant, string $booking): JsonResponse
    {
        $b = $this->find($tenant, $booking);

        return response()->json(['data' => $b->refunds->map(fn ($r) => BP::refund($r))->values()]);
    }

    public function recordRefund(Request $request, Tenant $tenant, string $booking): JsonResponse
    {
        $b = $this->find($tenant, $booking);
        $data = $request->validate([
            'type' => ['required', 'in:bank_transfer,reallocation'],
            'amount' => ['required', 'integer', 'min:1'],
            'target_booking_id' => ['nullable', 'required_if:type,reallocation', 'uuid'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'proof_file_id' => ['nullable', 'uuid'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['target_booking_id.required_if' => 'Pilih booking tujuan pengalihan dana.']);
        if (! empty($data['proof_file_id'])) {
            FileController::claimPersonal($request, $data['proof_file_id'], 'refund_proof')->forceFill(['tenant_id' => $tenant->id])->save();
        }

        return response()->json(['data' => BP::refund($this->cancel->recordRefund($b, $data, $request->user()))], 201);
    }

    public function notes(Tenant $tenant, string $booking): JsonResponse
    {
        $b = $this->find($tenant, $booking);

        return response()->json(['data' => $b->notes()->with('author')->get()->map(fn ($n) => [
            'id' => $n->id, 'body' => $n->body, 'author' => $n->author->name, 'created_at' => $n->created_at->toIso8601String(),
        ])->values()]);
    }

    public function addNote(Request $request, Tenant $tenant, string $booking): JsonResponse
    {
        $b = $this->find($tenant, $booking);
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:3000']]);
        $n = BookingNote::create(['tenant_id' => $tenant->id, 'booking_id' => $b->id, 'author_id' => $request->user()->id, 'body' => trim($data['body'])]);

        return response()->json(['data' => ['id' => $n->id, 'body' => $n->body, 'author' => $request->user()->name, 'created_at' => $n->created_at->toIso8601String()]], 201);
    }

    /** NIK & paspor utuh, hanya untuk peserta booking aktif PPIU ini; setiap akses tercatat. */
    public function identity(Tenant $tenant, string $pilgrim): JsonResponse
    {
        $p = $this->tenantPilgrim($tenant, $pilgrim);
        Audit::log('pilgrim.identity_viewed', $p, null, null, null, $tenant->id, 'tenant');

        return response()->json(['data' => [
            'pilgrim_id' => $p->id, 'full_name' => $p->full_name, 'nik' => $p->nik_enc, 'passport_no' => $p->passport_no_enc,
            'passport_expires_on' => $p->passport_expires_on?->toDateString(), 'birth_place' => $p->birth_place, 'birth_date' => $p->birth_date?->toDateString(),
        ]]);
    }

    public function documentFile(Tenant $tenant, string $document): JsonResponse
    {
        $d = PilgrimDocument::with('file')->findOrFail($document);
        $this->tenantPilgrim($tenant, $d->pilgrim_id);
        Audit::log('document.viewed', $d, null, ['type' => $d->type], null, $tenant->id, 'tenant');

        return response()->json(['data' => ['url' => URL::temporarySignedRoute('files.content', now()->addMinutes(10), ['file' => $d->file_id], absolute: false), 'expires_in' => 600]]);
    }

    public function reviewDocument(Request $request, Tenant $tenant, string $document): JsonResponse
    {
        $d = PilgrimDocument::findOrFail($document);
        $this->tenantPilgrim($tenant, $d->pilgrim_id);
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected'], 'note' => ['nullable', 'required_if:status,rejected', 'string', 'max:500']],
            ['note.required_if' => 'Tulis alasan agar jamaah tahu apa yang perlu diperbaiki.']);
        $r = PilgrimDocumentReview::updateOrCreate(['tenant_id' => $tenant->id, 'pilgrim_document_id' => $d->id],
            ['status' => $data['status'], 'note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        Audit::log('pilgrim_document.reviewed', $d, null, ['status' => $data['status']], $data['note'] ?? null, $tenant->id, 'tenant');

        return response()->json(['data' => ['document_id' => $d->id, 'status' => $r->status, 'note' => $r->note, 'reviewed_at' => $r->reviewed_at->toIso8601String()]]);
    }

    private function find(Tenant $tenant, string $id): Booking
    {
        return Booking::with('tenant')->where('tenant_id', $tenant->id)->whereKey($id)->firstOrFail();
    }

    /** Jamaah harus peserta (aktif atau riwayat) booking PPIU ini; selain itu 404. */
    private function tenantPilgrim(Tenant $tenant, string $pilgrimId): Pilgrim
    {
        $ok = BookingParticipant::withoutTenantScope()->where('tenant_id', $tenant->id)->where('pilgrim_id', $pilgrimId)->exists();
        if (! $ok) {
            throw new NotFoundHttpException;
        }

        return Pilgrim::withTrashed()->findOrFail($pilgrimId);
    }
}
