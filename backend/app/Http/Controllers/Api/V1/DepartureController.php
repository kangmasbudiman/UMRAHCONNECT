<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Departure;
use App\Models\DeparturePrice;
use App\Models\Package;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\CatalogPresenter as P;
use App\Support\Etag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Keberangkatan, kuota, dan harga per kamar (FR-PPIU-05..07, CR-03, BR-08). */
class DepartureController extends Controller
{
    private const ACTIVE_BOOKING = ['awaiting_dp', 'dp_verified', 'fully_paid'];

    public function index(Tenant $tenant, string $package): JsonResponse
    {
        $pkg = Package::where('tenant_id', $tenant->id)->whereKey($package)->firstOrFail();
        $rows = Departure::where('tenant_id', $tenant->id)->where('package_id', $pkg->id)->with('prices')->orderBy('departure_date')->get();

        return response()->json(['data' => $rows->map(fn ($d) => P::departure($d))->values()]);
    }

    public function store(Request $request, Tenant $tenant, string $package): JsonResponse
    {
        $pkg = Package::where('tenant_id', $tenant->id)->whereKey($package)->firstOrFail();
        if (in_array($pkg->status, ['archived', 'taken_down'], true)) {
            throw ApiException::conflict('package_not_editable', 'Keberangkatan tidak bisa ditambahkan ke paket yang diarsipkan atau diturunkan.');
        }
        $data = $this->validated($request, null, $tenant);
        $dep = DB::transaction(function () use ($data, $pkg, $tenant) {
            $prices = $data['prices'];
            unset($data['prices']);
            $dep = Departure::create($data + ['tenant_id' => $tenant->id, 'package_id' => $pkg->id, 'sales_status' => 'open', 'status' => 'scheduled']);
            $this->writePrices($dep, $prices);
            $pkg->touch();

            return $dep;
        });
        Audit::log('departure.created', $dep, null, P::departureInput($dep->fresh('prices')), tenantId: $tenant->id, context: 'tenant');

        return $this->respond($dep->fresh('prices'), 201);
    }

    public function show(Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->find($tenant, $departure);

        return $this->respond($dep, 200, $this->activeBookings($dep));
    }

    public function update(Request $request, Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->find($tenant, $departure);
        $this->assertScheduled($dep);
        Etag::require($request, P::departureInput($dep) + ['status' => $dep->status, 'sales_status' => $dep->sales_status]);
        $data = $this->validated($request, $dep, $tenant);
        unset($data['prices']);

        $used = $dep->seats_held + $dep->seats_confirmed;
        if (isset($data['quota']) && $data['quota'] < $used) {
            throw ApiException::conflict('quota_below_used', "Kuota tidak boleh di bawah kursi terpakai ({$used} kursi).", ['seats_used' => $used]);
        }
        $active = $this->activeBookings($dep);
        $dateChanged = (isset($data['departure_date']) && $data['departure_date'] !== $dep->departure_date->toDateString())
            || (isset($data['return_date']) && $data['return_date'] !== $dep->return_date->toDateString());
        if ($dateChanged && $active > 0) {
            throw ApiException::conflict('departure_has_bookings', "Tanggal tidak bisa diubah karena ada {$active} booking aktif. Batalkan keberangkatan ini atau hubungi jamaah terlebih dahulu.", ['active_bookings_count' => $active]);
        }
        $old = P::departureInput($dep);
        $dep->update($data);
        // Kuota bertambah → buka lagi bila sebelumnya penuh.
        if ($dep->sales_status === 'full' && $dep->seatsRemaining() > 0) {
            $dep->update(['sales_status' => 'open']);
        } elseif ($dep->sales_status === 'open' && $dep->seatsRemaining() === 0) {
            $dep->update(['sales_status' => 'full']);
        }
        Audit::log('departure.updated', $dep, $old, $data, tenantId: $tenant->id, context: 'tenant');

        return $this->respond($dep->fresh('prices'), 200, $active);
    }

