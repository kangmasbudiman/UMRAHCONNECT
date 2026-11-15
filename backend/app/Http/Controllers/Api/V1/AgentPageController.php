<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\ReferralLink;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;

/**
 * Halaman agen publik (fitur Agen Premium): profil singkat + paket dari PPIU mitra aktif, setiap tautan membawa
 * kode referral agen sehingga booking tercatat atas namanya. Tanpa NIK/alamat; hanya nama, kota, dan WhatsApp.
 */
class AgentPageController extends Controller
{
    public function __invoke(string $code, BillingService $billing): JsonResponse
    {
        $agent = Agent::with('user')->where('code', strtoupper($code))->where('status', 'active')->firstOrFail();
        abort_unless($billing->feature('agent', $agent->id, 'public_page'), 404);
        BillingController::ensureTenantLinks($agent);

        $parts = Partnership::withoutTenantScope()->with('tenant')->where('agent_id', $agent->id)->where('status', 'active')
            ->get()->filter(fn ($p) => $p->tenant?->verification_status === 'verified')->values();
        $links = ReferralLink::withoutTenantScope()->where('agent_id', $agent->id)->where('is_active', true)->get();
        $today = now('Asia/Jakarta')->toDateString();
        $packages = Package::publiclyVisible()->whereIn('packages.tenant_id', $parts->pluck('tenant_id'))
            ->whereHas('departures', fn ($q) => $q->where('status', 'scheduled')->whereDate('departure_date', '>=', $today))
            ->with(['tenant', 'images.file', 'departures' => fn ($q) => $q->where('status', 'scheduled')->whereDate('departure_date', '>=', $today)->orderBy('departure_date'), 'departures.prices'])
            ->get();

        return response()->json(['data' => [
            'code' => $agent->code, 'display_name' => $agent->user?->name, 'domicile_city' => $agent->domicile_city, 'whatsapp' => $agent->user?->phone,
            'partners' => $parts->map(fn ($p) => ['slug' => $p->tenant->slug, 'brand_name' => $p->tenant->brand_name, 'ppiu_license_no' => $p->tenant->ppiu_license_no, 'city' => $p->tenant->city])->values(),
            'packages' => $packages->map(function ($p) use ($links) {
                $link = $links->first(fn ($l) => $l->package_id === $p->id) ?? $links->first(fn ($l) => $l->tenant_id === $p->tenant_id && $l->package_id === null);
                $next = $p->departures->first();

                return [
                    'slug' => $p->slug, 'name' => $p->name, 'duration_days' => $p->duration_days, 'airline' => $p->airline,
                    'tenant' => ['slug' => $p->tenant->slug, 'brand_name' => $p->tenant->brand_name],
                    'cover_image_url' => ($img = $p->images->first()) ? \App\Support\CatalogPresenter::image($img)['url'] : null,
                    'price_from_amount' => (int) $p->departures->flatMap->prices->min('price_amount'),
                    'next_departure' => $next ? ['departure_date' => $next->departure_date->toDateString(), 'departure_city' => $next->departure_city] : null,
                    'referral_code' => $link?->code,
                ];
            })->sortBy('price_from_amount')->values(),
        ]])->header('Cache-Control', 'public, max-age=60');
    }
}
