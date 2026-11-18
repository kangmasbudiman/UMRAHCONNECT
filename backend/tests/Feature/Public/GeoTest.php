<?php

namespace Tests\Feature\Public;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebSession;
use Tests\TestCase;

/** Titik lokasi PPIU/agen (peta MapLibre & aplikasi ponsel) dan pencarian PPIU terdekat. */
class GeoTest extends TestCase
{
    use RefreshDatabase, WebSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        Storage::fake('local');
        config(['permissions.enforce_two_factor' => false]);
    }

    private function input(array $over = []): array
    {
        return $over + [
            'legal_name' => 'PT Nur Medan', 'brand_name' => 'Nur Medan', 'ppiu_license_no' => 'U.901 Tahun 2025',
            'license_expires_on' => now()->addYear()->toDateString(), 'address' => 'Jl. Gatot Subroto No. 1',
            'region_code' => DB::table('regions')->where('code', 'like', '12.71.%')->where('level', 4)->orderBy('code')->value('code'),
            'phone' => '061 123456', 'email' => 'cs@nur.test',
        ];
    }

    public function test_titik_lokasi_wajib_saat_daftar_dan_harus_di_indonesia(): void
    {
        $this->actingAs(User::create(['name' => 'Owner', 'email' => 'o@nur.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]));

        $this->postJson('/api/v1/tenants', $this->input())->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['latitude', 'longitude']]]);
        $this->postJson('/api/v1/tenants', $this->input(['latitude' => 3.59]))->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['longitude']]]);
        $this->postJson('/api/v1/tenants', $this->input(['latitude' => 40.71, 'longitude' => -74.0]))->assertStatus(422)
            ->assertJsonPath('error.errors.latitude.0', 'Titik lokasi harus berada di wilayah Indonesia. Geser pin ke lokasi yang benar.');

        $t = $this->postJson('/api/v1/tenants', $this->input(['latitude' => '3.5951957', 'longitude' => '98.6722227']))->assertCreated()
            ->assertJsonPath('data.latitude', 3.595196)->assertJsonPath('data.longitude', 98.672223)->json('data');

        // Pindah kantor: titik bisa diubah lewat PATCH.
        $this->patchJson("/api/v1/tenants/{$t['id']}", ['latitude' => 3.6, 'longitude' => 98.68])->assertOk()->assertJsonPath('data.latitude', 3.6);
        $this->patchJson("/api/v1/tenants/{$t['id']}", ['latitude' => 3.6])->assertStatus(422); // harus berpasangan
    }

    public function test_profil_belum_lengkap_tanpa_titik_lokasi(): void
    {
        $this->seed(DemoSeeder::class);
        $t = Tenant::where('slug', 'ppiu-a-jambi')->first();
        $this->assertTrue(\App\Http\Controllers\Api\V1\TenantController::checklist($t)['profile_complete']);
        $t->update(['latitude' => null, 'longitude' => null]);
        $this->assertFalse(\App\Http\Controllers\Api\V1\TenantController::checklist($t->fresh())['profile_complete']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $t->update(['latitude' => 40.7, 'longitude' => -74.0]); // CHECK database ikut menjaga
    }

    public function test_ppiu_terdekat_diurutkan_dengan_jarak_dan_radius(): void
    {
        $this->seed(DemoSeeder::class);
        // Dari pusat Kota Jambi: PPIU A (~1,6 km) masuk; PPIU B di Palembang (~220 km) tidak dalam radius 50 km.
        $res = $this->getJson('/api/v1/public/tenants?near=-1.60,103.60')->assertOk()->json('data');
        $this->assertSame(['ppiu-a-jambi'], array_column($res, 'slug'));
        $this->assertEqualsWithDelta(1.6, $res[0]['distance_km'], 0.3);
        $this->assertSame(-1.6125, $res[0]['latitude']);

        $all = $this->getJson('/api/v1/public/tenants?near=-1.60,103.60&radius=500')->assertOk()->json('data');
        $this->assertSame(['ppiu-a-jambi', 'ppiu-b-jambi'], array_column($all, 'slug'), 'Urut dari yang terdekat; PPIU belum terverifikasi tidak tampil');
        $this->assertGreaterThan(150, $all[1]['distance_km']);

        $this->getJson('/api/v1/public/tenants?near=-2.98,104.75&radius=10')->assertOk()->assertJsonPath('data.0.slug', 'ppiu-b-jambi');
        $this->getJson('/api/v1/public/tenants?near=abc')->assertStatus(422);
        $this->getJson('/api/v1/public/tenants?near=40.7,-74.0')->assertStatus(422);
        $this->getJson('/api/v1/public/tenants?near=-1.6,103.6&radius=9999')->assertStatus(422);
    }

    public function test_titik_domisili_agen_opsional(): void
    {
        $u = User::create(['name' => 'Dewi', 'phone' => '+6281388887777', 'password_hash' => 'rahasia123', 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($u);
        $ktp = $this->postJson('/api/v1/files', ['purpose' => 'ktp', 'file' => \Illuminate\Http\UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf')])->json('data.id');
        $base = ['nik' => '1571010101900055', 'ktp_file_id' => $ktp, 'region_code' => '15.71.02.1002', 'address' => 'Jl. Kol. Abunjani No. 3',
            'payout_bank_name' => 'BSI', 'payout_account_no' => '7001234567', 'payout_account_holder' => 'Dewi'];
        $this->postJson('/api/v1/agent/profile', $base + ['latitude' => 40.7, 'longitude' => -74])->assertStatus(422);
        $this->postJson('/api/v1/agent/profile', $base)->assertCreated()->assertJsonPath('data.latitude', null);
        $this->patchJson('/api/v1/agent/profile', ['latitude' => -1.63, 'longitude' => 103.615])->assertOk()->assertJsonPath('data.longitude', 103.615);
        // Kelurahan + titik dikirim bersamaan: titik tidak boleh hilang.
        $this->patchJson('/api/v1/agent/profile', ['region_code' => '15.71.02.1002', 'latitude' => -1.64, 'longitude' => 103.62])->assertOk()
            ->assertJsonPath('data.latitude', -1.64)->assertJsonPath('data.region.village.code', '15.71.02.1002');
    }
}
