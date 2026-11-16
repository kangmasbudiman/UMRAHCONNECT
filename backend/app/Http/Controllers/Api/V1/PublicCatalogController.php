<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Departure;
use App\Models\Package;
use App\Models\ReferralLink;
use App\Models\Tenant;
use App\Support\OffsetCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint publik marketplace (API Documentation §4, openapi.yaml tag Public).
 * Hanya PPIU terverifikasi dan paket terbit; harga selalu harga resmi keberangkatan (BR-07).
 */
class PublicCatalogController extends Controller
{
    private const ROOMS = ['quad', 'triple', 'double'];

    /** GET /public/packages — FR-MKT-01..03 */
    public function packages(Request $request): JsonResponse
    {
        $v = $request->validate([
            'price_min' => 'nullable|integer|min:0',
            'price_max' => 'nullable|integer|min:0',
            'duration_days' => 'nullable|integer|min:1',
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'airline' => 'nullable|string|max:100',
            'hotel_stars' => 'nullable|integer|between:1,5',
            'tenant' => 'nullable|string|max:100',
            'departure_city' => 'nullable|string|max:100',
            'province' => ['nullable', 'regex:/^\d{2}$/'],            // lokasi kantor PPIU (kode wilayah)
            'regency' => ['nullable', 'regex:/^\d{2}\.\d{2}$/'],
            'q' => 'nullable|string|max:100',
            'elderly' => 'nullable|boolean',
            'featured' => 'nullable|boolean',
            'sort' => 'nullable|in:price,-price,departure_date,-published_at,-rating',
            'limit' => 'nullable|integer|min:1|max:100',
            'cursor' => 'nullable|string',
        ]);
        $today = $this->today();

        // Syarat keberangkatan: akan datang, terjadwal, dan (bila ada) cocok dengan filter bulan/kota/harga.
        $depFilter = function ($q) use ($v, $today) {
            $q->where('status', 'scheduled')->whereDate('departure_date', '>=', $today);
            if (! empty($v['month'])) {
                $q->whereRaw("to_char(departure_date, 'YYYY-MM') = ?", [$v['month']]);
            }
            if (! empty($v['departure_city'])) {
                $q->where('departure_city', 'ilike', $v['departure_city']);
            }
            if (isset($v['price_min']) || isset($v['price_max'])) {
                $q->whereHas('prices', function ($p) use ($v) {
                    if (isset($v['price_min'])) {
                        $p->where('price_amount', '>=', $v['price_min']);
                    }
                    if (isset($v['price_max'])) {
                        $p->where('price_amount', '<=', $v['price_max']);
                    }
                });
            }
        };

        $minPrice = DB::table('departure_prices as dp')
            ->join('departures as d', 'd.id', '=', 'dp.departure_id')
            ->whereColumn('d.package_id', 'packages.id')
            ->where('d.status', 'scheduled')->whereNull('d.deleted_at')->where('d.departure_date', '>=', $today)
            ->selectRaw('min(dp.price_amount)');
        $nextDate = DB::table('departures as d')
            ->whereColumn('d.package_id', 'packages.id')
            ->where('d.status', 'scheduled')->whereNull('d.deleted_at')->where('d.departure_date', '>=', $today)
            ->selectRaw('min(d.departure_date)');

        $query = Package::publiclyVisible()
            ->select('packages.*')
            ->selectSub($minPrice, 'price_from_amount')
            ->selectSub($nextDate, 'next_departure_date')
            ->whereHas('departures', $depFilter)
            ->with(['tenant', 'images.file', 'departures' => fn ($q) => $q->where('status', 'scheduled')->whereDate('departure_date', '>=', $today)->orderBy('departure_date'), 'departures.prices']);

        if (! empty($v['duration_days'])) {
            $query->where('duration_days', $v['duration_days']);
        }
        if (! empty($v['airline'])) {
            $query->where('airline', 'ilike', $v['airline']);
        }
        $featuredIds = \App\Services\BillingService::featuredPackageIds();
        if (! empty($v['featured'])) {
            $query->whereIn('packages.id', $featuredIds ?: ['00000000-0000-0000-0000-000000000000']);
        }
        if (! empty($v['elderly'])) {
            $query->where('hotel_makkah_distance_m', '<=', \App\Support\CatalogPresenter::ELDERLY_MAX_DISTANCE_M)
                ->where('hotel_madinah_distance_m', '<=', \App\Support\CatalogPresenter::ELDERLY_MAX_DISTANCE_M);
        }
        if (! empty($v['hotel_stars'])) {
            $query->where('hotel_makkah_stars', '>=', $v['hotel_stars']);
        }
        if (! empty($v['tenant'])) {
            $query->whereHas('tenant', fn ($t) => $t->where('slug', $v['tenant']));
        }
        // Cari per lokasi PPIU (skala nasional): provinsi dan/atau kab/kota kantor travel.
        if (! empty($v['regency'])) {
            $query->whereHas('tenant', fn ($t) => $t->where('regency_code', $v['regency']));
        } elseif (! empty($v['province'])) {
            $query->whereHas('tenant', fn ($t) => $t->where('province_code', $v['province']));
        }
        if (! empty($v['q'])) {
            $term = '%'.addcslashes($v['q'], '%_\\').'%';
            $query->where(function (Builder $w) use ($term) {
                $w->where('packages.name', 'ilike', $term)
                    ->orWhere('packages.airline', 'ilike', $term)
                    ->orWhere('packages.hotel_makkah', 'ilike', $term)
                    ->orWhere('packages.hotel_madinah', 'ilike', $term)
                    ->orWhereHas('tenant', fn ($t) => $t->where('brand_name', 'ilike', $term));
            });
        }

        // Paket pilihan (promosi berbayar) disematkan di atas hanya pada urutan bawaan, selalu berlabel "Promosi".
        if (($v['sort'] ?? 'departure_date') === 'departure_date' && $featuredIds) {
            $query->orderByRaw('CASE WHEN packages.id IN ('.implode(',', array_fill(0, count($featuredIds), '?')).') THEN 0 ELSE 1 END', $featuredIds);
        }
        match ($v['sort'] ?? 'departure_date') {
            'price' => $query->orderBy('price_from_amount'),
            '-price' => $query->orderByDesc('price_from_amount'),
            '-published_at' => $query->orderByDesc('packages.published_at'),
            '-rating' => $query->orderByRaw("(select avg(r.rating) from reviews r where r.package_id = packages.id and r.status = 'published') desc nulls last"),
            default => $query->orderBy('next_departure_date'),
        };
        $query->orderBy('packages.id');

        [$rows, $meta] = OffsetCursor::paginate($query, $request);
        $ratings = \App\Support\Ratings::forPackages($rows->pluck('id')->all());

        return response()->json(['data' => $rows->map(fn (Package $p) => $this->packageSummary($p, $ratings) + ['featured' => in_array($p->id, $featuredIds, true)])->values(), 'meta' => $meta]);
    }

