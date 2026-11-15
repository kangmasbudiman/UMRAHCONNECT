<?php

namespace Tests\Feature\Agent;

use App\Models\CommissionScheme;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebSession;
use Tests\TestCase;

/** BP-03/BP-04 (exit criteria Sprint 3): agen mendaftar, bermitra, dan membagikan link yang membawa atribusi. */
class AgentPartnershipTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(10, 0));
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        config(['permissions.enforce_two_factor' => false]);
        $this->a = Tenant::where('slug', 'ppiu-a-jambi')->firstOrFail();
        $this->ownerA = TenantMember::withoutTenantScope()->where('tenant_id', $this->a->id)->where('role', 'owner')->firstOrFail()->user;
    }

    private function terms(): array
    {
        return ['accepted_terms_version' => config('app.legal.partnership_terms_version')];
    }

    private function newAgent(string $name = 'Siti Rahma', string $phone = '+6281311112222', string $nik = '1571010101900001'): User
    {
        $u = User::create(['name' => $name, 'phone' => $phone, 'password_hash' => 'rahasia123', 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($u);
        $ktp = $this->postJson('/api/v1/files', ['purpose' => 'ktp', 'file' => UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf')])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/agent/profile', [
            'nik' => $nik, 'ktp_file_id' => $ktp, 'region_code' => '15.71.02.1002', 'address' => 'Jl. Kapten Pattimura No. 12',
            'payout_bank_name' => 'BSI', 'payout_account_no' => '7001 2345 67', 'payout_account_holder' => $name,
        ])->assertCreated();

        return $u;
    }

    public function test_registrasi_agen_nik_tersamar_dan_unik(): void
    {
        $this->newAgent();
        $this->getJson('/api/v1/agent/profile')->assertOk()
            ->assertJsonPath('data.code', fn ($c) => (bool) preg_match('/^SITI\d{2}$/', $c))
            ->assertJsonPath('data.nik_masked', '1571••••••••0001')
            ->assertJsonPath('data.payout_account_no_masked', '••••••4567');
        $this->getJson('/api/v1/me/contexts')->assertJsonPath('data.agent.active_partnerships', 0);

        // NIK yang sama di akun lain → 409
        $other = User::create(['name' => 'Lain', 'phone' => '+6281399990000', 'password_hash' => 'rahasia123', 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($other);
        $ktp = $this->postJson('/api/v1/files', ['purpose' => 'ktp', 'file' => UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf')])->json('data.id');
        $this->postJson('/api/v1/agent/profile', ['nik' => '1571-0101-0190-0001', 'ktp_file_id' => $ktp, 'region_code' => '15.71.02.1002', 'address' => 'Jl. Kapten Pattimura No. 12', 'payout_bank_name' => 'BSI', 'payout_account_no' => '123456', 'payout_account_holder' => 'Lain'])
            ->assertStatus(409)->assertJsonPath('error.code', 'nik_registered');
        // Belum terdaftar sebagai agen → rute agen ditolak
        $this->getJson('/api/v1/agent/partnerships')->assertForbidden()->assertJsonPath('error.code', 'agent_required');
    }

    public function test_agen_mengajukan_ppiu_menyetujui_dengan_skema_khusus_lalu_link_membawa_atribusi(): void
    {
        $agentUser = $this->newAgent();
        $this->getJson('/api/v1/agent/tenants')->assertOk()->assertJsonCount(2, 'data'); // hanya PPIU terverifikasi
        $pid = $this->postJson('/api/v1/agent/partnerships', ['tenant_id' => $this->a->id, 'message' => 'Saya aktif di pengajian'] + $this->terms())
            ->assertCreated()->assertJsonPath('data.status', 'applied')->assertJsonPath('data.commission_scheme.flat_amount', 1000000)->json('data.id');
        $this->postJson('/api/v1/agent/partnerships', ['tenant_id' => $this->a->id] + $this->terms())->assertStatus(409);
        $this->assertDatabaseHas('consents', ['user_id' => $agentUser->id, 'document' => 'partnership_terms', 'tenant_id' => $this->a->id]);

        // Belum aktif → tidak bisa membuat link
        $this->postJson('/api/v1/agent/referral-links', ['tenant_id' => $this->a->id])->assertForbidden()->assertJsonPath('error.code', 'partnership_inactive');

        // PPIU menyetujui dengan skema khusus 2,5%
        $this->actingAs($this->ownerA);
        $this->getJson("/api/v1/tenants/{$this->a->id}/partnerships?filter[status]=applied")->assertOk()->assertJsonPath('data.0.agent.phone', '+6281311112222');
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/$pid/approve", ['new_scheme' => ['type' => 'percent', 'percent_bp' => 250]])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.commission_scheme.scope', 'agent')
            ->assertJsonPath('data.commission_scheme.label', '2,5% dari harga paket');

        // Agen: paket mitra + komisi, buat link paket (dipakai ulang bila sama), QR PNG
        $this->actingAs($agentUser);
        $pkg = collect($this->getJson('/api/v1/agent/packages')->assertOk()->json('data'))->firstWhere('slug', 'umrah-reguler-12-hari');
        $this->assertSame(250, $pkg['commission_scheme']['percent_bp']);
        $link = $this->postJson('/api/v1/agent/referral-links', ['tenant_id' => $this->a->id, 'package_id' => $pkg['id']])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^http:\/\/localhost:3000\/paket\/ppiu-a-jambi\/umrah-reguler-12-hari\?ref=[A-Z2-9]{6}$/', $link['url']);
        $this->postJson('/api/v1/agent/referral-links', ['tenant_id' => $this->a->id, 'package_id' => $pkg['id']])->assertOk()->assertJsonPath('data.code', $link['code']);
        $qr = $this->get("/api/v1/agent/referral-links/{$link['id']}/qr")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(1024, getimagesizefromstring($qr->getContent())[0]);

        // Atribusi publik: kode menunjuk agen ini, klik tercatat
        $this->getJson("/api/v1/public/referrals/{$link['code']}")->assertOk()->assertJsonPath('data.agent.display_name', 'Siti Rahma');
        $this->postJson("/api/v1/public/referrals/{$link['code']}/clicks")->assertNoContent();

        // Ditangguhkan → link berhenti mengatribusi; diaktifkan lagi → kembali
        $this->actingAs($this->ownerA);
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/$pid/suspend", ['reason' => 'Promosi menyesatkan'])->assertOk();
        $this->getJson("/api/v1/public/referrals/{$link['code']}")->assertNotFound();
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/$pid/reactivate")->assertOk();
        $this->getJson("/api/v1/public/referrals/{$link['code']}")->assertOk();

        // Diakhiri → link nonaktif permanen
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/$pid/end", ['reason' => 'Kontrak selesai'])->assertOk()->assertJsonPath('data.status', 'ended');
        $this->actingAs($agentUser);
        $this->getJson('/api/v1/agent/referral-links')->assertJsonPath('data.0.is_active', false);
        $this->getJson("/api/v1/public/referrals/{$link['code']}")->assertNotFound();
    }

    public function test_ppiu_mengundang_agen_dan_agen_menerima(): void
    {
        $agentUser = $this->newAgent('Rahmat', '+6281355556666', '1571010101900002');
        $this->actingAs($this->ownerA);
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/invitations", ['phone' => '0813 0000 0000'])->assertStatus(422)->assertJsonPath('error.code', 'agent_not_registered');
        $pid = $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/invitations", ['phone' => '0813-5555-6666'])
            ->assertCreated()->assertJsonPath('data.status', 'invited')->json('data.id');

        $this->actingAs($agentUser);
        $this->getJson('/api/v1/agent/partnerships')->assertJsonPath('data.0.status', 'invited');
        $this->postJson("/api/v1/agent/partnerships/$pid/accept", ['accepted_terms_version' => 'lama'])->assertStatus(422);
        $this->postJson("/api/v1/agent/partnerships/$pid/accept", $this->terms())->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/me/contexts')->assertJsonPath('data.agent.active_partnerships', 1);
    }

    public function test_skema_komisi_baru_menutup_yang_lama_dan_prioritas_cr02(): void
    {
        $this->actingAs($this->ownerA);
        $budiId = \App\Models\Agent::where('code', 'BUDI24')->value('id');
        $reg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->value('id');
        $url = "/api/v1/tenants/{$this->a->id}/commission-schemes";

        $this->postJson($url, ['scope' => 'default', 'type' => 'flat', 'flat_amount' => 1500000])->assertCreated();
        $this->assertSame(1, CommissionScheme::withoutTenantScope()->where('tenant_id', $this->a->id)->where('scope', 'default')->whereNull('effective_to')->count());
        $this->postJson($url, ['scope' => 'package', 'package_id' => $reg, 'type' => 'flat', 'flat_amount' => 2000000])->assertCreated();
        $this->postJson($url, ['scope' => 'agent_package', 'agent_id' => $budiId, 'package_id' => $reg, 'type' => 'percent', 'percent_bp' => 300])->assertCreated();
        $this->postJson($url, ['scope' => 'agent', 'agent_id' => \Illuminate\Support\Str::uuid()->toString(), 'type' => 'flat', 'flat_amount' => 1])->assertStatus(422);
        $this->postJson($url, ['scope' => 'default', 'type' => 'percent'])->assertStatus(422)->assertJsonValidationErrors(['percent_bp'], 'error.errors');

        $this->assertSame('agent_package', \App\Support\Commission::effective($this->a->id, $budiId, $reg)->scope);
        $other = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', '!=', 'umrah-reguler-12-hari')->value('id');
        $this->assertSame(1500000, \App\Support\Commission::effective($this->a->id, $budiId, $other)->flat_amount);

        $this->getJson("$url?filter[active]=false")->assertOk()->assertJsonPath('data.0.scope', 'default');
        $defaultId = CommissionScheme::withoutTenantScope()->where('tenant_id', $this->a->id)->where('scope', 'default')->whereNull('effective_to')->value('id');
        $this->postJson("$url/$defaultId/end")->assertStatus(409);
    }

    public function test_persetujuan_butuh_skema_komisi(): void
    {
        $this->newAgent();
        $pid = $this->postJson('/api/v1/agent/partnerships', ['tenant_id' => $this->a->id] + $this->terms())->json('data.id');
        CommissionScheme::withoutTenantScope()->where('tenant_id', $this->a->id)->update(['effective_to' => now()]);
        $this->actingAs($this->ownerA);
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/$pid/approve")->assertStatus(422)->assertJsonPath('error.code', 'scheme_required');
        $this->assertSame('applied', Partnership::withoutTenantScope()->find($pid)->status);
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/$pid/approve", ['new_scheme' => ['type' => 'flat', 'flat_amount' => 750000]])
            ->assertOk()->assertJsonPath('data.commission_scheme.flat_amount', 750000);
    }

    public function test_admin_ppiu_hanya_melihat_dan_tenant_lain_terisolasi(): void
    {
        $admin = User::create(['name' => 'Admin A', 'email' => 'admin@a.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        TenantMember::create(['tenant_id' => $this->a->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $pid = Partnership::withoutTenantScope()->where('tenant_id', $this->a->id)->value('id');
        $this->actingAs($admin);
        $this->getJson("/api/v1/tenants/{$this->a->id}/partnerships")->assertOk()->assertJsonPath('data.0.referral_clicks', 37);
        $this->postJson("/api/v1/tenants/{$this->a->id}/partnerships/$pid/suspend", ['reason' => 'x x x'])->assertForbidden();

        // Owner PPIU B tidak bisa memakai ID kemitraan PPIU A lewat tenant-nya
        $b = Tenant::where('slug', 'ppiu-b-jambi')->first();
        $ownerB = TenantMember::withoutTenantScope()->where('tenant_id', $b->id)->where('role', 'owner')->first()->user;
        $this->actingAs($ownerB)->postJson("/api/v1/tenants/{$b->id}/partnerships/$pid/suspend", ['reason' => 'coba coba'])->assertNotFound();
    }
}
