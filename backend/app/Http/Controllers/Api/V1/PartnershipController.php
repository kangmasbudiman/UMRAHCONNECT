<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\CommissionScheme;
use App\Models\Consent;
use App\Models\Partnership;
use App\Models\ReferralLink;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AgentPresenter as AP;
use App\Support\Audit;
use App\Support\Commission;
use App\Support\OffsetCursor;
use App\Support\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Kemitraan agen–PPIU (BP-03, ST-PART, FR-AGT-03/04/14, FR-PPIU-08..10).
 * Status: applied/invited → active | rejected; active ⇄ suspended; active/suspended → ended.
 */
class PartnershipController extends Controller
{
    private const STATUSES = ['invited', 'applied', 'active', 'rejected', 'suspended', 'ended'];

    // ---------------- Sisi agen ----------------

    public function agentIndex(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $request->validate(['filter.status' => ['nullable', Rule::in(self::STATUSES)]]);
        $rows = Partnership::withoutTenantScope()->with(['tenant', 'agent.user'])->where('agent_id', $agent->id)
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'invited' THEN 0 WHEN 'active' THEN 1 WHEN 'applied' THEN 2 ELSE 3 END")->orderByDesc('updated_at')->get();

        return response()->json(['data' => $rows->map(fn ($p) => AP::partnership($p))->values()]);
    }