    /** GET /public/packages/{tenantSlug}/{packageSlug} — FR-MKT-04, FR-MKT-05, FR-MKT-10 */
    public function package(string $tenantSlug, string $packageSlug): JsonResponse
    {
        $package = Package::publiclyVisible()
            ->whereHas('tenant', fn ($t) => $t->where('slug', $tenantSlug))
            ->where('slug', $packageSlug)
            ->with(['tenant', 'images.file', 'departures' => fn ($q) => $q->upcoming()->orderBy('departure_date'), 'departures.prices'])
            ->firstOrFail();

        return response()->json(['data' => array_merge($this->packageFull($package), [
            'transparency' => \App\Support\CatalogPresenter::transparency($package),
            'elderly_friendly' => \App\Support\CatalogPresenter::elderlyFriendly($package),
            'rating' => \App\Support\Ratings::forPackages([$package->id])[$package->id] ?? null,
            'tenant' => $this->tenantPublic($package->tenant) + ['rating' => \App\Support\Ratings::forTenant($package->tenant_id)],
            'departures' => $package->departures->map(fn (Departure $d) => $this->departurePublic($d))->values(),
        ])]);
    }

    /** GET /public/tenants — FR-MKT-06 */
    public function tenants(Request $request): JsonResponse
    {
        $request->validate([
            'city' => 'nullable|string|max:100',
            'province' => ['nullable', 'regex:/^\d{2}$/'],
            'regency' => ['nullable', 'regex:/^\d{2}\.\d{2}$/'],
            'near' => ['nullable', 'string', 'max:40'],                 // "lat,lng" — PPIU terdekat (aplikasi ponsel/peta)
            'radius' => ['nullable', 'numeric', 'min:1', 'max:500'],   // km, bawaan 50
            'limit' => 'nullable|integer|min:1|max:100', 'cursor' => 'nullable|string',
        ]);
        $near = null;
        if ($request->filled('near') && ! ($near = \App\Support\Geo::parsePoint($request->query('near')))) {
            throw new \App\Exceptions\ApiException(422, 'validation_failed', 'Titik lokasi tidak valid.', errors: ['near' => ['Format "lat,lng" di wilayah Indonesia, mis. -1.61,103.61.']]);
        }
        $query = Tenant::verified()->orderBy('brand_name')->orderBy('id');
        if ($city = $request->query('city')) {
            $query->where('city', 'ilike', $city);
        }
        if ($regency = $request->query('regency')) {
            $query->where('regency_code', $regency);
        } elseif ($province = $request->query('province')) {
            $query->where('province_code', $province);
        }
        if ($near) {
            $query->select('tenants.*');
            \App\Support\Geo::nearest($query, 'tenants', $near[0], $near[1], (float) ($request->query('radius') ?: 50))->orderBy('tenants.id');
        }
        [$rows, $meta] = OffsetCursor::paginate($query, $request);

        return response()->json(['data' => $rows->map(fn (Tenant $t) => $this->tenantPublic($t) + ($near ? ['distance_km' => (float) $t->distance_km] : []))->values(), 'meta' => $meta]);
    }

