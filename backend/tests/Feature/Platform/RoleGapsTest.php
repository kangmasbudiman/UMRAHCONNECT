<?php

namespace Tests\Feature\Platform;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Prospect;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WebSession;
use Tests\TestCase;

/**
 * Kekurangan per peran yang ditutup setelah Sprint 7:
 * pengalihan kepemilikan (Owner), prospek (Agen), moderasi paket dan kelola akun (Admin Platform),
 * kelola staf dan pengaturan platform (Super Admin).
 */
class RoleGapsTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private User $owner;

    private User $finance;

    private User $super;

    private User $padmin;

    private Agent $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 14)->setTime(10, 0)->utc());
        $this->seed(DemoSeeder::class);
        config(['permissions.enforce_two_factor' => false]);
        $this->a = Tenant::where('slug', 'ppiu-a-jambi')->firstOrFail();
        $this->owner = TenantMember::withoutTenantScope()->where('tenant_id', $this->a->id)->where('role', 'owner')->first()->user;
        $this->finance = User::where('email', 'ppiua.finance@umrahconnect.test')->firstOrFail();
        $this->super = User::where('email', 'superadmin@umrahconnect.test')->firstOrFail();
        $this->padmin = User::create(['name' => 'Admin Platform', 'email' => 'padmin@contoh.test', 'password_hash' => 'rahasia123', 'platform_role' => 'platform_admin', 'email_verified_at' => now()]);
        $this->budi = Agent::where('code', 'BUDI24')->firstOrFail();
    }

    private function enable2fa(User $u): string
    {
        $secret = (new Google2FA)->generateSecretKey();
        $u->forceFill(['two_factor_secret_enc' => $secret, 'two_factor_enabled' => true])->save();

        return $secret;
    }

    // ---------- 1. Pengalihan kepemilikan ----------

    public function test_owner_mengalihkan_kepemilikan_dengan_kata_sandi_dan_2fa(): void
    {
        $url = "/api/v1/tenants/{$this->a->id}/ownership-transfer";
        $fm = TenantMember::withoutTenantScope()->where('user_id', $this->finance->id)->first();

        $this->actingAs($this->finance)->postJson($url, ['member_id' => $fm->id, 'password' => 'x', 'two_factor_code' => '000000'])->assertForbidden();

        $this->actingAs($this->owner)->postJson($url, ['member_id' => $fm->id, 'password' => 'test-password', 'two_factor_code' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.code', 'two_factor_not_enabled');
        $secret = $this->enable2fa($this->owner);
        $otp = fn () => (new Google2FA)->getCurrentOtp($secret);
        $this->postJson($url, ['member_id' => $fm->id, 'password' => 'salah', 'two_factor_code' => $otp()])->assertStatus(422)->assertJsonPath('error.code', 'password_invalid');
        $this->postJson($url, ['member_id' => $fm->id, 'password' => 'test-password', 'two_factor_code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'two_factor_invalid');
        $ownerMember = TenantMember::withoutTenantScope()->where('user_id', $this->owner->id)->first();
        $this->postJson($url, ['member_id' => $ownerMember->id, 'password' => 'test-password', 'two_factor_code' => $otp()])->assertStatus(422)->assertJsonPath('error.code', 'member_not_eligible');

        $this->travel(61)->seconds(); // batas 5 percobaan/menit (throttle:reauth)
        $this->postJson($url, ['member_id' => $fm->id, 'password' => 'test-password', 'two_factor_code' => $otp()])->assertOk()
            ->assertJsonPath('data.0.role', 'owner')->assertJsonPath('data.0.user.id', $this->finance->id);
        $this->assertSame('admin', $ownerMember->fresh()->role);
        $this->assertSame('owner', $fm->fresh()->role);
        $this->assertTrue(AuditLog::where('action', 'tenant.ownership_transferred')->where('tenant_id', $this->a->id)->exists());
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->finance->id)->where('event', 'tenant.ownership_received')->exists());

        // Owner lama kini Admin: tidak bisa lagi mengalihkan atau mengelola tim.
        $this->travel(61)->seconds();
        $this->postJson($url, ['member_id' => $fm->id, 'password' => 'test-password', 'two_factor_code' => $otp()])->assertForbidden();
        $this->actingAs($this->finance)->getJson("/api/v1/tenants/{$this->a->id}/bank-accounts")->assertOk();
    }

    // ---------- 2. Prospek agen ----------

    public function test_agen_mengelola_prospek_miliknya_sendiri(): void
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();
        $this->actingAs($this->budi->user);
        $id = $this->postJson('/api/v1/agent/prospects', ['name' => 'Pak Hasan', 'phone' => '0812-3456-7890', 'tenant_id' => $this->a->id, 'package_id' => $pkg->id,
            'next_follow_up_on' => '2026-11-14', 'notes' => 'Minta brosur'])->assertCreated()
            ->assertJsonPath('data.phone', '+6281234567890')->assertJsonPath('data.follow_up_status', 'new')->assertJsonPath('data.package.name', 'Umrah Reguler 12 Hari')->json('data.id');
        $this->postJson('/api/v1/agent/prospects', ['name' => 'Bu Ani', 'phone' => '081200000001'])->assertCreated();
        $this->postJson('/api/v1/agent/prospects', ['name' => 'X', 'phone' => '1'])->assertStatus(422);

        // PPIU bukan mitra / paket tidak cocok ditolak
        $c = Tenant::where('slug', 'ppiu-c-jambi')->first();
        $this->postJson('/api/v1/agent/prospects', ['name' => 'Pak Udin', 'phone' => '081200000002', 'tenant_id' => $c->id])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['tenant_id']]]);
        $bPkg = Package::withoutTenantScope()->where('slug', 'umrah-hemat-10-hari')->first();
        $this->postJson('/api/v1/agent/prospects', ['name' => 'Pak Udin', 'phone' => '081200000002', 'tenant_id' => $this->a->id, 'package_id' => $bPkg->id])->assertStatus(422);

        $this->getJson('/api/v1/agent/prospects')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Pak Hasan')->assertJsonPath('meta.due', 1)->assertJsonPath('meta.counts.new', 2);
        $this->patchJson("/api/v1/agent/prospects/$id", ['follow_up_status' => 'interested', 'next_follow_up_on' => null])->assertOk()->assertJsonPath('data.follow_up_status', 'interested');
        $this->getJson('/api/v1/agent/prospects?filter[follow_up_status]=interested')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/agent/prospects?q=hasan')->assertJsonCount(1, 'data');

        // Agen lain tidak bisa melihat/mengubah
        $siti = User::create(['name' => 'Siti', 'email' => 'siti@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        Agent::create(['user_id' => $siti->id, 'code' => 'SITI77', 'nik_enc' => '9999010101900077', 'nik_hash' => Agent::nikHash('9999010101900077'), 'domicile_city' => 'Jambi']);
        $this->actingAs($siti)->getJson('/api/v1/agent/prospects')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($siti)->patchJson("/api/v1/agent/prospects/$id", ['name' => 'Diubah'])->assertNotFound();
        $this->actingAs($siti)->deleteJson("/api/v1/agent/prospects/$id")->assertNotFound();

        $this->actingAs($this->budi->user)->deleteJson("/api/v1/agent/prospects/$id")->assertNoContent();
        $this->assertSame(1, Prospect::count());
    }

    // ---------- 3. Moderasi paket ----------

    public function test_admin_platform_menurunkan_dan_mengizinkan_paket_terbit_lagi(): void
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();
        $this->actingAs($this->owner)->postJson("/api/v1/platform/packages/{$pkg->id}/take-down", ['reason' => 'Coba dari PPIU'])->assertNotFound();

        $this->actingAs($this->padmin);
        $this->getJson('/api/v1/platform/packages?q=reguler')->assertOk()->assertJsonPath('data.0.id', $pkg->id);
        $this->postJson("/api/v1/platform/packages/{$pkg->id}/take-down", ['reason' => 'pendek'])->assertStatus(422);
        $this->postJson("/api/v1/platform/packages/{$pkg->id}/take-down", ['reason' => 'Foto hotel tidak sesuai dengan hotel yang dijual'])->assertOk()->assertJsonPath('data.status', 'taken_down');
        $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari')->assertNotFound();
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->owner->id)->where('event', 'package.taken_down')->exists());
        $this->assertTrue(AuditLog::where('action', 'package.taken_down')->exists());

        $this->actingAs($this->owner)->postJson("/api/v1/tenants/{$this->a->id}/packages/{$pkg->id}/publish")->assertStatus(409);

        $this->actingAs($this->padmin)->postJson("/api/v1/platform/packages/{$pkg->id}/restore", ['reason' => 'Foto sudah diganti'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->actingAs($this->owner)->postJson("/api/v1/tenants/{$this->a->id}/packages/{$pkg->id}/publish")->assertOk();
        $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari')->assertOk();
    }

    // ---------- 4. Kelola akun ----------

    public function test_admin_platform_menonaktifkan_akun_jamaah_dan_agen_saja(): void
    {
        $jamaah = User::create(['name' => 'Hasan Jamaah', 'email' => 'hasan@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->actingAs($this->padmin);
        $this->getJson('/api/v1/platform/users?q=hasan')->assertOk()->assertJsonPath('data.0.id', $jamaah->id);
        $this->getJson('/api/v1/platform/users?q=budi')->assertOk()->assertJsonPath('data.0.agent.code', 'BUDI24');
        $this->getJson('/api/v1/platform/users?q=finance')->assertOk()->assertJsonPath('data.0.memberships.0.role', 'finance');

        $this->postJson("/api/v1/platform/users/{$jamaah->id}/deactivate", ['reason' => 'Laporan penipuan berulang'])->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->postJson("/api/v1/platform/users/{$this->finance->id}/deactivate", ['reason' => 'Coba akun staf PPIU'])->assertForbidden();
        $this->postJson("/api/v1/platform/users/{$this->super->id}/deactivate", ['reason' => 'Coba super admin'])->assertForbidden();
        $this->postJson("/api/v1/platform/users/{$this->padmin->id}/deactivate", ['reason' => 'Coba akun sendiri'])->assertStatus(422);
        $this->assertTrue(AuditLog::where('action', 'user.deactivated')->where('entity_id', $jamaah->id)->exists());

        $this->postJson("/api/v1/platform/users/{$jamaah->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

        // Super Admin boleh menonaktifkan staf PPIU.
        $this->actingAs($this->super)->postJson("/api/v1/platform/users/{$this->finance->id}/deactivate", ['reason' => 'Karyawan keluar, akun bocor'])->assertOk();
        // Pengguna biasa tidak tahu route ini ada.
        $this->actingAs($jamaah)->getJson('/api/v1/platform/users')->assertNotFound();

        // Akun nonaktif tidak bisa masuk.
        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['identifier' => 'ppiua.finance@umrahconnect.test', 'password' => 'test-password'])->assertStatus(403)->assertJsonPath('error.code', 'account_deactivated');
    }

    // ---------- 5. Staf dan pengaturan platform ----------

    public function test_super_admin_mengelola_staf_platform(): void
    {
        $calon = User::create(['name' => 'Calon Staf', 'email' => 'calon@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->actingAs($this->padmin)->getJson('/api/v1/platform/admins')->assertForbidden();

        $this->actingAs($this->super);
        $this->getJson('/api/v1/platform/admins')->assertOk()->assertJsonPath('data.0.platform_role', 'super_admin');
        $this->postJson('/api/v1/platform/admins', ['user_id' => $calon->id])->assertCreated()->assertJsonPath('data.platform_role', 'platform_admin');
        $this->postJson('/api/v1/platform/admins', ['user_id' => $calon->id])->assertStatus(409);
        $tanpaEmail = User::create(['name' => 'HP saja', 'phone' => '+6281200009999', 'password_hash' => 'rahasia123', 'phone_verified_at' => now()]);
        $this->postJson('/api/v1/platform/admins', ['user_id' => $tanpaEmail->id])->assertStatus(422);

        $this->deleteJson("/api/v1/platform/admins/{$calon->id}")->assertNoContent();
        $this->assertNull($calon->fresh()->platform_role);
        $this->deleteJson("/api/v1/platform/admins/{$this->super->id}")->assertStatus(422);
        $this->assertSame(2, AuditLog::whereIn('action', ['platform_user.added', 'platform_user.removed'])->count());
    }

    public function test_super_admin_mengubah_pengaturan_dan_dipakai_sistem(): void
    {
        $this->seed(ProductionSeeder::class);
        $this->actingAs($this->padmin)->getJson('/api/v1/platform/settings')->assertForbidden();

        $this->actingAs($this->super);
        $this->getJson('/api/v1/platform/settings')->assertOk()->assertJsonCount(count(Settings::EDITABLE), 'data');
        $this->putJson('/api/v1/platform/settings/attribution.window_days', ['value' => 200])->assertStatus(422);
        $this->putJson('/api/v1/platform/settings/tidak.ada', ['value' => 1])->assertNotFound();
        $this->putJson('/api/v1/platform/settings/attribution.window_days', ['value' => 14])->assertOk()->assertJsonPath('data.value', 14)->assertJsonPath('data.updated_by', 'Super Admin');
        $this->putJson('/api/v1/platform/settings/support_access.max_minutes', ['value' => 30])->assertOk();
        $this->putJson('/api/v1/platform/settings/commission.earn_on_default', ['value' => 'dp_verified'])->assertOk();
        $this->assertTrue(AuditLog::where('action', 'setting.updated')->exists());

        $this->getJson('/api/v1/public/config')->assertOk()->assertJsonPath('data.attribution_window_days', 14);
        $s = $this->postJson('/api/v1/platform/support-sessions', ['tenant_id' => $this->a->id, 'ticket_no' => 'T-9', 'reason' => 'Cek pengaturan durasi'])->assertCreated()->json('data');
        $this->assertSame(30, (int) round(\Illuminate\Support\Carbon::parse($s['started_at'])->diffInMinutes(\Illuminate\Support\Carbon::parse($s['expires_at']))));
    }
}
