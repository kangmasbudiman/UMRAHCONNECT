<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Departure;
use App\Models\Tenant;
use App\Services\BillingService;
use App\Support\Audit;
use App\Support\Regions;
use App\Services\ReportService;

/**
 * Fitur PPIU Business: manifest jamaah (untuk visa & maskapai) dan rooming list per keberangkatan, CSV siap Excel.
 * Memuat NIK & paspor utuh → hanya peran dengan pilgrim.identity.view, dan setiap unduhan tercatat di audit log.
 */
class ManifestController extends Controller
{
    private const ROOM = ['quad' => 'Quad (4)', 'triple' => 'Triple (3)', 'double' => 'Double (2)'];

    public function __invoke(Tenant $tenant, string $departure, BillingService $billing)
    {
        $type = request()->query('type') === 'rooming' ? 'rooming' : 'manifest';
        $billing->requireFeature('tenant', $tenant->id, 'manifest_export', 'Ekspor manifest & rooming list tersedia di paket PPIU Business. Buka tab Langganan untuk meningkatkan paket.');
        $dep = Departure::where('tenant_id', $tenant->id)->whereKey($departure)->with('package')->firstOrFail();
        $rows = BookingParticipant::withoutTenantScope()->with(['pilgrim', 'booking.booker', 'booking.agent.user'])
            ->where('departure_id', $dep->id)->where('is_active', true)
            ->whereHas('booking', fn ($q) => $q->withoutTenantScope()->whereIn('status', ['awaiting_dp', 'dp_verified', 'fully_paid', 'departed', 'completed']))
            ->get()
            ->sortBy(fn ($p) => [array_search($p->room_type, array_keys(self::ROOM)), $p->booking->code, $p->pilgrim->gender, $p->pilgrim->full_name])->values();

        if ($type === 'manifest') {
            $head = ['No', 'Nama lengkap', 'L/P', 'Tempat lahir', 'Tanggal lahir', 'NIK', 'No. paspor', 'Paspor berlaku s.d.', 'Alamat', 'Kelurahan/Desa', 'Kecamatan', 'Kab/Kota', 'Provinsi', 'Kode pos', 'Tipe kamar', 'Kode booking', 'Status booking', 'Pemesan', 'HP pemesan', 'Agen'];
            $rn = Regions::names($rows->flatMap(fn ($p) => [$p->pilgrim->village_code, $p->pilgrim->district_code, $p->pilgrim->regency_code, $p->pilgrim->province_code])->all());
            $data = $rows->map(fn ($p, $i) => [$i + 1, $p->pilgrim->full_name, $p->pilgrim->gender, $p->pilgrim->birth_place, $p->pilgrim->birth_date?->toDateString(),
                $p->pilgrim->nik_enc, $p->pilgrim->passport_no_enc, $p->pilgrim->passport_expires_on?->toDateString(),
                $p->pilgrim->address_enc, $rn[$p->pilgrim->village_code] ?? '', $rn[$p->pilgrim->district_code] ?? '', $rn[$p->pilgrim->regency_code] ?? '', $rn[$p->pilgrim->province_code] ?? '', $p->pilgrim->postal_code,
                self::ROOM[$p->room_type] ?? $p->room_type,
                $p->booking->code, $p->booking->status, $p->booking->booker?->name, $p->booking->booker?->phone, $p->booking->agent ? "{$p->booking->agent->user?->name} ({$p->booking->agent->code})" : '']);
        } else {
            // Rooming list: jamaah satu booking & satu tipe kamar dikelompokkan; kamar diisi sampai kapasitas.
            $cap = ['quad' => 4, 'triple' => 3, 'double' => 2];
            $head = ['Kamar', 'Tipe kamar', 'Nama lengkap', 'L/P', 'Kode booking'];
            $data = [];
            $n = 0;
            foreach ($rows->groupBy(fn ($p) => $p->booking->code.'|'.$p->room_type) as $group) {
                foreach ($group->chunk($cap[$group->first()->room_type] ?? 4) as $room) {
                    $n++;
                    foreach ($room as $p) {
                        $data[] = ['K'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), self::ROOM[$p->room_type] ?? $p->room_type, $p->pilgrim->full_name, $p->pilgrim->gender, $p->booking->code];
                    }
                }
            }
        }
        Audit::log('departure.manifest_exported', $dep, null, ['type' => $type, 'rows' => count($data)], null, $tenant->id, 'tenant');
        $name = sprintf('%s-%s-%s.csv', $type, str($dep->package->slug)->limit(30, ''), $dep->departure_date->format('Ymd'));

        $cols = array_map(fn ($h, $i) => ['key' => 'c'.$i, 'label' => $h], $head, array_keys($head));
        $rowsOut = array_map(fn ($r) => array_combine(array_column($cols, 'key'), array_map(fn ($v) => (string) ($v ?? ''), $r)), is_array($data) ? $data : $data->all());

        return ReportService::csv(['columns' => $cols, 'rows' => $rowsOut, 'totals' => []], $name);
    }
}