    /** GET /public/tenants/{tenantSlug} — FR-MKT-06 */
    public function tenant(string $tenantSlug): JsonResponse
    {
        $tenant = Tenant::verified()->where('slug', $tenantSlug)->firstOrFail();
        $today = $this->today();
        $packages = Package::publiclyVisible()
            ->where('packages.tenant_id', $tenant->id)
            ->whereHas('departures', fn ($q) => $q->upcoming())
            ->with(['tenant', 'images.file', 'departures' => fn ($q) => $q->upcoming()->orderBy('departure_date'), 'departures.prices'])
            ->orderBy('name')->get();

        $ratings = \App\Support\Ratings::forPackages($packages->pluck('id')->all());

        return response()->json(['data' => array_merge($this->tenantPublic($tenant), [
            'rating' => \App\Support\Ratings::forTenant($tenant->id),
            'packages' => $packages->map(fn (Package $p) => $this->packageSummary($p, $ratings))->values(),
        ])]);
    }

    /** GET /public/departures — FR-MKT-07 */
    public function departures(Request $request): JsonResponse
    {
        $v = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'departure_city' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:100', 'cursor' => 'nullable|string']);
        $query = Departure::query()->withoutTenantScope()->upcoming()
            ->whereHas('package', fn ($p) => $p->publiclyVisible())
            ->with(['prices', 'package.tenant'])
            ->orderBy('departure_date')->orderBy('id');
        if (! empty($v['month'])) {
            $query->whereRaw("to_char(departure_date, 'YYYY-MM') = ?", [$v['month']]);
        }
        if (! empty($v['departure_city'])) {
            $query->where('departure_city', 'ilike', $v['departure_city']);
        }
        [$rows, $meta] = OffsetCursor::paginate($query, $request);

        return response()->json(['data' => $rows->map(fn (Departure $d) => array_merge($this->departurePublic($d), [
            'package' => ['slug' => $d->package->slug, 'name' => $d->package->name, 'duration_days' => $d->package->duration_days, 'airline' => $d->package->airline],
            'tenant' => ['slug' => $d->package->tenant->slug, 'brand_name' => $d->package->tenant->brand_name],
        ]))->values(), 'meta' => $meta]);
    }

