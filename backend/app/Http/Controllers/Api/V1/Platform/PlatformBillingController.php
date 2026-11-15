<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\FeaturedPlacement;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TransactionFee;
use App\Services\BillingService;
use App\Support\Audit;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Dasbor pendapatan & penagihan (Super Admin). */
class PlatformBillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    public function revenue(): JsonResponse
    {
        $subs = Subscription::with('plan')->where('status', 'active')->get();
        $mrr = $subs->sum(fn ($s) => $s->monthlyPrice());
        $featured = FeaturedPlacement::where('status', 'active')->get();
        $period = now('Asia/Jakarta')->subMonth()->format('Y-m');

        return response()->json(['data' => [
            'mrr' => $mrr + (int) $featured->sum('price_monthly'),
            'subscriptions' => $subs->groupBy('plan_code')->map(fn ($g, $code) => ['plan_code' => $code, 'name' => $g->first()->plan->name, 'count' => $g->count(), 'monthly' => $g->sum(fn ($s) => $s->monthlyPrice())])->values(),
            'past_due' => Subscription::where('status', 'past_due')->count(),
            'featured' => ['active' => $featured->count(), 'monthly' => (int) $featured->sum('price_monthly')],
            'fees_accrued' => ['count' => TransactionFee::where('status', 'accrued')->count(), 'amount' => (int) TransactionFee::where('status', 'accrued')->sum('amount')],
            'invoices' => [
                'outstanding' => (int) PlatformInvoice::whereIn('status', ['unpaid', 'awaiting_confirmation'])->sum('total'),
                'awaiting_confirmation' => PlatformInvoice::where('status', 'awaiting_confirmation')->count(),
                'overdue' => PlatformInvoice::where('status', 'unpaid')->where('due_on', '<', now('Asia/Jakarta')->toDateString())->count(),
                'paid_last_12m' => (int) PlatformInvoice::where('status', 'paid')->where('paid_at', '>=', now()->subYear())->sum('total'),
            ],
            'last_period' => $period,
            'last_period_billed' => PlatformInvoice::where('period', $period)->exists(),
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['unpaid', 'awaiting_confirmation', 'paid', 'void'])]]);
        $q = PlatformInvoice::with('items')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'awaiting_confirmation' THEN 0 WHEN 'unpaid' THEN 1 ELSE 2 END")->orderByDesc('period')->orderByDesc('created_at');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 30);
        $tenants = Tenant::whereIn('id', $rows->where('billable_type', 'tenant')->pluck('billable_id'))->pluck('brand_name', 'id');
        $agents = Agent::with('user')->whereIn('id', $rows->where('billable_type', 'agent')->pluck('billable_id'))->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn ($i) => BillingController::invoice($i) + [
            'billable_type' => $i->billable_type,
            'billable_name' => $i->billable_type === 'tenant' ? ($tenants[$i->billable_id] ?? '—') : (($a = $agents[$i->billable_id] ?? null) ? "{$a->user?->name} ({$a->code})" : '—'),
            'proof_url' => $i->proof_file_id ? \Illuminate\Support\Facades\URL::temporarySignedRoute('files.content', now()->addMinutes(10), ['file' => $i->proof_file_id], absolute: false) : null,
        ])->values(), 'meta' => $meta]);
    }

    public function markPaid(Request $request, string $invoice): JsonResponse
    {
        $inv = PlatformInvoice::with('items')->findOrFail($invoice);

        return response()->json(['data' => BillingController::invoice($this->billing->markPaid($inv, $request->user(), $request->input('note'))->fresh('items'))]);
    }

    public function reject(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => BillingController::invoice($this->billing->rejectPayment(PlatformInvoice::findOrFail($invoice), $request->user(), $data['note'])->fresh('items'))]);
    }

    public function void(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => BillingController::invoice($this->billing->void(PlatformInvoice::findOrFail($invoice), $request->user(), $data['note'])->fresh('items'))]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('audience')->orderBy('sort')->get()->map(fn ($p) => BillingController::plan($p) + ['audience' => $p->audience, 'is_public' => $p->is_public])->values()]);
    }

    public function updatePlan(Request $request, string $code): JsonResponse
    {
        $plan = Plan::findOrFail($code);
        $data = $request->validate([
            'price_monthly' => ['required', 'integer', 'min:0', 'max:100000000'],
            'features.max_members' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'features.manifest_export' => ['sometimes', 'boolean'],
            'features.public_page' => ['sometimes', 'boolean'],
        ]);
        $old = $plan->only(['price_monthly', 'features']);
        $plan->update(['price_monthly' => $data['price_monthly'], 'features' => array_merge($plan->features ?? [], $data['features'] ?? []), 'updated_at' => now()]);
        Audit::log('billing.plan_updated', $request->user(), $old + ['code' => $code], $plan->only(['price_monthly', 'features']), null, null, 'platform');

        return response()->json(['data' => BillingController::plan($plan)]);
    }

    public function tenantContractShow(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => [
            'plan' => BillingController::plan($this->billing->plan('tenant', $tenant->id)),
            'subscription' => BillingController::subscription($this->billing->subscription('tenant', $tenant->id)),
            'transaction_fee_amount' => $tenant->settings['transaction_fee_amount'] ?? null,
            'default_transaction_fee_amount' => (int) \App\Support\Settings::get('billing.transaction_fee_amount'),
        ]]);
    }

    /** Kontrak khusus per PPIU: paket (termasuk Enterprise), harga khusus, tarif biaya per transaksi. */
    public function tenantContract(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['sometimes', 'string'],
            'price_override' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'transaction_fee_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5000000'],
        ]);
        if (array_key_exists('plan_code', $data)) {
            $this->billing->changePlan('tenant', $tenant->id, $data['plan_code'], $request->user(), $data['price_override'] ?? null, true);
        }
        if (array_key_exists('transaction_fee_amount', $data)) {
            $settings = $tenant->settings ?? [];
            if ($data['transaction_fee_amount'] === null) {
                unset($settings['transaction_fee_amount']);
            } else {
                $settings['transaction_fee_amount'] = $data['transaction_fee_amount'];
            }
            $old = $tenant->settings['transaction_fee_amount'] ?? null;
            $tenant->update(['settings' => $settings]);
            Audit::log('billing.contract_updated', $tenant, ['transaction_fee_amount' => $old], ['transaction_fee_amount' => $data['transaction_fee_amount']], null, $tenant->id, 'platform');
        }
        $plan = $this->billing->plan('tenant', $tenant->id);

        return response()->json(['data' => ['plan' => BillingController::plan($plan), 'subscription' => BillingController::subscription($this->billing->subscription('tenant', $tenant->id)),
            'transaction_fee_amount' => $tenant->fresh()->settings['transaction_fee_amount'] ?? null]]);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        if ($data['period'] >= now('Asia/Jakarta')->format('Y-m')) {
            throw new \App\Exceptions\ApiException(422, 'period_not_closed', 'Tagihan hanya untuk bulan yang sudah selesai.', errors: ['period' => ['Pilih bulan yang sudah lewat.']]);
        }
        $n = $this->billing->runPeriod($data['period']);
        Audit::log('billing.run', $request->user(), null, ['period' => $data['period'], 'invoices' => $n], null, null, 'platform');

        return response()->json(['data' => ['created' => $n]]);
    }
}
