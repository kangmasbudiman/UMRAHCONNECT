<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Sprint 7: data awal produksi dan pemeriksaan kesiapan go-live. */
class ProductionSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_produksi_hanya_mengisi_pengaturan_dan_tidak_menimpa(): void
    {
        $this->seed(ProductionSeeder::class);
        $this->assertSame(0, User::count());
        $this->assertSame(30, json_decode(DB::table('settings')->where('key', 'attribution.window_days')->value('value')));
        DB::table('settings')->where('key', 'attribution.window_days')->update(['value' => json_encode(14)]);
        $this->seed(ProductionSeeder::class);
        $this->assertSame(14, json_decode(DB::table('settings')->where('key', 'attribution.window_days')->value('value')));
    }

    public function test_buat_super_admin_tanpa_kata_sandi_yang_diketahui(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'Admin@UmrahConnect.id', '--name' => 'Admin Utama'])->assertSuccessful();
        $u = User::where('email', 'admin@umrahconnect.id')->firstOrFail();
        $this->assertSame('super_admin', $u->platform_role);
        $this->assertNotNull($u->email_verified_at);
        $this->assertFalse(Hash::check('', $u->password_hash));
        $this->assertTrue(AuditLog::where('action', 'platform_user.created')->exists());

        $this->artisan('platform:create-admin', ['email' => 'admin@umrahconnect.id'])->assertFailed();
        $this->artisan('platform:create-admin', ['email' => 'bukan-email'])->assertFailed();
        $this->artisan('platform:create-admin', ['email' => 'x@umrahconnect.id', '--role' => 'owner'])->assertFailed();
    }

    public function test_preflight_produksi_menolak_konfigurasi_dev(): void
    {
        config(['app.debug' => true, 'mail.default' => 'log', 'session.secure' => false]);
        $this->artisan('app:preflight', ['--production' => true])->assertFailed();
    }

    public function test_preflight_lolos_bila_konfigurasi_produksi_benar(): void
    {
        $this->seed(ProductionSeeder::class);
        $this->artisan('platform:create-admin', ['email' => 'admin@umrahconnect.id']);
        config(['app.debug' => false, 'app.url' => 'https://api.umrahconnect.id', 'app.web_url' => 'https://umrahconnect.id',
            'app.blind_index_key' => str_repeat('k9', 20), 'permissions.enforce_two_factor' => true, 'session.secure' => true,
            'mail.default' => 'smtp', 'cache.default' => 'database']);
        $this->artisan('app:preflight', ['--production' => true])->assertSuccessful();
    }
}
