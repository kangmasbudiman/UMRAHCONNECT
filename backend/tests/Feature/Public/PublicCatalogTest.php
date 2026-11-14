<?php

namespace Tests\Feature\Public;

use App\Models\Departure;
use App\Models\ReferralLink;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marketplace publik — Testing Documentation §6 (TC-BP02-01) dan aturan BR-01, BR-07, CR-03.
 * Waktu dibekukan ke 14 Nov 2026 agar keberangkatan contoh selalu "akan datang".
 */
class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(10, 0));
        $this->seed(DemoSeeder::class);
    }

    public function test_katalog_hanya_menampilkan_paket_terbit_dari_ppiu_terverifikasi(): void
    {
        $res = $this->getJson('/api/v1/public/packages')->assertOk();

        $names = collect($res->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Umrah Hemat 10 Hari', 'Umrah Plus Thaif 9 Hari', 'Umrah Ramadhan 15 Hari', 'Umrah Reguler 12 Hari'], $names);
        $this->assertNotContains('Umrah Syawal (draf)', $names);           // draf
        $this->assertNotContains('Umrah Akhir Tahun', $names);             // PPIU C menunggu verifikasi, PPIU D ditangguhkan
        $res->assertJsonPath('meta.next_cursor', null);
    }

    public function test_harga_mulai_dan_keberangkatan_terdekat_benar(): void
    {
        $item = collect($this->getJson('/api/v1/public/packages')->json('data'))->firstWhere('slug', 'umrah-reguler-12-hari');

        $this->assertSame(32500000, $item['price_from_amount']);
        $this->assertSame('2027-02-10', $item['next_departure']['departure_date']);
        $this->assertSame(14, $item['next_departure']['seats_remaining']);
        $this->assertTrue($item['tenant']['verified']);
    }

    public function test_urut_harga_termurah_dan_filter(): void
    {
        $sorted = $this->getJson('/api/v1/public/packages?sort=price')->json('data');
        $this->assertSame('Umrah Hemat 10 Hari', $sorted[0]['name']);           // mulai Rp27.500.000

        $feb = collect($this->getJson('/api/v1/public/packages?month=2027-02')->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Umrah Ramadhan 15 Hari', 'Umrah Reguler 12 Hari'], $feb);

        $cheap = collect($this->getJson('/api/v1/public/packages?price_max=30000000')->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Umrah Hemat 10 Hari', 'Umrah Plus Thaif 9 Hari'], $cheap);

        $q = collect($this->getJson('/api/v1/public/packages?q=ramadhan')->json('data'))->pluck('name')->all();
        $this->assertSame(['Umrah Ramadhan 15 Hari'], $q);
    }

    public function test_paginasi_kursor(): void
    {
        $page1 = $this->getJson('/api/v1/public/packages?limit=3')->assertOk();
        $this->assertCount(3, $page1->json('data'));
        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $page2 = $this->getJson('/api/v1/public/packages?limit=3&cursor='.$cursor)->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertNull($page2->json('meta.next_cursor'));
    }

    public function test_detail_paket_berisi_harga_resmi_per_kamar_dan_sisa_kursi(): void
    {
        Departure::withoutTenantScope()->whereDate('departure_date', '2027-02-10')->update(['seats_held' => 3]);

        $res = $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari')->assertOk();

        $res->assertJsonPath('data.tenant.slug', 'ppiu-a-jambi')
            ->assertJsonPath('data.departures.0.departure_date', '2027-02-10')
            ->assertJsonPath('data.departures.0.seats_remaining', 11)                 // CR-03: 14 − 3
            ->assertJsonPath('data.departures.0.prices.0', ['room_type' => 'quad', 'price_amount' => 32500000])
            ->assertJsonPath('data.departures.0.prices.2', ['room_type' => 'double', 'price_amount' => 38000000])
            ->assertJsonPath('data.departures.1.seats_remaining', 2);
        $this->assertCount(12, $res->json('data.itinerary'));
    }

    public function test_paket_tersembunyi_dijawab_404_dengan_format_error_standar(): void
    {
        $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-syawal-draf')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'errors', 'request_id']]);

        $this->getJson('/api/v1/public/tenants/ppiu-d-jambi')->assertNotFound();      // PPIU ditangguhkan
    }

    public function test_validasi_parameter_memakai_format_error(): void
    {
        $this->getJson('/api/v1/public/packages?month=Februari')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['errors' => ['month']]]);
    }

    public function test_direktori_dan_jadwal_tidak_memuat_ppiu_belum_terverifikasi(): void
    {
        $tenants = collect($this->getJson('/api/v1/public/tenants')->json('data'))->pluck('slug')->all();
        $this->assertSame(['ppiu-a-jambi', 'ppiu-b-jambi'], $tenants);

        $deps = $this->getJson('/api/v1/public/departures')->assertOk()->json('data');
        $this->assertCount(6, $deps);                                     // 2 + 1 + 2 + 1 keberangkatan dari 4 paket terlihat
        $this->assertSame('2027-01-20', $deps[0]['departure_date']);
        $this->assertSame('Umrah Plus Thaif 9 Hari', $deps[0]['package']['name']);
    }

    public function test_referral_aktif_dan_klik_tercatat(): void
    {
        $this->getJson('/api/v1/public/referrals/Q7KX2M')->assertOk()
            ->assertJsonPath('data.agent.code', 'BUDI24')
            ->assertJsonPath('data.agent.display_name', 'Budi')
            ->assertJsonPath('data.package_slug', 'umrah-reguler-12-hari')
            ->assertJsonMissingPath('data.agent.nik_enc');

        $this->postJson('/api/v1/public/referrals/Q7KX2M/clicks')->assertNoContent();
        $this->assertSame(38, ReferralLink::withoutTenantScope()->where('code', 'Q7KX2M')->value('click_count'));
    }

    public function test_referral_dari_kemitraan_ditangguhkan_dijawab_404(): void
    {
        \App\Models\Partnership::withoutTenantScope()->update(['status' => 'suspended']);

        $this->getJson('/api/v1/public/referrals/Q7KX2M')->assertNotFound();
        $this->postJson('/api/v1/public/referrals/Q7KX2M/clicks')->assertNotFound();
    }

    public function test_facets(): void
    {
        $this->getJson('/api/v1/public/facets')->assertOk()
            ->assertJsonPath('data.airlines', ['Garuda Indonesia', 'Lion Air', 'Saudia'])
            ->assertJsonPath('data.departure_cities', ['Jakarta', 'Jambi'])
            ->assertJsonPath('data.price_min_amount', 27500000)
            ->assertJsonPath('data.price_max_amount', 52000000);
    }
}
