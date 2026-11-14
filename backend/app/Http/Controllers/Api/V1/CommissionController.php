<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\Tenant;
use App\Services\CommissionService;
use App\Support\AgentPresenter;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Komisi & pencairan (BP-07, FR-AGT-10/11, FR-FIN-07..09). */
class CommissionController extends Controller
{
    private const STATUSES = ['estimated', 'earned', 'approved', 'paid', 'cancelled'];

    public function __construct(private CommissionService $service) {}

    public static function present(Commission $c, bool $withAgent = true): array
    {
        $c->loadMissing(['booking.departure', 'booking.package', 'agent.user', 'tenant']);

        return [
            'id' => $c->id,
            'booking' => ['id' => $c->booking->id, 'code' => $c->booking->code, 'status' => $c->booking->status, 'participant_count' => $c->booking->participant_count,
                'total_amount' => $c->booking->total_amount, 'package_name' => $c->booking->package->name, 'departure_date' => $c->booking->departure->departure_date->toDateString()],
            'tenant' => ['id' => $c->tenant->id, 'brand_name' => $c->tenant->brand_name],
            'amount' => $c->amount, 'status' => $c->status,
            'earned_at' => $c->earned_at?->toIso8601String(), 'approved_at' => $c->approved_at?->toIso8601String(), 'paid_at' => $c->paid_at?->toIso8601String(),
            'payout_id' => $c->payout_id,
        ] + ($withAgent ? ['agent' => AgentPresenter::agentRef($c->agent)] : []);
    }

    public static function presentPayout(CommissionPayout $p): array
    {
        $p->loadMissing(['agent.user', 'tenant', 'commissions']);

        return [
            'id' => $p->id, 'tenant' => ['id' => $p->tenant->id, 'brand_name' => $p->tenant->brand_name],
            'agent' => AgentPresenter::agentRef($p->agent) + ['payout_bank_name' => $p->agent->payout_bank_name, 'payout_account_no_masked' => AgentPresenter::mask($p->agent->payout_account_no_enc, 0, 4), 'payout_account_holder' => $p->agent->payout_account_holder],
            'total_amount' => $p->total_amount, 'status' => $p->status, 'requested_by' => $p->requested_by,
            'requested_at' => $p->requested_at?->toIso8601String(), 'paid_at' => $p->paid_at?->toIso8601String(),
            'proof_file_id' => $p->proof_file_id, 'reject_reason' => $p->reject_reason,
            'commission_ids' => $p->commissions->pluck('id')->values(), 'commission_count' => $p->commissions->count(),
        ];
    }

    private function totals($q): array
    {
        return (clone $q)->reorder()->selectRaw('status, count(*) c, sum(amount) s')->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['count' => (int) $r->c, 'amount' => (int) $r->s]])->all();
    }

    // ---------- Agen ----------

    public function agentIndex(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $request->validate(['filter.status' => ['nullable', Rule::in(self::STATUSES)], 'filter.tenant_id' => ['nullable', 'uuid']]);
        $base = Commission::withoutTenantScope()->where('agent_id', $agent->id)
            ->when($request->input('filter.tenant_id'), fn ($q, $t) => $q->where('tenant_id', $t));
        $q = (clone $base)->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 50, 100);

        return response()->json(['data' => $rows->map(fn ($c) => self::present($c, false))->values(), 'meta' => $meta + ['totals' => $this->totals($base)]]);
    }

    public function agentPayouts(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $rows = CommissionPayout::withoutTenantScope()->where('agent_id', $agent->id)->orderByDesc('requested_at')->get();

        return response()->json(['data' => $rows->map(fn ($p) => self::presentPayout($p))->values()]);
    }

    public function agentRequestPayout(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $data = $request->validate(['tenant_id' => ['required', 'uuid'], 'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid']]);
        $p = $this->service->createPayout($data['tenant_id'], $agent->id, $data['ids'], $request->user());

        return response()->json(['data' => self::presentPayout($p)], 201);
    }

    // ---------- PPIU ----------

    public function tenantIndex(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['filter.status' => ['nullable', Rule::in(self::STATUSES)], 'filter.agent_id' => ['nullable', 'uuid'], 'filter.departure_id' => ['nullable', 'uuid']]);
        $base = Commission::where('tenant_id', $tenant->id)
            ->when($request->input('filter.agent_id'), fn ($q, $a) => $q->where('agent_id', $a))
            ->when($request->input('filter.departure_id'), fn ($q, $d) => $q->whereIn('booking_id', \App\Models\Booking::withoutTenantScope()->where('departure_id', $d)->select('id')));
        $q = (clone $base)->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 50, 100);

        return response()->json(['data' => $rows->map(fn ($c) => self::present($c))->values(), 'meta' => $meta + ['totals' => $this->totals($base)]]);
    }

    public function approve(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid']]);
        $rows = $this->service->approve($tenant->id, $data['ids'], $request->user());

        return response()->json(['data' => collect($rows)->map(fn ($c) => self::present($c->fresh()))->values()]);
    }

    public function payouts(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['filter.status' => ['nullable', 'in:requested,paid,rejected']]);
        $rows = CommissionPayout::where('tenant_id', $tenant->id)->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 ELSE 1 END")->orderByDesc('requested_at')->get();

        return response()->json(['data' => $rows->map(fn ($p) => self::presentPayout($p))->values()]);
    }

    public function createPayout(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'uuid'], 'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid']]);

        return response()->json(['data' => self::presentPayout($this->service->createPayout($tenant->id, $data['agent_id'], $data['ids'], $request->user()))], 201);
    }

    public function pay(Request $request, Tenant $tenant, string $payout): JsonResponse
    {
        $p = CommissionPayout::where('tenant_id', $tenant->id)->whereKey($payout)->firstOrFail();
        $data = $request->validate(['paid_at' => ['required', 'date', 'before_or_equal:now'], 'proof_file_id' => ['required', 'uuid']],
            ['proof_file_id.required' => 'Unggah bukti transfer komisi.']);
        FileController::claimPersonal($request, $data['proof_file_id'], 'payout_proof')->forceFill(['tenant_id' => $tenant->id])->save();

        return response()->json(['data' => self::presentPayout($this->service->pay($p, $data['paid_at'], $data['proof_file_id'], $request->user())->fresh())]);
    }

    public function reject(Request $request, Tenant $tenant, string $payout): JsonResponse
    {
        $p = CommissionPayout::where('tenant_id', $tenant->id)->whereKey($payout)->firstOrFail();
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => self::presentPayout($this->service->reject($p, $data['reason'])->fresh())]);
    }
}
