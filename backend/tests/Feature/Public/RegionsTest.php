<?php

namespace Tests\Feature\Public;

use App\Models\Pilgrim;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebSession;
use Tests\TestCase;

/** Skala nasional: wilayah administratif resmi (Kepmendagri 2025), alamat berbasis kode, dan filter per lokasi PPIU. */
class RegionsTest extends TestCase
{
    use RefreshDatabase, WebSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        Storage::fake('local');
        config(['permissions.enforce_two_factor' => false]);
    }

    public function test_data_wilayah_lengkap_dan_api_dropdown_bertingkat(): void
    {
        $this->assertSame([1 => 38, 2 => 514, 3 => 7285, 4 => 83762],
            DB::table('regions')->selectRaw('level, count(*) as n')->groupBy('level')->orderBy('level')->pluck('n', 'level')->map(fn ($n) => (int) $n)->all());

        $this->getJson('/api/v1/public/regions')->assertOk()->assertJsonCount(38, 'data')->assertJsonPath('data.0.name', 'Aceh');
        $kota = $this->getJson('/api/v1/public/regions?parent=15')->assertOk()->json('data');
        $this->assertContains(['code' => '15.71', 'name' => 'Kota Jambi'], $kota);
        $this->getJson('/api/v1/public/regions?parent=15.71.01')->assertOk()->assertJsonFragment(['code' => '15.71.01.1001', 'name' => 'Simpang IV Sipin']);
        $this->getJson('/api/v1/public/regions?parent=15.71.01.1001')->assertStatus(422); // kelurahan tidak punya anak
        $this->getJson('/api/v1/public/regions?parent=x')->assertStatus(422);
    }

    public function test_profil_ppiu_menyimpan_kode_wilayah_dan_menolak_kode_palsu(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@ppiu.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $this->actingAs($owner);
        $base = [
            'legal_name' => 'PT Barokah Makassar', 'brand_name' => 'Barokah', 'ppiu_license_no' => 'U.900 Tahun 2025',
            'license_expires_on' => now()->addYear()->toDateString(), 'address' => 'Jl. Perintis No. 9', 'latitude' => -5.1, 'longitude' => 119.5,
            'phone' => '0411 123456', 'email' => 'cs@barokah.test',
        ];
        $this->postJson('/api/v1/tenants', $base)->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['region_code']]]);
        $this->postJson('/api/v1/tenants', $base + ['region_code' => '73.71.99.9999'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['region_code']]]);
        $this->postJson('/api/v1/tenants', $base + ['region_code' => '73.71'])->assertStatus(422);

        $village = DB::table('regions')->where('parent_code', 'like', '73.71.%')->where('level', 4)->orderBy('code')->first();
        $t = $this->postJson('/api/v1/tenants', $base + ['region_code' => $village->code, 'postal_code' => '90111'])->assertCreated()
            ->assertJsonPath('data.city', 'Kota Makassar')->assertJsonPath('data.province', 'Sulawesi Selatan')
            ->assertJsonPath('data.region.village.code', $village->code)->assertJsonPath('data.region.regency.code', '73.71')
            ->assertJsonPath('data.postal_code', '90111')->json('data');
        $this->assertDatabaseHas('tenants', ['id' => $t['id'], 'province_code' => '73', 'regency_code' => '73.71', 'village_code' => $village->code]);
    }

    public function test_cari_paket_dan_ppiu_per_provinsi_dan_kab_kota(): void
    {
        $this->seed(DemoSeeder::class);
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 14)->utc());

        $regions = $this->getJson('/api/v1/public/facets')->assertOk()->json('data.ppiu_regions');
        $this->assertSame(['Jambi', 'Sumatera Selatan'], array_column($regions, 'name'));
        $this->assertSame([['code' => '16.71', 'name' => 'Kota Palembang', 'count' => 1]], $regions[1]['regencies']);

        $palembang = $this->getJson('/api/v1/public/packages?province=16')->assertOk()->json('data');
        $this->assertNotEmpty($palembang);
        $this->assertSame(['ppiu-b-jambi'], array_values(array_unique(array_column(array_column($palembang, 'tenant'), 'slug'))));
        $this->getJson('/api/v1/public/packages?regency=15.71')->assertOk()->assertJsonPath('data.0.tenant.slug', 'ppiu-a-jambi');
        $this->getJson('/api/v1/public/packages?province=31')->assertOk()->assertJsonCount(0, 'data'); // PPIU Jakarta belum terverifikasi
        $this->getJson('/api/v1/public/tenants?province=15')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.regency_code', '15.71');
        $this->getJson('/api/v1/public/packages?province=Jambi')->assertStatus(422);
    }

    public function test_domisili_agen_dan_alamat_jamaah_terenkripsi(): void
    {
        $u = User::create(['name' => 'Rina', 'phone' => '+6281311112222', 'password_hash' => 'rahasia123', 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($u);
        $ktp = $this->postJson('/api/v1/files', ['purpose' => 'ktp', 'file' => \Illuminate\Http\UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf')])->json('data.id');
        $this->postJson('/api/v1/agent/profile', [
            'nik' => '3174010101900001', 'ktp_file_id' => $ktp, 'region_code' => '31.74.01.1001', 'address' => 'Jl. Tebet Barat Dalam No. 3', 'postal_code' => '12810',
            'payout_bank_name' => 'BSI', 'payout_account_no' => '7001234567', 'payout_account_holder' => 'Rina',
        ])->assertCreated()->assertJsonPath('data.domicile_city', 'Kota Administrasi Jakarta Selatan')
            ->assertJsonPath('data.region.district.name', 'Tebet')->assertJsonPath('data.address', 'Jl. Tebet Barat Dalam No. 3')->assertJsonPath('data.postal_code', '12810');
        $this->assertStringNotContainsString('Tebet Barat', (string) DB::table('agents')->where('user_id', $u->id)->value('address_enc'), 'Alamat agen harus terenkripsi');
        // Halaman/kartu agen untuk PPIU & publik tidak memuat alamat jalan.
        $code = DB::table('agents')->where('user_id', $u->id)->value('code');
        $this->assertArrayNotHasKey('address', \App\Support\AgentPresenter::agentRef(\App\Models\Agent::where('code', $code)->first(), true));

        $p = $this->postJson('/api/v1/me/pilgrims', [
            'full_name' => 'Rina Amalia', 'nik' => '3174010101900002', 'birth_place' => 'Jakarta', 'birth_date' => '1990-01-01', 'gender' => 'P',
            'address' => 'Jl. Tebet Raya No. 5', 'region_code' => '31.74.01.1001', 'postal_code' => '12820',
        ])->assertCreated()->assertJsonPath('data.address', 'Jl. Tebet Raya No. 5')->assertJsonPath('data.region.village.name', 'Tebet Timur')->json('data');
        $raw = DB::table('pilgrims')->where('id', $p['id'])->first();
        $this->assertStringNotContainsString('Tebet Raya', $raw->address_enc, 'Baris alamat harus terenkripsi di database');
        $this->assertSame('31.74', $raw->regency_code);
        $this->assertSame('Jl. Tebet Raya No. 5', Pilgrim::find($p['id'])->address_enc);

        $this->postJson('/api/v1/me/pilgrims', [
            'full_name' => 'Tanpa Alamat', 'nik' => '3174010101900003', 'birth_place' => 'Jakarta', 'birth_date' => '1990-01-01', 'gender' => 'L',
        ])->assertCreated()->assertJsonPath('data.region', null); // alamat jamaah opsional
        $this->postJson('/api/v1/me/pilgrims', [
            'full_name' => 'Kode Palsu', 'nik' => '3174010101900004', 'birth_place' => 'Jakarta', 'birth_date' => '1990-01-01', 'gender' => 'L', 'region_code' => '99.99.99.9999',
        ])->assertStatus(422);
    }
}