    /** Ganti harga per kamar. Booking yang sudah ada memakai harga saat booking (BR-08). */
    public function replacePrices(Request $request, Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->find($tenant, $departure);
        $this->assertScheduled($dep);
        Etag::require($request, P::departureInput($dep) + ['status' => $dep->status, 'sales_status' => $dep->sales_status]);
        $data = $request->validate($this->priceRules(), $this->priceMessages());
        $this->assertDpWithinPrices($dep->dp_min_per_pax_amount, $data['prices']);

        $old = P::departureInput($dep)['prices'];
        DB::transaction(function () use ($dep, $data) {
            DeparturePrice::where('departure_id', $dep->id)->delete();
            $this->writePrices($dep, $data['prices']);
            $dep->touch();
        });
        Audit::log('departure.prices_replaced', $dep, ['prices' => $old], ['prices' => $data['prices']], tenantId: $tenant->id, context: 'tenant');

        return $this->respond($dep->fresh('prices'), 200, $this->activeBookings($dep));
    }

    public function closeSales(Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->find($tenant, $departure);
        $this->assertScheduled($dep);
        if ($dep->sales_status === 'closed') {
            throw ApiException::conflict('invalid_status_transition', 'Penjualan sudah ditutup.');
        }

        return $this->setSales($dep, 'closed', 'departure.sales_closed');
    }

    public function openSales(Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->find($tenant, $departure);
        $this->assertScheduled($dep);
        if ($dep->sales_status !== 'closed') {
            throw ApiException::conflict('invalid_status_transition', 'Penjualan tidak sedang ditutup.');
        }
        if ($dep->departure_date->toDateString() <= now('Asia/Jakarta')->toDateString()) {
            throw ApiException::conflict('departure_past', 'Keberangkatan sudah lewat; penjualan tidak bisa dibuka.');
        }

        return $this->setSales($dep, $dep->seatsRemaining() > 0 ? 'open' : 'full', 'departure.sales_opened');
    }

    /** Batalkan keberangkatan (BP-08). Respons: daftar booking aktif yang harus diproses refund-nya. */
    public function cancel(Request $request, Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->find($tenant, $departure);
        $this->assertScheduled($dep);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $bookings = DB::table('bookings')->where('tenant_id', $tenant->id)->where('departure_id', $dep->id)
            ->whereIn('status', self::ACTIVE_BOOKING)->orderBy('created_at')->get(['id', 'code', 'status']);
        $dep->update(['status' => 'cancelled', 'sales_status' => 'closed']);
        Audit::log('departure.cancelled', $dep, ['status' => 'scheduled'], ['status' => 'cancelled'], $data['reason'], $tenant->id, 'tenant');

        return response()->json(['data' => $bookings->map(fn ($b) => (array) $b)->values(), 'meta' => ['departure' => P::departure($dep->fresh('prices'))]]);
    }

    public function complete(Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->find($tenant, $departure);
        if (! in_array($dep->status, ['scheduled', 'departed'], true)) {
            throw ApiException::conflict('invalid_status_transition', 'Keberangkatan ini tidak bisa ditandai selesai.');
        }
        if ($dep->return_date->toDateString() > now('Asia/Jakarta')->toDateString()) {
            throw ApiException::conflict('departure_not_finished', 'Keberangkatan baru bisa ditandai selesai setelah tanggal kepulangan.');
        }
        DB::transaction(function () use ($dep) {
            DB::table('bookings')->where('departure_id', $dep->id)->where('status', 'departed')->update(['status' => 'completed', 'updated_at' => now()]);
            $dep->update(['status' => 'completed', 'sales_status' => 'closed']);
        });
        Audit::log('departure.completed', $dep, null, ['status' => 'completed'], tenantId: $tenant->id, context: 'tenant');

        return $this->respond($dep->fresh('prices'));
    }

    private function setSales(Departure $dep, string $to, string $action): JsonResponse
    {
        $from = $dep->sales_status;
        $dep->update(['sales_status' => $to]);
        Audit::log($action, $dep, ['sales_status' => $from], ['sales_status' => $to], tenantId: $dep->tenant_id, context: 'tenant');

        return $this->respond($dep->fresh('prices'));
    }

    private function respond(Departure $dep, int $status = 200, ?int $active = null): JsonResponse
    {
        return response()->json(['data' => P::departure($dep, $active)], $status)->header('ETag', P::departureEtag($dep));
    }

    private function find(Tenant $tenant, string $id): Departure
    {
        return Departure::where('tenant_id', $tenant->id)->whereKey($id)->with('prices')->firstOrFail();
    }

    private function assertScheduled(Departure $dep): void
    {
        if ($dep->status !== 'scheduled') {
            throw ApiException::conflict('departure_not_editable', 'Keberangkatan yang sudah berangkat, selesai, atau dibatalkan tidak bisa diubah.');
        }
    }

