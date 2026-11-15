<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\FeaturedPlacement;
use App\Models\File;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\ReferralLink;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\TransactionFee;
use App\Services\BillingService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Langganan & tagihan platform dari sisi PPIU dan agen. */
class BillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    // ---------- PPIU ----------

    public function tenantShow(Tenant $tenant): JsonResponse
    {
        $plan = $this->billing->plan('tenant', $tenant->id);
        $sub = $this->billing->subscription('tenant', $tenant->id);
        $members = TenantMember::where('tenant_id', $tenant->id)->whereIn('status', ['active', 'invited'])->count();
        $featured = FeaturedPlacement::with('package')->where('tenant_id', $tenant->id)->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->orderByDesc('starts_at')->limit(20)->get();
        $fee = (int) ($tenant->settings['transaction_fee_amount'] ?? Settings::get('billing.transaction_fee_amount'));

        return response()->json(['data' => [
            'plan' => self::plan($plan), 'subscription' => self::subscription($sub),
            'plans' => Plan::where('audience', 'tenant')->where('is_public', true)->orderBy('sort')->get()->map(fn ($p) => self::plan($p))->values(),
            'usage' => ['members' => $members, 'max_members' => (int) ($plan->features['max_members'] ?? 3)],
            'transaction_fee_amount' => $fee,
            'accrued_fees' => ['count' => TransactionFee::where('tenant_id', $tenant->id)->where('status', 'accrued')->count(), 'amount' => (int) TransactionFee::where('tenant_id', $tenant->id)->where('status', 'accrued')->sum('amount')],
            'featured' => [
                'price_monthly' => (int) Settings::get('featured.price_monthly'),
                'slots_left' => max(0, (int) Settings::get('featured.max_slots') - FeaturedPlacement::where('status', 'active')->count()),
                'placements' => $featured->map(fn ($f) => ['id' => $f->id, 'status' => $f->status, 'price_monthly' => $f->price_monthly, 'starts_at' => $f->starts_at->toIso8601String(), 'ends_at' => $f->ends_at?->toIso8601String(),
                    'package' => ['id' => $f->package->id, 'name' => $f->package->name, 'slug' => $f->package->slug]])->values(),
            ],
            'invoices' => $this->invoices('tenant', $tenant->id),
            'bank_account' => Settings::get('billing.bank_account'),
        ]]);
    }

    public function tenantChangePlan(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string']]);
        $plan = Plan::find($data['plan_code']) ?? throw new ApiException(422, 'plan_invalid', 'Paket tidak dikenal.', errors: ['plan_code' => ['Tidak dikenal.']]);
        $max = (int) ($plan->features['max_members'] ?? 3);
        $members = TenantMember::where('tenant_id', $tenant->id)->whereIn('status', ['active', 'invited'])->count();
        if ($members > $max) {
            throw new ApiException(409, 'members_over_limit', "Paket {$plan->name} hanya untuk {$max} anggota tim. Nonaktifkan anggota dulu (sekarang {$members}).");
        }
        $this->billing->changePlan('tenant', $tenant->id, $plan->code, $request->user());

        return $this->tenantShow($tenant);
    }

    public function startFeatured(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['package_id' => ['required', 'uuid']]);
        $this->billing->startFeatured($tenant, $data['package_id'], $request->user());

        return $this->tenantShow($tenant);
    }

    public function endFeatured(Tenant $tenant, string $placement): JsonResponse
    {
        $f = FeaturedPlacement::where('tenant_id', $tenant->id)->whereKey($placement)->firstOrFail();
        $this->billing->endFeatured($f);

        return $this->tenantShow($tenant);
    }

    public function tenantConfirm(Request $request, Tenant $tenant, string $invoice): JsonResponse
    {
        $inv = PlatformInvoice::where('billable_type', 'tenant')->where('billable_id', $tenant->id)->whereKey($invoice)->firstOrFail();
        $this->billing->confirmPayment($inv, $this->proof($request), $request->input('note'), $request->user());

        return $this->tenantShow($tenant);
    }

    // ---------- Agen ----------

    public function agentShow(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $plan = $this->billing->plan('agent', $agent->id);

        return response()->json(['data' => [
            'plan' => self::plan($plan), 'subscription' => self::subscription($this->billing->subscription('agent', $agent->id)),
            'plans' => Plan::where('audience', 'agent')->where('is_public', true)->orderBy('sort')->get()->map(fn ($p) => self::plan($p))->values(),
            'public_page_url' => ($plan->features['public_page'] ?? false) ? rtrim(config('app.web_url'), '/').'/a/'.$agent->code : null,
            'invoices' => $this->invoices('agent', $agent->id),
            'bank_account' => Settings::get('billing.bank_account'),
        ]]);
    }

    public function agentChangePlan(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $data = $request->validate(['plan_code' => ['required', 'string']]);
        $plan = $this->billing->changePlan('agent', $agent->id, $data['plan_code'], $request->user());
        if ($plan->features['public_page'] ?? false) {
            self::ensureTenantLinks($agent);
        }

        return $this->agentShow($request);
    }

    public function agentConfirm(Request $request, string $invoice): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $inv = PlatformInvoice::where('billable_type', 'agent')->where('billable_id', $agent->id)->whereKey($invoice)->firstOrFail();
        $this->billing->confirmPayment($inv, $this->proof($request), $request->input('note'), $request->user());

        return $this->agentShow($request);
    }

    /** Link referral tingkat PPIU (tanpa paket) untuk setiap kemitraan aktif agen; dipakai halaman agen publik. */
    public static function ensureTenantLinks(Agent $agent): void
    {
        $parts = Partnership::withoutTenantScope()->where('agent_id', $agent->id)->where('status', 'active')->get();
        foreach ($parts as $p) {
            $exists = ReferralLink::withoutTenantScope()->where('agent_id', $agent->id)->where('tenant_id', $p->tenant_id)->whereNull('package_id')->where('is_active', true)->exists();
            if (! $exists) {
                $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
                do {
                    $code = '';
                    for ($i = 0; $i < 6; $i++) {
                        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                    }
                } while (DB::table('referral_links')->where('code', $code)->exists());
                ReferralLink::create(['tenant_id' => $p->tenant_id, 'partnership_id' => $p->id, 'agent_id' => $agent->id, 'package_id' => null, 'code' => $code]);
            }
        }
    }

    // ---------- Bentuk respons ----------

    private function proof(Request $request): ?string
    {
        $data = $request->validate(['proof_file_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        if (! empty($data['proof_file_id'])) {
            File::where('uploaded_by', $request->user()->id)->whereKey($data['proof_file_id'])->firstOr(fn () => throw new ApiException(422, 'validation_failed', 'File bukti tidak ditemukan.', errors: ['proof_file_id' => ['Tidak ditemukan.']]));
        }

        return $data['proof_file_id'] ?? null;
    }

    private function invoices(string $type, string $id): array
    {
        return PlatformInvoice::with('items')->where('billable_type', $type)->where('billable_id', $id)->orderByDesc('period')->limit(24)->get()
            ->map(fn ($i) => self::invoice($i))->values()->all();
    }

    public static function invoice(PlatformInvoice $i): array
    {
        return [
            'id' => $i->id, 'number' => $i->number, 'period' => $i->period, 'status' => $i->status, 'total' => $i->total,
            'due_on' => $i->due_on->toDateString(), 'issued_at' => $i->issued_at->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
            'overdue' => $i->status === 'unpaid' && $i->due_on->lt(now('Asia/Jakarta')->startOfDay()),
            'payer_note' => $i->payer_note, 'review_note' => $i->review_note, 'has_proof' => $i->proof_file_id !== null,
            'items' => $i->items->map(fn ($it) => ['type' => $it->type, 'description' => $it->description, 'quantity' => $it->quantity, 'unit_amount' => $it->unit_amount, 'amount' => $it->amount])->values(),
        ];
    }

    public static function plan(Plan $p): array
    {
        return ['code' => $p->code, 'name' => $p->name, 'price_monthly' => $p->price_monthly, 'features' => $p->features];
    }

    public static function subscription($s): ?array
    {
        return $s ? ['id' => $s->id, 'plan_code' => $s->plan_code, 'status' => $s->status, 'price_override' => $s->price_override, 'started_at' => $s->started_at->toIso8601String()] : null;
    }
}
