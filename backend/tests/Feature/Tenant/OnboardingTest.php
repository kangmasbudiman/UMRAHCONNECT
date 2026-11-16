<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebSession;
use Tests\TestCase;

/** BP-01 Onboarding PPIU: daftar → dokumen → rekening → ajukan → verifikasi platform (exit criteria Sprint 1). */
class OnboardingTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        Storage::fake('local');
        config(['permissions.enforce_two_factor' => false]);
        $this->owner = User::create(['name' => 'Owner Baru', 'email' => 'owner@ppiu-baru.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $this->admin = User::create(['name' => 'Admin Platform', 'email' => 'ops@umrahconnect.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'platform_role' => 'platform_admin', 'email_verified_at' => now()]);
    }

    private function tenantInput(array $over = []): array
    {
        return $over + [
            'legal_name' => 'PT Amanah Baitullah Jambi', 'brand_name' => 'Amanah Baitullah',
            'ppiu_license_no' => 'U.777 Tahun 2025', 'license_expires_on' => now()->addYears(2)->toDateString(),
            'address' => 'Jl. Sultan Thaha No. 1', 'region_code' => '15.71.01.1001', 'postal_code' => '36122', 'latitude' => -1.6125, 'longitude' => 103.593,
            'phone' => '0741 123456', 'whatsapp' => '081277778888', 'email' => 'cs@amanah.test',
        ];
    }

    private function uploadDoc(string $tenantId, string $type): string
    {
        $fileId = $this->postJson('/api/v1/files', ['purpose' => 'tenant_document', 'file' => UploadedFile::fake()->create("$type.pdf", 120, 'application/pdf')])
            ->assertCreated()->json('data.id');

        return $this->postJson("/api/v1/tenants/$tenantId/documents", ['type' => $type, 'file_id' => $fileId])->assertCreated()->json('data.id');
    }

    public function test_alur_lengkap_ppiu_didaftarkan_lalu_diverifikasi_dan_tampil_di_marketplace(): void
    {
        $this->actingAs($this->owner);
        $t = $this->postJson('/api/v1/tenants', $this->tenantInput())->assertCreated()
            ->assertJsonPath('data.verification_status', 'draft')
            ->assertJsonPath('data.slug', 'amanah-baitullah')
            ->assertJsonPath('data.phone', '+62741123456')
            ->assertJsonPath('data.onboarding.can_submit', false)
            ->json('data');

        // Belum lengkap → tidak bisa diajukan
        $this->postJson("/api/v1/tenants/{$t['id']}/verification-submissions")->assertStatus(422)
            ->assertJsonPath('error.code', 'verification_incomplete')
            ->assertJsonPath('error.details.checklist.documents_missing', ['akta', 'nib', 'npwp', 'ppiu_license', 'pic_ktp']);

        foreach (['akta', 'nib', 'npwp', 'ppiu_license', 'pic_ktp'] as $type) {
            $this->uploadDoc($t['id'], $type);
        }
        $bank = $this->postJson("/api/v1/tenants/{$t['id']}/bank-accounts", ['bank_name' => 'BSI', 'account_no' => '7123 4567 89', 'account_holder' => 'PT Amanah Baitullah Jambi'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.account_no', '7123456789')->json('data');

        $this->postJson("/api/v1/tenants/{$t['id']}/verification-submissions")->assertOk()->assertJsonPath('data.verification_status', 'pending_review');
        // Selama ditinjau profil terkunci
        $this->patchJson("/api/v1/tenants/{$t['id']}", ['brand_name' => 'Ganti'])->assertStatus(409);

        // Platform: antrean → verifikasi rekening → putuskan
        $this->actingAs($this->admin);
        $this->getJson('/api/v1/platform/tenants?filter[verification_status]=pending_review')->assertOk()
            ->assertJsonPath('data.0.id', $t['id'])->assertJsonPath('data.0.owner.email', 'owner@ppiu-baru.test');
        $this->postJson("/api/v1/platform/tenants/{$t['id']}/verify", ['decision' => 'verified'])->assertStatus(422)->assertJsonPath('error.code', 'bank_account_unverified');
        $this->postJson("/api/v1/platform/bank-accounts/{$bank['id']}/verify")->assertOk()->assertJsonPath('data.is_default', true);
        $detail = $this->postJson("/api/v1/platform/tenants/{$t['id']}/verify", ['decision' => 'verified'])->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')->json('data');
        $this->assertSame(['accepted'], collect($detail['documents'])->pluck('status')->unique()->values()->all());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $t['id'], 'action' => 'tenant.verification_decided']);

        // BR-01: sekarang tampil di direktori publik
        $this->getJson('/api/v1/public/tenants/amanah-baitullah')->assertOk()->assertJsonPath('data.brand_name', 'Amanah Baitullah');

        // Setelah terverifikasi, data izin terkunci tetapi kontak boleh diubah
        $this->actingAs($this->owner);
        $this->patchJson("/api/v1/tenants/{$t['id']}", ['ppiu_license_no' => 'U.999 Tahun 2026'])->assertStatus(422)->assertJsonPath('error.code', 'license_fields_locked');
        $this->patchJson("/api/v1/tenants/{$t['id']}", ['whatsapp' => '081211112222'])->assertOk()->assertJsonPath('data.whatsapp', '+6281211112222');
    }

    public function test_perlu_revisi_mengembalikan_catatan_dokumen_dan_bisa_diajukan_ulang(): void
    {
        $this->actingAs($this->owner);
        $tid = $this->postJson('/api/v1/tenants', $this->tenantInput())->json('data.id');
        $docs = [];
        foreach (['akta', 'nib', 'npwp', 'ppiu_license', 'pic_ktp'] as $type) {
            $docs[$type] = $this->uploadDoc($tid, $type);
        }
        $this->postJson("/api/v1/tenants/$tid/bank-accounts", ['bank_name' => 'BSI', 'account_no' => '7123456789', 'account_holder' => 'PT Amanah'])->assertCreated();
        $this->postJson("/api/v1/tenants/$tid/verification-submissions")->assertOk();

        $this->actingAs($this->admin);
        $this->postJson("/api/v1/platform/tenants/$tid/verify", ['decision' => 'needs_revision'])->assertStatus(422); // catatan wajib
        $this->postJson("/api/v1/platform/tenants/$tid/verify", [
            'decision' => 'needs_revision', 'note' => 'Foto KTP PIC buram.',
            'document_notes' => [['document_id' => $docs['pic_ktp'], 'status' => 'rejected', 'note' => 'Buram']],
        ])->assertOk()->assertJsonPath('data.verification_status', 'needs_revision')->assertJsonPath('data.status_note', 'Foto KTP PIC buram.');

        $this->actingAs($this->owner);
        $this->getJson("/api/v1/tenants/$tid")->assertJsonPath('data.onboarding.documents_missing', ['pic_ktp']);
        $this->uploadDoc($tid, 'pic_ktp');
        $this->postJson("/api/v1/tenants/$tid/verification-submissions")->assertOk()->assertJsonPath('data.verification_status', 'pending_review');
    }

    public function test_isolasi_tenant_dan_permission_peran(): void
    {
        $tenantA = Tenant::create(['code' => 'AAA01', 'slug' => 'a', 'legal_name' => 'PT A', 'brand_name' => 'A', 'ppiu_license_no' => 'X1', 'license_expires_on' => '2030-01-01', 'verification_status' => 'verified']);
        TenantMember::create(['tenant_id' => $tenantA->id, 'user_id' => $this->owner->id, 'role' => 'owner', 'status' => 'active']);
        $outsider = User::create(['name' => 'Luar', 'email' => 'luar@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $finance = User::create(['name' => 'Fin', 'email' => 'fin@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);

        // Orang luar: 404, bukan 403
        $this->actingAs($outsider)->getJson("/api/v1/tenants/{$tenantA->id}")->assertNotFound();
        $this->getJson('/api/v1/tenants/bukan-uuid')->assertNotFound();
        // Non-platform tidak melihat rute platform
        $this->getJson('/api/v1/platform/tenants')->assertNotFound();

        // Owner mengundang finance; finance menerima lewat /me/invitations
        $this->actingAs($this->owner);
        $this->postJson("/api/v1/tenants/{$tenantA->id}/members/invitations", ['email' => 'belum@ada.test', 'role' => 'admin'])->assertStatus(422)->assertJsonPath('error.code', 'invitee_not_registered');
        $mid = $this->postJson("/api/v1/tenants/{$tenantA->id}/members/invitations", ['email' => 'fin@contoh.test', 'role' => 'finance'])->assertCreated()->json('data.id');
        $this->actingAs($finance)->getJson("/api/v1/tenants/{$tenantA->id}")->assertNotFound(); // belum menerima
        $this->getJson('/api/v1/me/contexts')->assertJsonPath('data.invitations.0.member_id', $mid);
        $this->postJson("/api/v1/me/invitations/$mid/accept")->assertOk()->assertJsonPath('data.status', 'active');

        // Finance boleh lihat rekening, tidak boleh menambah atau mengubah profil
        $this->getJson("/api/v1/tenants/{$tenantA->id}/bank-accounts")->assertOk();
        $this->postJson("/api/v1/tenants/{$tenantA->id}/bank-accounts", ['bank_name' => 'BSI', 'account_no' => '123456789', 'account_holder' => 'X'])->assertForbidden();
        $this->patchJson("/api/v1/tenants/{$tenantA->id}", ['brand_name' => 'X'])->assertForbidden();

        // 2FA wajib untuk Finance saat penegakan aktif
        config(['permissions.enforce_two_factor' => true]);
        $this->getJson("/api/v1/tenants/{$tenantA->id}/bank-accounts")->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
    }

    public function test_daftar_ppiu_butuh_kontak_terverifikasi_dan_file_milik_sendiri(): void
    {
        $unverified = User::create(['name' => 'U', 'email' => 'u@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active']);
        $this->actingAs($unverified)->postJson('/api/v1/tenants', $this->tenantInput())->assertForbidden()->assertJsonPath('error.code', 'verification_required');

        $other = User::create(['name' => 'O', 'email' => 'o@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $fileId = $this->actingAs($other)->postJson('/api/v1/files', ['purpose' => 'tenant_document', 'file' => UploadedFile::fake()->create('akta.pdf', 50, 'application/pdf')])->json('data.id');

        $this->actingAs($this->owner);
        $tid = $this->postJson('/api/v1/tenants', $this->tenantInput())->json('data.id');
        $this->postJson("/api/v1/tenants/$tid/documents", ['type' => 'akta', 'file_id' => $fileId])->assertStatus(422)->assertJsonPath('error.code', 'file_invalid');
        $this->getJson("/api/v1/files/$fileId")->assertNotFound();
        // Tipe file dicek dari isi, bukan nama
        $tmp = tempnam(sys_get_temp_dir(), 'uc');
        file_put_contents($tmp, "MZ\x90\x00 ini program, bukan PDF");
        $this->postJson('/api/v1/files', ['purpose' => 'tenant_document', 'file' => new UploadedFile($tmp, 'palsu.pdf', null, null, true)])
            ->assertStatus(422)->assertJsonValidationErrors(['file'], 'error.errors');
    }
}