    /** GET /public/facets — FR-MKT-02 */
    public function facets(): JsonResponse
    {
        $today = $this->today();
        $visible = Package::publiclyVisible()->whereHas('departures', fn ($q) => $q->upcoming());
        $packageIds = (clone $visible)->pluck('packages.id');
        $prices = DB::table('departure_prices as dp')->join('departures as d', 'd.id', '=', 'dp.departure_id')
            ->whereIn('d.package_id', $packageIds)->where('d.status', 'scheduled')->whereNull('d.deleted_at')->where('d.departure_date', '>=', $today)
            ->selectRaw('min(dp.price_amount) as lo, max(dp.price_amount) as hi')->first();

        return response()->json(['data' => [
            'airlines' => (clone $visible)->distinct()->orderBy('airline')->pluck('airline')->values(),
            'departure_cities' => DB::table('departures')->whereIn('package_id', $packageIds)->where('status', 'scheduled')->whereNull('deleted_at')
                ->where('departure_date', '>=', $today)->distinct()->orderBy('departure_city')->pluck('departure_city')->values(),
            'months' => DB::table('departures')->whereIn('package_id', $packageIds)->where('status', 'scheduled')->whereNull('deleted_at')
                ->where('departure_date', '>=', $today)->selectRaw("distinct to_char(departure_date, 'YYYY-MM') as m")->orderBy('m')->pluck('m')->values(),
            'price_min_amount' => (int) ($prices->lo ?? 0),
            'price_max_amount' => (int) ($prices->hi ?? 0),
            'ppiu_regions' => $this->ppiuRegions(),
        ]]);
    }

    /** Provinsi & kab/kota yang punya PPIU terverifikasi — isi dropdown "Lokasi PPIU" (tidak menampilkan daerah kosong). */
    private function ppiuRegions(): array
    {
        $rows = DB::table('tenants as t')
            ->join('regions as p', 'p.code', '=', 't.province_code')
            ->leftJoin('regions as r', 'r.code', '=', 't.regency_code')
            ->where('t.verification_status', 'verified')->whereNull('t.deleted_at')
            ->groupBy('p.code', 'p.name', 'r.code', 'r.name')
            ->orderBy('p.name')->orderBy('r.name')
            ->get(['p.code as pc', 'p.name as pn', 'r.code as rc', 'r.name as rn', DB::raw('count(*) as n')]);

        return $rows->groupBy('pc')->map(fn ($g) => [
            'code' => $g->first()->pc, 'name' => $g->first()->pn, 'count' => (int) $g->sum('n'),
            'regencies' => $g->whereNotNull('rc')->map(fn ($r) => ['code' => $r->rc, 'name' => $r->rn, 'count' => (int) $r->n])->values()->all(),
        ])->values()->all();
    }

    /** GET /public/referrals/{code} — FR-MKT-08, FR-MKT-10 */
    public function referral(string $code): JsonResponse
    {
        $link = $this->activeReferral($code);

        return response()->json(['data' => [
            'agent' => [
                'id' => $link->agent->id,
                'code' => $link->agent->code,
                'display_name' => $link->agent->user->name,
                'whatsapp' => $link->agent->user->phone,
                'domicile_city' => $link->agent->domicile_city,
            ],
            'tenant' => ['id' => $link->tenant->id, 'slug' => $link->tenant->slug, 'brand_name' => $link->tenant->brand_name, 'whatsapp' => $link->tenant->whatsapp],
            'package_slug' => $link->package?->slug,
        ]]);
    }

    /** POST /public/referrals/{code}/clicks — FR-AGT-06 (tanpa data pribadi) */
    public function referralClick(string $code): Response
    {
        $link = $this->activeReferral($code);
        ReferralLink::withoutTenantScope()->whereKey($link->id)->increment('click_count');

        return response()->noContent();
    }

    // ------------------------------------------------------------------

    /** Link aktif hanya bila kemitraan Aktif, agen aktif, dan PPIU terverifikasi (CR-01, BR-04). */
    private function activeReferral(string $code): ReferralLink
    {
        return ReferralLink::withoutTenantScope()
            ->where('code', $code)->where('is_active', true)
            ->whereHas('partnership', fn ($p) => $p->withoutTenantScope()->where('status', 'active'))
            ->whereHas('agent', fn ($a) => $a->where('status', 'active'))
            ->whereHas('tenant', fn ($t) => $t->verified())
            ->with(['agent.user', 'tenant', 'package' => fn ($q) => $q->withoutTenantScope()])
            ->firstOrFail();
    }

    private function today(): string
    {
        return now('Asia/Jakarta')->toDateString();
    }