    private function activeBookings(Departure $dep): int
    {
        return DB::table('bookings')->where('departure_id', $dep->id)->whereIn('status', self::ACTIVE_BOOKING)->count();
    }

    private function writePrices(Departure $dep, array $prices): void
    {
        foreach ($prices as $p) {
            DeparturePrice::create(['tenant_id' => $dep->tenant_id, 'departure_id' => $dep->id, 'room_type' => $p['room_type'], 'price_amount' => (int) $p['price_amount']]);
        }
    }

    private function priceRules(): array
    {
        return [
            'prices' => ['required', 'array', 'min:1', 'max:3'],
            'prices.*.room_type' => ['required', Rule::in(['quad', 'triple', 'double']), 'distinct'],
            'prices.*.price_amount' => ['required', 'integer', 'min:1000000', 'max:1000000000'],
        ];
    }

    private function priceMessages(): array
    {
        return [
            'prices.required' => 'Isi minimal satu harga kamar.',
            'prices.*.room_type.distinct' => 'Tipe kamar tidak boleh dobel.',
            'prices.*.price_amount.min' => 'Harga minimal Rp1.000.000.',
        ];
    }

    private function assertDpWithinPrices(int $dp, array $prices): void
    {
        $min = min(array_map(fn ($p) => (int) $p['price_amount'], $prices));
        if ($dp > $min) {
            throw new ApiException(422, 'validation_failed', 'DP minimal tidak boleh melebihi harga kamar termurah.', errors: ['dp_min_per_pax_amount' => ['DP melebihi harga termurah ('.number_format($min, 0, ',', '.').').']]);
        }
    }

    private function validated(Request $request, ?Departure $dep, Tenant $tenant): array
    {
        $creating = $dep === null;
        $req = $creating ? 'required' : 'sometimes';
        $today = now('Asia/Jakarta')->toDateString();
        $rules = [
            'departure_date' => [$req, 'date_format:Y-m-d', 'after:'.$today],
            'return_date' => [$req, 'date_format:Y-m-d'],
            'departure_city' => [$req, 'string', 'max:100'],
            'quota' => [$req, 'integer', 'min:1', 'max:1000'],
            'dp_min_per_pax_amount' => [$req, 'integer', 'min:100000'],
            'dp_deadline_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'final_payment_due_on' => [$req, 'date_format:Y-m-d'],
        ] + ($creating ? $this->priceRules() : []);
        $data = $request->validate($rules, [
            'departure_date.after' => 'Tanggal berangkat harus setelah hari ini.',
            'quota.min' => 'Kuota minimal 1 kursi.',
            'dp_min_per_pax_amount.min' => 'DP minimal Rp100.000.',
        ] + $this->priceMessages());

        // Validasi lintas field memakai nilai baru atau nilai lama.
        $depDate = $data['departure_date'] ?? $dep?->departure_date->toDateString();
        $retDate = $data['return_date'] ?? $dep?->return_date->toDateString();
        $finalDue = $data['final_payment_due_on'] ?? $dep?->final_payment_due_on->toDateString();
        $errors = [];
        if ($retDate < $depDate) {
            $errors['return_date'] = ['Tanggal pulang tidak boleh sebelum tanggal berangkat.'];
        }
        if ($finalDue > $depDate) {
            $errors['final_payment_due_on'] = ['Batas pelunasan harus sebelum atau sama dengan tanggal berangkat.'];
        } elseif ($finalDue < $today && isset($data['final_payment_due_on'])) {
            $errors['final_payment_due_on'] = ['Batas pelunasan sudah lewat.'];
        }
        if ($errors) {
            throw new ApiException(422, 'validation_failed', 'Data yang dikirim belum valid.', errors: $errors);
        }
        $prices = $data['prices'] ?? $dep?->prices->map(fn ($p) => ['price_amount' => $p->price_amount])->all();
        if (isset($data['dp_min_per_pax_amount']) && $prices) {
            $this->assertDpWithinPrices($data['dp_min_per_pax_amount'], $prices);
        }
        if ($creating && ! isset($data['dp_deadline_hours'])) {
            $data['dp_deadline_hours'] = (int) ($tenant->settings['dp_deadline_hours_default'] ?? 48);
        }

        return $data;
    }
}
