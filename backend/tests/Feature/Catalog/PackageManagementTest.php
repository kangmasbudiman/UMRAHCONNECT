<?php

namespace Tests\Feature\Catalog;

use App\Models\Departure;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebSession;
use Tests\TestCase;

/** BP-02 Kelola paket: draf → keberangkatan + harga → terbit → tampil publik (exit criteria Sprint 2). */
class PackageManagementTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $tenant;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(10, 0));
        Storage::fake('public');
        config(['permissions.enforce_two_factor' => false]);
        $this->tenant = Tenant::create(['code' => 'AMN01', 'slug' => 'amanah', 'legal_name' => 'PT Amanah', 'brand_name' => 'Amanah', 'ppiu_license_no' => 'U.1', 'license_expires_on' => '2030-01-01', 'verification_status' => 'verified']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@amanah.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $this->finance = User::create(['name' => 'Fin', 'email' => 'f@amanah.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        TenantMember::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'role' => 'owner', 'status' => 'active']);
        TenantMember::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->finance->id, 'role' => 'finance', 'status' => 'active']);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/tenants/{$this->tenant->id}{$path}";
    }

    private function departureInput(array $over = []): array
    {
        return $over + [
            'departure_date' => '2027-03-01', 'return_date' => '2027-03-12', 'departure_city' => 'Jambi',
            'quota' => 30, 'dp_min_per_pax_amount' => 5000000, 'final_payment_due_on' => '2027-01-30',
            'prices' => [['room_type' => 'quad', 'price_amount' => 31000000], ['room_type' => 'double', 'price_amount' => 36500000]],
        ];
    }

    private function completePackage(): array
    {
        return [
            'description' => 'Umrah 12 hari bersama pembimbing dari Jambi.',
            'hotel_makkah' => 'Hotel Makkah (contoh)', 'hotel_makkah_stars' => 4,
            'hotel_madinah' => 'Hotel Madinah (contoh)', 'hotel_madinah_stars' => 4,
            'itinerary' => [['day' => 1, 'title' => 'Jambi – Jeddah', 'activities' => ['Berkumpul di bandara']]],
            'includes' => ['Tiket pesawat', 'Visa', ' '], 'excludes' => ['Paspor'],
            'payment_terms' => 'DP 48 jam setelah booking.', 'cancellation_terms' => 'Batal > 45 hari: refund 75%.',
        ];
    }

    public function test_paket_dibuat_dilengkapi_diterbitkan_lalu_tampil_di_marketplace(): void
    {
        $this->actingAs($this->owner);
        $res = $this->postJson($this->url('/packages'), ['name' => 'Umrah Berkah 12 Hari', 'duration_days' => 12, 'airline' => 'Saudia'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.slug', 'umrah-berkah-12-hari')
            ->assertJsonPath('data.publish_checklist.complete', false)->assertHeader('ETag');
        $id = $res->json('data.id');
        $etag = $res->headers->get('ETag');

        // Terbit sebelum lengkap → 422 dengan checklist
        $this->postJson($this->url("/packages/$id/publish"))->assertStatus(422)->assertJsonPath('error.code', 'package_incomplete')
            ->assertJsonPath('error.details.checklist.items.departure', false);

        // PATCH butuh If-Match
        $this->patchJson($this->url("/packages/$id"), $this->completePackage())->assertStatus(428);
        $this->withHeader('If-Match', '"basi"')->patchJson($this->url("/packages/$id"), $this->completePackage())->assertStatus(412);
        $res = $this->withHeader('If-Match', $etag)->patchJson($this->url("/packages/$id"), $this->completePackage())->assertOk()
            ->assertJsonPath('data.includes', ['Tiket pesawat', 'Visa']);
        // ETag lama ditolak setelah perubahan
        $this->withHeader('If-Match', $etag)->patchJson($this->url("/packages/$id"), ['name' => 'Ganti nama paket'])->assertStatus(412);
        $this->flushHeaders();
        $this->withHeader('Referer', 'http://localhost:3000/');

        // Keberangkatan: validasi lintas field
        $this->postJson($this->url("/packages/$id/departures"), $this->departureInput(['return_date' => '2027-02-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['return_date'], 'error.errors');
        $this->postJson($this->url("/packages/$id/departures"), $this->departureInput(['dp_min_per_pax_amount' => 40000000]))
            ->assertStatus(422)->assertJsonValidationErrors(['dp_min_per_pax_amount'], 'error.errors');
        $this->postJson($this->url("/packages/$id/departures"), $this->departureInput(['prices' => [['room_type' => 'quad', 'price_amount' => 30000000], ['room_type' => 'quad', 'price_amount' => 31000000]]]))
            ->assertStatus(422);
        $this->postJson($this->url("/packages/$id/departures"), $this->departureInput())->assertCreated()
            ->assertJsonPath('data.seats_remaining', 30)->assertJsonPath('data.dp_deadline_hours', 48)
            ->assertJsonPath('data.prices.1.room_type', 'double');

        // Foto: sampul = foto pertama
        $fileId = $this->postJson('/api/v1/files', ['purpose' => 'package_image', 'file' => UploadedFile::fake()->image('kabah.jpg', 1200, 800)])->assertCreated()->json('data.id');
        $this->postJson($this->url("/packages/$id/images"), ['file_id' => $fileId])->assertCreated()->assertJsonCount(1, 'data.images');

        $this->postJson($this->url("/packages/$id/publish"))->assertOk()->assertJsonPath('data.status', 'published');

        $pub = collect($this->getJson('/api/v1/public/packages')->assertOk()->json('data'))->firstWhere('slug', 'umrah-berkah-12-hari');
        $this->assertSame(31000000, $pub['price_from_amount']);
        $this->assertNotNull($pub['cover_image_url']);
        $this->getJson('/api/v1/public/packages/amanah/umrah-berkah-12-hari')->assertOk()->assertJsonCount(1, 'data.images');

        // Tarik → hilang dari marketplace
        $this->postJson($this->url("/packages/$id/unpublish"))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->getJson('/api/v1/public/packages/amanah/umrah-berkah-12-hari')->assertNotFound();
    }

    public function test_ppiu_belum_terverifikasi_tidak_bisa_menerbitkan(): void
    {
        $this->tenant->update(['verification_status' => 'pending_review']);
        $this->actingAs($this->owner);
        $id = $this->postJson($this->url('/packages'), ['name' => 'Umrah Draf Saja', 'duration_days' => 9, 'airline' => 'Lion Air'])->json('data.id');
        $this->postJson($this->url("/packages/$id/publish"))->assertForbidden()->assertJsonPath('error.code', 'tenant_not_verified');
    }

    public function test_kuota_dan_harga_mengikuti_aturan_kursi_terpakai(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson($this->url('/packages'), ['name' => 'Umrah Kuota Uji', 'duration_days' => 12, 'airline' => 'Saudia'])->json('data.id');
        $dep = $this->postJson($this->url("/packages/$id/departures"), $this->departureInput(['quota' => 10]))->json('data');
        Departure::whereKey($dep['id'])->update(['seats_held' => 4, 'seats_confirmed' => 3]);
        $etag = $this->getJson($this->url("/departures/{$dep['id']}"))->assertJsonPath('data.seats_remaining', 3)->headers->get('ETag');

        $this->withHeader('If-Match', $etag)->patchJson($this->url("/departures/{$dep['id']}"), ['quota' => 6])
            ->assertStatus(409)->assertJsonPath('error.code', 'quota_below_used');
        $etag = $this->withHeader('If-Match', $etag)->patchJson($this->url("/departures/{$dep['id']}"), ['quota' => 7])
            ->assertOk()->assertJsonPath('data.sales_status', 'full')->headers->get('ETag');

        // Ganti harga: baris lama diganti seluruhnya
        $this->withHeader('If-Match', $etag)->putJson($this->url("/departures/{$dep['id']}/prices"), ['prices' => [['room_type' => 'triple', 'price_amount' => 33000000]]])
            ->assertOk()->assertJsonCount(1, 'data.prices')->assertJsonPath('data.active_bookings_count', 0);

        $this->postJson($this->url("/departures/{$dep['id']}/close-sales"))->assertOk()->assertJsonPath('data.sales_status', 'closed');
        $this->postJson($this->url("/departures/{$dep['id']}/open-sales"))->assertOk()->assertJsonPath('data.sales_status', 'full');
        $this->postJson($this->url("/departures/{$dep['id']}/cancel"), ['reason' => 'Maskapai membatalkan penerbangan'])
            ->assertOk()->assertJsonPath('meta.departure.status', 'cancelled');
        $this->postJson($this->url("/departures/{$dep['id']}/close-sales"))->assertStatus(409);
    }

    public function test_finance_hanya_melihat_dan_tenant_lain_tidak_bisa_mengakses(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson($this->url('/packages'), ['name' => 'Umrah Rahasia Tenant', 'duration_days' => 9, 'airline' => 'Garuda'])->json('data.id');

        $this->actingAs($this->finance);
        $this->getJson($this->url('/packages'))->assertOk()->assertJsonPath('meta.counts.draft', 1);
        $this->postJson($this->url('/packages'), ['name' => 'Tidak boleh', 'duration_days' => 9, 'airline' => 'X'])->assertForbidden();
        $this->postJson($this->url("/packages/$id/publish"))->assertForbidden();

        // Owner tenant lain menebak ID paket lewat tenant-nya sendiri → 404
        $other = Tenant::create(['code' => 'LAIN1', 'slug' => 'lain', 'legal_name' => 'PT Lain', 'brand_name' => 'Lain', 'ppiu_license_no' => 'U.2', 'license_expires_on' => '2030-01-01', 'verification_status' => 'verified']);
        $otherOwner = User::create(['name' => 'Lain', 'email' => 'l@lain.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        TenantMember::create(['tenant_id' => $other->id, 'user_id' => $otherOwner->id, 'role' => 'owner', 'status' => 'active']);
        $this->actingAs($otherOwner)->getJson("/api/v1/tenants/{$other->id}/packages/$id")->assertNotFound();
        $this->getJson("/api/v1/tenants/{$other->id}/packages")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_salin_dan_arsip(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson($this->url('/packages'), ['name' => 'Umrah Asli 9 Hari', 'duration_days' => 9, 'airline' => 'Saudia'] + $this->completePackage())->json('data.id');
        $this->postJson($this->url("/packages/$id/duplicate"))->assertCreated()
            ->assertJsonPath('data.name', 'Umrah Asli 9 Hari (salinan)')->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.cancellation_terms', 'Batal > 45 hari: refund 75%.');
        $this->postJson($this->url("/packages/$id/archive"))->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson($this->url("/packages/$id/departures"), $this->departureInput())->assertStatus(409);
    }
}
