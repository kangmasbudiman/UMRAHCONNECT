<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CommissionScheme;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\Tenant;
use App\Support\AgentPresenter as AP;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Skema komisi (FR-PPIU-11, BR-06, CR-02). Skema tidak pernah diubah: skema baru menutup skema aktif
 * pada cakupan yang sama (effective_to), sehingga komisi lama tetap memakai skema saat diperoleh.
 */
class CommissionSchemeController extends Controller
{
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate([
            'filter.scope' => ['nullable', Rule::in(['default', 'package', 'agent', 'agent_package'])],
            'filter.package_id' => ['nullable', 'uuid'], 'filter.agent_id' => ['nullable', 'uuid'], 'filter.active' => ['nullable', 'in:true,false,1,0'],
        ]);
        $active = $request->input('filter.active');
        $rows = CommissionScheme::where('tenant_id', $tenant->id)
            ->when($request->input('filter.scope'), fn ($q, $s) => $q->where('scope', $s))
            ->when($request->input('filter.package_id'), fn ($q, $s) => $q->where('package_id', $s))
            ->when($request->input('filter.agent_id'), fn ($q, $s) => $q->where('agent_id', $s))
            ->when($active !== null, fn ($q) => in_array($active, ['true', '1'], true) ? $q->whereNull('effective_to') : $q->whereNotNull('effective_to'))
            ->orderByRaw('effective_to IS NOT NULL')->orderByRaw("array_position(ARRAY['default','package','agent','agent_package']::varchar[], scope)")
            ->orderByDesc('effective_from')->get();
        $packages = Package::where('tenant_id', $tenant->id)->whereIn('id', $rows->pluck('package_id')->filter())->pluck('name', 'id');
        $agents = \App\Models\Agent::with('user')->whereIn('id', $rows->pluck('agent_id')->filter())->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn ($s) => AP::scheme($s) + [
            'package_name' => $packages[$s->package_id] ?? null,
            'agent' => isset($agents[$s->agent_id]) ? AP::agentRef($agents[$s->agent_id]) : null,
        ])->values()]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['default', 'package', 'agent', 'agent_package'])],
            'package_id' => ['nullable', 'required_if:scope,package,agent_package', 'uuid'],
            'agent_id' => ['nullable', 'required_if:scope,agent,agent_package', 'uuid'],
            'type' => ['required', 'in:flat,percent'],
            'flat_amount' => ['nullable', 'required_if:type,flat', 'integer', 'min:0', 'max:100000000'],
            'percent_bp' => ['nullable', 'required_if:type,percent', 'integer', 'min:0', 'max:10000'],
            'earn_on' => ['nullable', 'in:fully_paid,dp_verified'],
        ], [
            'flat_amount.required_if' => 'Isi nominal komisi per jamaah.',
            'percent_bp.required_if' => 'Isi persentase komisi.',
            'percent_bp.max' => 'Persentase maksimal 100%.',
        ]);
        $needsPkg = in_array($data['scope'], ['package', 'agent_package'], true);
        $needsAgent = in_array($data['scope'], ['agent', 'agent_package'], true);
        if ($needsPkg && ! Package::where('tenant_id', $tenant->id)->whereKey($data['package_id'])->exists()) {
            throw ApiException::unprocessable('package_not_found', 'Paket tidak ditemukan di PPIU ini.');
        }
        if ($needsAgent && ! Partnership::where('tenant_id', $tenant->id)->where('agent_id', $data['agent_id'])->whereIn('status', ['active', 'suspended', 'invited'])->exists()) {
            throw ApiException::unprocessable('agent_not_partner', 'Agen ini bukan mitra PPIU Anda.');
        }
        $scheme = self::replaceActive($tenant, [
            'scope' => $data['scope'],
            'package_id' => $needsPkg ? $data['package_id'] : null,
            'agent_id' => $needsAgent ? $data['agent_id'] : null,
            'type' => $data['type'],
            'flat_amount' => $data['type'] === 'flat' ? $data['flat_amount'] : null,
            'percent_bp' => $data['type'] === 'percent' ? $data['percent_bp'] : null,
            'earn_on' => $data['earn_on'] ?? 'fully_paid',
        ]);

        return response()->json(['data' => AP::scheme($scheme)], 201);
    }

    public function end(Tenant $tenant, string $scheme): JsonResponse
    {
        $s = CommissionScheme::where('tenant_id', $tenant->id)->whereKey($scheme)->firstOrFail();
        if ($s->effective_to) {
            throw ApiException::conflict('scheme_ended', 'Skema ini sudah berakhir.');
        }
        if ($s->scope === 'default') {
            throw ApiException::conflict('default_scheme_required', 'Skema default tidak bisa diakhiri tanpa pengganti. Buat skema default baru untuk menggantinya.');
        }
        $s->update(['effective_to' => now()]);
        Audit::log('commission_scheme.ended', $s, null, ['effective_to' => $s->effective_to->toIso8601String()], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => AP::scheme($s->fresh())]);
    }

    /** Tutup skema aktif pada cakupan yang sama lalu buat yang baru, dalam satu transaksi. */
    public static function replaceActive(Tenant $tenant, array $attrs): CommissionScheme
    {
        return DB::transaction(function () use ($tenant, $attrs) {
            $now = now();
            CommissionScheme::withoutTenantScope()->where('tenant_id', $tenant->id)->where('scope', $attrs['scope'])
                ->where('package_id', $attrs['package_id'])->where('agent_id', $attrs['agent_id'])->whereNull('effective_to')
                ->lockForUpdate()->update(['effective_to' => $now, 'updated_at' => $now]);
            $s = CommissionScheme::create($attrs + ['tenant_id' => $tenant->id, 'effective_from' => $now]);
            Audit::log('commission_scheme.created', $s, null, $attrs, tenantId: $tenant->id, context: 'tenant');

            return $s;
        });
    }
}