    /** Agen mengajukan kemitraan (FR-AGT-03). Pengajuan ulang setelah ditolak/berakhir memakai baris yang sama. */
    public function apply(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'message' => ['nullable', 'string', 'max:1000'],
            'accepted_terms_version' => ['required', Rule::in([config('app.legal.partnership_terms_version')])],
        ], ['accepted_terms_version.in' => 'Ketentuan kemitraan sudah diperbarui. Muat ulang halaman lalu setujui versi terbaru.']);
        $tenant = Tenant::verified()->find($data['tenant_id']) ?? throw ApiException::unprocessable('tenant_not_available', 'PPIU tidak ditemukan atau belum terverifikasi.');

        $p = Partnership::withoutTenantScope()->where('tenant_id', $tenant->id)->where('agent_id', $agent->id)->first();
        if ($p && in_array($p->status, ['applied', 'invited', 'active', 'suspended'], true)) {
            throw ApiException::conflict('partnership_exists', match ($p->status) {
                'applied' => 'Pengajuan Anda ke PPIU ini masih menunggu keputusan.',
                'invited' => 'PPIU ini sudah mengundang Anda. Terima undangannya di daftar kemitraan.',
                'active' => 'Anda sudah bermitra dengan PPIU ini.',
                'suspended' => 'Kemitraan dengan PPIU ini sedang ditangguhkan.',
            });
        }
        $p = DB::transaction(function () use ($p, $tenant, $agent, $data, $request) {
            $consent = $this->consent($request, $tenant->id, $data['accepted_terms_version']);
            $attrs = ['status' => 'applied', 'source' => 'applied', 'message' => $data['message'] ?? null, 'agreement_consent_id' => $consent->id,
                'reject_reason' => null, 'status_note' => null, 'decided_by' => null, 'decided_at' => null];

            return $p ? tap($p)->update($attrs) : Partnership::create($attrs + ['tenant_id' => $tenant->id, 'agent_id' => $agent->id]);
        });
        Audit::log('partnership.applied', $p, null, ['status' => 'applied'], tenantId: $tenant->id, context: 'agent');
        app(\App\Services\NotificationService::class)->toTenant($tenant->id, ['owner', 'admin'], 'partnership.applied', ['body' => "{$request->user()->name} ({$agent->code}) mengajukan kemitraan agen.", 'url' => "/dasbor/ppiu/{$tenant->id}/agen?status=applied"], true);

        return response()->json(['data' => AP::partnership($p->fresh())], 201);
    }

    public function accept(Request $request, string $partnership): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $data = $request->validate(['accepted_terms_version' => ['required', Rule::in([config('app.legal.partnership_terms_version')])]]);
        $p = $this->agentFind($agent, $partnership, ['invited']);
        DB::transaction(function () use ($p, $request, $data) {
            $consent = $this->consent($request, $p->tenant_id, $data['accepted_terms_version']);
            $p->update(['status' => 'active', 'agreement_consent_id' => $consent->id, 'decided_at' => now()]);
        });
        Audit::log('partnership.accepted', $p, ['status' => 'invited'], ['status' => 'active'], tenantId: $p->tenant_id, context: 'agent');

        return response()->json(['data' => AP::partnership($p->fresh())]);
    }

    public function decline(Request $request, string $partnership): JsonResponse
    {
        $p = $this->agentFind($request->attributes->get('agent'), $partnership, ['invited']);
        $p->update(['status' => 'rejected', 'decided_at' => now(), 'reject_reason' => 'Undangan ditolak agen']);
        Audit::log('partnership.declined', $p, ['status' => 'invited'], ['status' => 'rejected'], tenantId: $p->tenant_id, context: 'agent');

        return response()->json(['data' => AP::partnership($p->fresh())]);
    }

    public function agentEnd(Request $request, string $partnership): JsonResponse
    {
        $p = $this->agentFind($request->attributes->get('agent'), $partnership, ['active', 'suspended', 'applied']);
        $this->endPartnership($p, 'Diakhiri oleh agen', 'agent');

        return response()->json(['data' => AP::partnership($p->fresh())]);
    }

    // ---------------- Sisi PPIU ----------------

    public function tenantIndex(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['filter.status' => ['nullable', Rule::in(self::STATUSES)]]);
        $q = Partnership::with(['tenant', 'agent.user'])->where('tenant_id', $tenant->id)
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'applied' THEN 0 WHEN 'invited' THEN 1 WHEN 'active' THEN 2 WHEN 'suspended' THEN 3 ELSE 4 END")
            ->orderByDesc('updated_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request);
        $counts = Partnership::where('tenant_id', $tenant->id)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $clicks = ReferralLink::where('tenant_id', $tenant->id)->selectRaw('agent_id, sum(click_count) c')->groupBy('agent_id')->pluck('c', 'agent_id');

        return response()->json([
            'data' => $rows->map(fn ($p) => AP::partnership($p, true) + ['referral_clicks' => (int) ($clicks[$p->agent_id] ?? 0)])->values(),
            'meta' => $meta + ['counts' => $counts],
        ]);
    }

    /** PPIU mengundang agen lewat HP/email; agen harus sudah terdaftar (FR-PPIU-09). */
    public function invite(Request $request, Tenant $tenant): JsonResponse
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => OtpService::normalizePhone($request->input('phone'))]);
        }
        $data = $request->validate([
            'phone' => ['nullable', 'required_without:email', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc'],
            'commission_scheme_id' => ['nullable', 'uuid'],
        ], ['phone.regex' => 'Nomor HP tidak valid. Contoh: 081234567890.']);
        $this->assertTenantVerified($tenant);
        $user = ! empty($data['phone']) ? User::where('phone', $data['phone'])->first() : User::where('email', Str::lower($data['email']))->first();
        $agent = $user?->agent;
        if (! $agent) {
            throw new ApiException(422, 'agent_not_registered', 'Belum ada agen terdaftar dengan kontak ini. Minta yang bersangkutan mendaftar sebagai agen di UMRAH CONNECT, lalu undang lagi.',
                errors: [! empty($data['phone']) ? 'phone' : 'email' => ['Belum terdaftar sebagai agen.']]);
        }
        $scheme = $this->schemeFor($tenant, $data['commission_scheme_id'] ?? null);

        $p = Partnership::where('tenant_id', $tenant->id)->where('agent_id', $agent->id)->first();
        if ($p && ! in_array($p->status, ['rejected', 'ended'], true)) {
            throw ApiException::conflict('partnership_exists', $p->status === 'applied'
                ? 'Agen ini sudah mengajukan kemitraan. Setujui pengajuannya.'
                : 'Agen ini sudah bermitra atau sudah diundang.');
        }
        $p = DB::transaction(function () use ($p, $tenant, $agent, $request, $scheme) {
            $attrs = ['status' => 'invited', 'source' => 'invited', 'invited_by' => $request->user()->id, 'invited_at' => now(),
                'message' => null, 'reject_reason' => null, 'status_note' => null, 'decided_by' => null, 'decided_at' => null, 'agreement_consent_id' => null];
            $p = $p ? tap($p)->update($attrs) : Partnership::create($attrs + ['tenant_id' => $tenant->id, 'agent_id' => $agent->id]);
            $this->applyAgentScheme($tenant, $agent->id, $scheme);
            $this->assertHasScheme($tenant, $agent->id);

            return $p;
        });
        if ($agent->user->email) {
            Mail::raw("{$tenant->brand_name} mengundang Anda menjadi agen mitra di UMRAH CONNECT. Masuk lalu buka menu Agen › Mitra PPIU untuk menerima undangan.",
                fn ($m) => $m->to($agent->user->email)->subject("Undangan kemitraan dari {$tenant->brand_name}"));
        }
        Audit::log('partnership.invited', $p, null, ['status' => 'invited'], tenantId: $tenant->id, context: 'tenant');
        app(\App\Services\NotificationService::class)->send($agent->user, 'partnership.invited', ['body' => "{$tenant->brand_name} mengundang Anda menjadi agen mitra.", 'url' => '/agen'], $tenant->id);

        return response()->json(['data' => AP::partnership($p->fresh(), true)], 201);
    }

    /** Setujui pengajuan; skema: pilih yang ada, buat baru khusus agen, atau default (FR-PPIU-08). */
    public function approve(Request $request, Tenant $tenant, string $partnership): JsonResponse
    {
        $p = $this->tenantFind($tenant, $partnership, ['applied']);
        $this->assertTenantVerified($tenant);
        $data = $request->validate([
            'commission_scheme_id' => ['nullable', 'uuid'],
            'new_scheme' => ['nullable', 'array'],
            'new_scheme.type' => ['required_with:new_scheme', 'in:flat,percent'],
            'new_scheme.flat_amount' => ['nullable', 'required_if:new_scheme.type,flat', 'integer', 'min:0', 'max:100000000'],
            'new_scheme.percent_bp' => ['nullable', 'required_if:new_scheme.type,percent', 'integer', 'min:0', 'max:10000'],
            'new_scheme.earn_on' => ['nullable', 'in:fully_paid,dp_verified'],
        ]);
        DB::transaction(function () use ($p, $tenant, $data, $request) {
            if (! empty($data['new_scheme'])) {
                CommissionSchemeController::replaceActive($tenant, [
                    'scope' => 'agent', 'agent_id' => $p->agent_id, 'package_id' => null, 'type' => $data['new_scheme']['type'],
                    'flat_amount' => $data['new_scheme']['type'] === 'flat' ? $data['new_scheme']['flat_amount'] : null,
                    'percent_bp' => $data['new_scheme']['type'] === 'percent' ? $data['new_scheme']['percent_bp'] : null,
                    'earn_on' => $data['new_scheme']['earn_on'] ?? 'fully_paid',
                ]);
            } else {
                $this->applyAgentScheme($tenant, $p->agent_id, $this->schemeFor($tenant, $data['commission_scheme_id'] ?? null));
            }
            $this->assertHasScheme($tenant, $p->agent_id);
            $p->update(['status' => 'active', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        });
        Audit::log('partnership.approved', $p, ['status' => 'applied'], ['status' => 'active'], tenantId: $tenant->id, context: 'tenant');
        app(\App\Services\NotificationService::class)->send($p->agent->user, 'partnership.approved', ['body' => "Kemitraan dengan {$tenant->brand_name} disetujui. Anda sudah bisa membuat link paket.", 'url' => '/agen/paket'], $tenant->id, true);

        return response()->json(['data' => AP::partnership($p->fresh(), true)]);
    }

    public function reject(Request $request, Tenant $tenant, string $partnership): JsonResponse
    {
        $p = $this->tenantFind($tenant, $partnership, ['applied']);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $p->update(['status' => 'rejected', 'reject_reason' => $data['reason'], 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        Audit::log('partnership.rejected', $p, ['status' => 'applied'], ['status' => 'rejected'], $data['reason'], $tenant->id, 'tenant');
        app(\App\Services\NotificationService::class)->send($p->agent->user, 'partnership.rejected', ['body' => "Pengajuan kemitraan ke {$tenant->brand_name} ditolak: {$data['reason']}", 'url' => '/agen/mitra'], $tenant->id, true);

        return response()->json(['data' => AP::partnership($p->fresh(), true)]);
    }

    /** Tangguhkan: link referral berhenti mengatribusi selama ditangguhkan (FR-PPIU-10). */
    public function suspend(Request $request, Tenant $tenant, string $partnership): JsonResponse
    {
        $p = $this->tenantFind($tenant, $partnership, ['active']);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $p->update(['status' => 'suspended', 'status_note' => $data['reason']]);
        Audit::log('partnership.suspended', $p, ['status' => 'active'], ['status' => 'suspended'], $data['reason'], $tenant->id, 'tenant');

        return response()->json(['data' => AP::partnership($p->fresh(), true)]);
    }

    public function reactivate(Tenant $tenant, string $partnership): JsonResponse
    {
        $p = $this->tenantFind($tenant, $partnership, ['suspended']);
        $p->update(['status' => 'active', 'status_note' => null]);
        Audit::log('partnership.reactivated', $p, ['status' => 'suspended'], ['status' => 'active'], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => AP::partnership($p->fresh(), true)]);
    }

    public function tenantEnd(Request $request, Tenant $tenant, string $partnership): JsonResponse
    {
        $p = $this->tenantFind($tenant, $partnership, ['active', 'suspended', 'invited']);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->endPartnership($p, $data['reason'], 'tenant');

        return response()->json(['data' => AP::partnership($p->fresh(), true)]);
    }

    // ---------------- Bantuan ----------------

    /** Berakhir: link referral dinonaktifkan permanen; komisi yang sudah diperoleh tetap dibayar. */
    private function endPartnership(Partnership $p, string $reason, string $context): void
    {
        $from = $p->status;
        DB::transaction(function () use ($p, $reason) {
            $p->update(['status' => 'ended', 'status_note' => $reason]);
            ReferralLink::withoutTenantScope()->where('partnership_id', $p->id)->update(['is_active' => false, 'updated_at' => now()]);
        });
        Audit::log('partnership.ended', $p, ['status' => $from], ['status' => 'ended'], $reason, $p->tenant_id, $context);
    }

    private function consent(Request $request, string $tenantId, string $version): Consent
    {
        return Consent::create(['user_id' => $request->user()->id, 'document' => 'partnership_terms', 'version' => $version, 'tenant_id' => $tenantId, 'ip_address' => $request->ip()]);
    }

    private function schemeFor(Tenant $tenant, ?string $id): ?CommissionScheme
    {
        if (! $id) {
            return null;
        }

        return CommissionScheme::withoutTenantScope()->where('tenant_id', $tenant->id)->whereKey($id)->whereNull('effective_to')
            ->whereIn('scope', ['default', 'agent'])->first()
            ?? throw ApiException::unprocessable('scheme_invalid', 'Skema komisi tidak ditemukan atau sudah berakhir.');
    }

    /** Skema terpilih yang bukan default disalin sebagai skema khusus agen ini. */
    private function applyAgentScheme(Tenant $tenant, string $agentId, ?CommissionScheme $scheme): void
    {
        if (! $scheme || $scheme->scope === 'default' || $scheme->agent_id === $agentId) {
            return;
        }
        CommissionSchemeController::replaceActive($tenant, [
            'scope' => 'agent', 'agent_id' => $agentId, 'package_id' => null, 'type' => $scheme->type,
            'flat_amount' => $scheme->flat_amount, 'percent_bp' => $scheme->percent_bp, 'earn_on' => $scheme->earn_on,
        ]);
    }

    /** Agen tidak boleh aktif tanpa skema komisi yang jelas (BR-06). */
    private function assertHasScheme(Tenant $tenant, string $agentId): void
    {
        if (! Commission::effective($tenant->id, $agentId)) {
            throw ApiException::unprocessable('scheme_required', 'Tetapkan skema komisi dulu: buat skema default PPIU, atau isi skema khusus untuk agen ini.');
        }
    }

    private function assertTenantVerified(Tenant $tenant): void
    {
        if ($tenant->verification_status !== 'verified') {
            throw new ApiException(403, 'tenant_not_verified', 'Kemitraan agen dibuka setelah PPIU terverifikasi.');
        }
    }

    private function agentFind(Agent $agent, string $id, array $allowed): Partnership
    {
        $p = Partnership::withoutTenantScope()->where('agent_id', $agent->id)->whereKey($id)->firstOrFail();
        if (! in_array($p->status, $allowed, true)) {
            throw ApiException::conflict('invalid_status_transition', 'Status kemitraan tidak memungkinkan tindakan ini.');
        }

        return $p;
    }

    private function tenantFind(Tenant $tenant, string $id, array $allowed): Partnership
    {
        $p = Partnership::where('tenant_id', $tenant->id)->whereKey($id)->firstOrFail();
        if (! in_array($p->status, $allowed, true)) {
            throw ApiException::conflict('invalid_status_transition', 'Status kemitraan tidak memungkinkan tindakan ini.');
        }

        return $p;
    }
}
