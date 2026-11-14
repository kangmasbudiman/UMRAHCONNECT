<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\TenantMember;
use App\Support\Audit;
use App\Support\OffsetCursor;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Antrean dan keputusan verifikasi PPIU oleh platform (FR-ADM-02..05, BP-01, BP-11). */
class TenantVerificationController extends Controller
{
    private const STATUSES = ['draft', 'pending_review', 'needs_revision', 'verified', 'rejected', 'suspended'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'filter.verification_status' => ['nullable', Rule::in(self::STATUSES)],
            'filter.license_expiring_within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $q = Tenant::query()
            ->when($request->input('filter.verification_status'), fn ($q, $s) => $q->where('verification_status', $s))
            ->when($request->input('filter.license_expiring_within_days'), fn ($q, $d) => $q->whereDate('license_expires_on', '<=', now()->addDays((int) $d)))
            ->when($request->input('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('brand_name', 'ilike', "%{$s}%")->orWhere('legal_name', 'ilike', "%{$s}%")->orWhere('ppiu_license_no', 'ilike', "%{$s}%")))
            // Antrean: menunggu tinjauan paling lama di atas.
            ->orderByRaw("CASE verification_status WHEN 'pending_review' THEN 0 WHEN 'needs_revision' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
            ->orderBy('updated_at');
        [$rows, $page] = OffsetCursor::paginate($q, $request);

        $counts = Tenant::query()->selectRaw('verification_status, count(*) c')->groupBy('verification_status')->pluck('c', 'verification_status');

        return response()->json([
            'data' => collect($rows)->map(fn (Tenant $t) => Present::tenant($t) + ['owner' => $this->owner($t)])->values(),
            'meta' => $page + ['counts' => $counts],
        ]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $docs = TenantDocument::withoutTenantScope()->with('file')->where('tenant_id', $tenant->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $banks = BankAccount::withoutTenantScope()->where('tenant_id', $tenant->id)->orderBy('created_at')->get();
        $members = TenantMember::withoutTenantScope()->with('user')->where('tenant_id', $tenant->id)->get();
        $history = AuditLog::where('tenant_id', $tenant->id)
            ->whereIn('action', ['tenant.registered', 'tenant.verification_submitted', 'tenant.verification_decided', 'tenant.suspended', 'tenant.restored'])
            ->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => Present::tenant($tenant) + [
            'onboarding' => TenantController::checklist($tenant),
            'documents' => $docs->map(fn ($d) => Present::document($d) + ['file' => FileController::present($d->file)])->values(),
            'bank_accounts' => $banks->map(fn ($a) => Present::bankAccount($a))->values(),
            'members' => $members->map(fn ($m) => Present::member($m))->values(),
            'history' => $history->map(fn ($h) => [
                'action' => $h->action, 'new_values' => $h->new_values, 'reason' => $h->reason,
                'actor_context' => $h->actor_context, 'created_at' => $h->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function verify(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'needs_revision', 'rejected'])],
            'note' => ['nullable', 'required_unless:decision,verified', 'string', 'max:2000'],
            'document_notes' => ['nullable', 'array'],
            'document_notes.*.document_id' => ['required', 'uuid'],
            'document_notes.*.status' => ['required', Rule::in(['accepted', 'rejected'])],
            'document_notes.*.note' => ['nullable', 'string', 'max:1000'],
        ], ['note.required_unless' => 'Tulis alasan agar PPIU tahu apa yang perlu diperbaiki.']);
        if ($tenant->verification_status !== 'pending_review') {
            throw ApiException::conflict('invalid_status_transition', 'Hanya PPIU berstatus Menunggu Tinjauan yang bisa diputuskan.');
        }

        DB::transaction(function () use ($tenant, $data, $request) {
            $reviewer = $request->user()->id;
            $latest = TenantDocument::withoutTenantScope()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->orderByDesc('id')->get()->unique('type');
            $notes = collect($data['document_notes'] ?? [])->keyBy('document_id');
            foreach ($notes as $id => $n) {
                if (! $latest->firstWhere('id', $id)) {
                    throw ApiException::unprocessable('document_not_current', 'Dokumen yang dinilai bukan dokumen terbaru PPIU ini.');
                }
            }
            foreach ($latest as $doc) {
                $n = $notes->get($doc->id);
                if ($n) {
                    $doc->update(['status' => $n['status'], 'review_note' => $n['note'] ?? null, 'reviewed_by' => $reviewer, 'reviewed_at' => now()]);
                } elseif ($data['decision'] === 'verified' && $doc->status === 'submitted') {
                    // Memverifikasi PPIU berarti dokumen yang belum dinilai ikut diterima.
                    $doc->update(['status' => 'accepted', 'review_note' => null, 'reviewed_by' => $reviewer, 'reviewed_at' => now()]);
                }
            }

            if ($data['decision'] === 'verified') {
                $bad = $latest->filter(fn ($d) => in_array($d->type, TenantDocument::REQUIRED_TYPES, true) && $d->fresh()->status !== 'accepted');
                $missing = array_diff(TenantDocument::REQUIRED_TYPES, $latest->pluck('type')->all());
                if ($bad->isNotEmpty() || $missing) {
                    throw ApiException::unprocessable('documents_not_accepted', 'Semua dokumen wajib harus diterima sebelum PPIU diverifikasi.');
                }
                if (! BankAccount::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', 'verified')->exists()) {
                    throw ApiException::unprocessable('bank_account_unverified', 'Verifikasi minimal satu rekening PPIU sebelum memverifikasi PPIU.');
                }
            }

            $old = $tenant->verification_status;
            $tenant->update([
                'verification_status' => $data['decision'],
                'status_note' => $data['note'] ?? null,
                'verified_at' => $data['decision'] === 'verified' ? now() : $tenant->verified_at,
                'verified_by' => $data['decision'] === 'verified' ? $reviewer : $tenant->verified_by,
            ]);
            Audit::log('tenant.verification_decided', $tenant, ['verification_status' => $old], ['verification_status' => $data['decision']], $data['note'] ?? null, $tenant->id, 'platform');
        });

        $label = ['verified' => 'terverifikasi dan sudah tampil di marketplace', 'needs_revision' => 'perlu revisi', 'rejected' => 'ditolak'][$data['decision']];
        app(\App\Services\NotificationService::class)->toTenant($tenant->id, ['owner'], 'tenant.verification_decided', ['body' => "PPIU {$tenant->brand_name} {$label}.".(! empty($data['note']) ? " Catatan: {$data['note']}" : ''), 'url' => "/dasbor/ppiu/{$tenant->id}"], true);

        return $this->show($tenant->fresh());
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->transition($request, $tenant, 'verified', 'suspended', 'tenant.suspended');
    }

    public function restore(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->transition($request, $tenant, 'suspended', 'verified', 'tenant.restored');
    }

    public function verifyBankAccount(Request $request, string $account): JsonResponse
    {
        $acc = BankAccount::withoutTenantScope()->whereKey($account)->firstOrFail();
        if ($acc->status !== 'pending') {
            throw ApiException::conflict('invalid_status_transition', 'Rekening ini tidak sedang menunggu verifikasi.');
        }
        DB::transaction(function () use ($acc, $request) {
            $hasDefault = BankAccount::withoutTenantScope()->where('tenant_id', $acc->tenant_id)->where('is_default', true)->exists();
            $acc->update(['status' => 'verified', 'verified_by' => $request->user()->id, 'verified_at' => now(), 'is_default' => ! $hasDefault]);
            Audit::log('bank_account.verified', $acc, ['status' => 'pending'], ['status' => 'verified'], tenantId: $acc->tenant_id, context: 'platform');
        });

        return response()->json(['data' => Present::bankAccount($acc->fresh())]);
    }

    private function transition(Request $request, Tenant $tenant, string $from, string $to, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        if ($tenant->verification_status !== $from) {
            throw ApiException::conflict('invalid_status_transition', "Hanya PPIU berstatus {$from} yang bisa diubah menjadi {$to}.");
        }
        $tenant->update(['verification_status' => $to, 'status_note' => $data['reason']]);
        Audit::log($action, $tenant, ['verification_status' => $from], ['verification_status' => $to], $data['reason'], $tenant->id, 'platform');

        return $this->show($tenant->fresh());
    }

    private function owner(Tenant $t): ?array
    {
        $m = TenantMember::withoutTenantScope()->with('user')->where('tenant_id', $t->id)->where('role', 'owner')->where('status', 'active')->first();

        return $m ? ['name' => $m->user->name, 'email' => $m->user->email, 'phone' => $m->user->phone] : null;
    }
}