    private function tenantPublic(Tenant $t): array
    {
        return [
            'id' => $t->id, 'slug' => $t->slug, 'brand_name' => $t->brand_name, 'legal_name' => $t->legal_name,
            'ppiu_license_no' => $t->ppiu_license_no, 'verified' => $t->verification_status === 'verified',
            'city' => $t->city, 'province' => $t->province, 'address' => $t->address,
            'province_code' => $t->province_code, 'regency_code' => $t->regency_code, 'postal_code' => $t->postal_code,
            'latitude' => $t->latitude !== null ? (float) $t->latitude : null, 'longitude' => $t->longitude !== null ? (float) $t->longitude : null,
            'phone' => $t->phone, 'whatsapp' => $t->whatsapp, 'email' => $t->email, 'logo_url' => null,
        ];
    }

    private function packageSummary(Package $p, array $ratings = []): array
    {
        $next = $p->departures->first();
        $priceFrom = $p->price_from_amount ?? $p->departures->flatMap(fn ($d) => $d->prices ?? collect())->min('price_amount');

        return [
            'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name,
            'tenant' => ['slug' => $p->tenant->slug, 'brand_name' => $p->tenant->brand_name, 'verified' => true, 'ppiu_license_no' => $p->tenant->ppiu_license_no],
            'duration_days' => $p->duration_days, 'airline' => $p->airline, 'hotel_makkah_stars' => $p->hotel_makkah_stars,
            'hotel_makkah_distance_m' => $p->hotel_makkah_distance_m, 'hotel_madinah_distance_m' => $p->hotel_madinah_distance_m,
            'elderly_friendly' => \App\Support\CatalogPresenter::elderlyFriendly($p),
            'transparency_score' => \App\Support\CatalogPresenter::transparency($p)['score'],
            'rating' => $ratings[$p->id] ?? null,
            'cover_image_url' => ($img = $p->images->first()) ? \App\Support\CatalogPresenter::image($img)['url'] : null,
            'price_from_amount' => (int) $priceFrom,
            'next_departure' => $next ? [
                'departure_date' => $next->departure_date->toDateString(),
                'departure_city' => $next->departure_city,
                'seats_remaining' => $next->seatsRemaining(),
            ] : null,
        ];
    }

    private function packageFull(Package $p): array
    {
        return [
            'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'status' => $p->status,
            'duration_days' => $p->duration_days, 'airline' => $p->airline,
            'hotel_makkah' => $p->hotel_makkah, 'hotel_makkah_stars' => $p->hotel_makkah_stars, 'hotel_makkah_distance_m' => $p->hotel_makkah_distance_m,
            'hotel_madinah' => $p->hotel_madinah, 'hotel_madinah_stars' => $p->hotel_madinah_stars, 'hotel_madinah_distance_m' => $p->hotel_madinah_distance_m,
            'description' => $p->description, 'itinerary' => $p->itinerary, 'facilities' => $p->facilities,
            'includes' => $p->includes, 'excludes' => $p->excludes,
            'document_requirements' => $p->document_requirements, 'payment_terms' => $p->payment_terms, 'cancellation_terms' => $p->cancellation_terms,
            'published_at' => $p->published_at?->toIso8601ZuluString(),
            'images' => $p->images->map(fn ($i) => \App\Support\CatalogPresenter::image($i))->values(),
        ];
    }

    private function departurePublic(Departure $d): array
    {
        $prices = $d->prices->sortBy(fn ($p) => array_search($p->room_type, self::ROOMS))->values();

        return [
            'id' => $d->id,
            'departure_date' => $d->departure_date->toDateString(),
            'return_date' => $d->return_date->toDateString(),
            'departure_city' => $d->departure_city,
            'seats_remaining' => $d->seatsRemaining(),
            'sales_status' => $d->sales_status,
            'dp_min_per_pax_amount' => $d->dp_min_per_pax_amount,
            'final_payment_due_on' => $d->final_payment_due_on->toDateString(),
            'prices' => $prices->map(fn ($p) => ['room_type' => $p->room_type, 'price_amount' => $p->price_amount])->values(),
        ];
    }

    /** Pengaturan publik yang dibutuhkan web (mis. jendela atribusi cookie referral, CR-01). */
    public function config(): JsonResponse
    {
        return response()->json(['data' => ['attribution_window_days' => (int) \App\Support\Settings::get('attribution.window_days')]])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
