<?php

namespace Tests\Feature\Trust;

use App\Models\Agent;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WebSession;
use Tests\TestCase;

/** Fitur unggulan pembeda: Lacak Umrah, ulasan terverifikasi, skor transparansi + ramah lansia, rencana tabungan. */
class TrustFeaturesTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private User $owner;

    private User $finance;

    private User $jamaah;

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
        $this->jamaah = User::create(['name' => 'Hasan Basri', 'email' => 'hasan@contoh.test', 'phone' => '+6281234567890', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
    }

    private function pkg(): Package
    {
        return Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->firstOrFail();
    }

    private function dep(): Departure
    {
        return Departure::withoutTenantScope()->where('package_id', $this->pkg()->id)->where('departure_date', '2027-02-10')->firstOrFail();
    }

    private function book(): array
    {
        return $this->actingAs($this->jamaah)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/bookings', [
            'departure_id' => $this->dep()->id, 'accept_package_terms' => true,
            'participants' => [['room_type' => 'quad', 'pilgrim' => ['full_name' => 'Hasan Basri', 'nik' => '1571011705750001', 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => 'L']]],
        ])->assertCreated()->json('data');
    }

    // ---------- 1. Lacak Umrah ----------

    public function test_lacak_umrah_tahap_diperbarui_ppiu_dan_bisa_dilacak_publik(): void
    {
        $b = $this->book();
        $this->assertSame('booked', $b['journey']['steps'][0]['key']);
        $this->assertSame('current', collect($b['journey']['steps'])->firstWhere('key', 'dp')['status']);

        $url = "/api/v1/tenants/{$this->a->id}/departures/{$this->dep()->id}/milestones";
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonCount(5, 'data.milestones')->assertJsonPath('data.active_bookings', 1);
        $this->actingAs($this->finance)->putJson("$url/visa_issued", ['status' => 'done'])->assertForbidden();
        $this->actingAs($this->owner)->putJson("$url/manasik", ['status' => 'done'])->assertStatus(422);
        $this->putJson("$url/manasik", ['status' => 'done', 'happened_on' => '2027-01-20', 'note' => 'Masjid Agung Al-Falah Jambi, 08.00 WIB'])->assertOk()
            ->assertJsonPath('data.milestones.4.status', 'done');
        $this->putJson("$url/tidak_ada", ['status' => 'done'])->assertNotFound();

        $this->assertTrue(Notification::where('user_id', $this->jamaah->id)->where('event', 'departure.milestone')->where('channel', 'in_app')->exists());
        $steps = collect($this->actingAs($this->jamaah)->getJson("/api/v1/me/bookings/{$b['id']}")->assertOk()->json('data.journey.steps'));
        $this->assertSame(['done', '2027-01-20'], [$steps->firstWhere('key', 'manasik')['status'], $steps->firstWhere('key', 'manasik')['date']]);

        // Pelacakan publik: kode + 4 digit akhir HP; tanpa nama jamaah.
        $res = $this->getJson("/api/v1/public/track?code={$b['code']}&phone=7890")->assertOk()->assertJsonPath('data.code', $b['code']);
        $this->assertStringNotContainsString('Hasan', $res->getContent());
        $this->assertStringNotContainsString('1571011705750001', $res->getContent());
        $this->getJson("/api/v1/public/track?code={$b['code']}&phone=0000")->assertNotFound();
        $this->getJson('/api/v1/public/track?code=UC-0000-0000&phone=7890')->assertNotFound();
    }

    // ---------- 2. Ulasan terverifikasi ----------

    public function test_ulasan_hanya_dari_jamaah_yang_sudah_berangkat(): void
    {
        $b = $this->book();
        $this->actingAs($this->jamaah)->postJson("/api/v1/me/bookings/{$b['id']}/review", ['rating' => 5, 'body' => 'Pelayanan sangat baik dan muthawif sabar.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'review_not_eligible');
        $this->getJson("/api/v1/me/bookings/{$b['id']}")->assertJsonPath('data.can_review', false);

        Booking::withoutTenantScope()->whereKey($b['id'])->update(['status' => 'departed']);
        $this->getJson("/api/v1/me/bookings/{$b['id']}")->assertJsonPath('data.can_review', true);
        $this->postJson("/api/v1/me/bookings/{$b['id']}/review", ['rating' => 5, 'body' => 'terlalu pendek'])->assertStatus(422);
        $id = $this->postJson("/api/v1/me/bookings/{$b['id']}/review", ['rating' => 4, 'body' => 'Hotel dekat masjid, muthawif sabar membimbing orang tua kami.'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/me/bookings/{$b['id']}/review", ['rating' => 5, 'body' => 'Ulasan kedua untuk booking yang sama.'])->assertStatus(409);

        $other = User::create(['name' => 'Orang Lain', 'email' => 'lain@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->actingAs($other)->postJson("/api/v1/me/bookings/{$b['id']}/review", ['rating' => 1, 'body' => 'Mencoba menulis ulasan booking orang.'])->assertNotFound();

        $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari/reviews')->assertOk()
            ->assertJsonPath('data.0.author', 'Hasan B.')->assertJsonPath('data.0.verified_departure.departure_date', '2027-02-10')
            ->assertJsonPath('meta.summary.avg', 4)->assertJsonPath('meta.summary.count', 1);
        $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari')->assertJsonPath('data.rating.count', 1)->assertJsonPath('data.tenant.rating.avg', 4);

        $this->actingAs($this->owner)->getJson("/api/v1/tenants/{$this->a->id}/reviews")->assertOk()->assertJsonPath('data.0.booking_code', $b['code']);
        $this->actingAs($this->finance)->postJson("/api/v1/tenants/{$this->a->id}/reviews/$id/reply", ['reply' => 'Terima kasih Pak Hasan.'])->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/v1/tenants/{$this->a->id}/reviews/$id/reply", ['reply' => 'Terima kasih Pak Hasan, sampai jumpa di perjalanan berikutnya.'])->assertOk();
        $this->assertTrue(Notification::where('user_id', $this->jamaah->id)->where('event', 'review.replied')->exists());

        $super = User::where('email', 'superadmin@umrahconnect.test')->first();
        $this->actingAs($super)->postJson("/api/v1/platform/reviews/$id/hide", ['reason' => 'Memuat nomor telepon pribadi'])->assertOk()->assertJsonPath('data.status', 'hidden');
        $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari/reviews')->assertJsonCount(0, 'data');
    }

    // ---------- 3. Transparansi + ramah lansia ----------

    public function test_skor_transparansi_dan_filter_ramah_lansia(): void
    {
        $d = $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari')->assertOk()->json('data');
        $this->assertSame(95, $d['transparency']['score']);
        $this->assertSame(['photos'], collect($d['transparency']['items'])->where('ok', false)->pluck('key')->all());
        $this->assertFalse($d['elderly_friendly']);
        // Paket Ramadhan 15 hari hanya punya 12 hari rencana perjalanan → poin rencana perjalanan hilang.
        $this->assertSame(80, $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-ramadhan-15-hari')->json('data.transparency.score'));

        // Data contoh: hanya paket Ramadhan yang kedua hotelnya ≤ 300 m (150 m / 100 m).
        $this->assertSame(['umrah-ramadhan-15-hari'], collect($this->getJson('/api/v1/public/packages?elderly=1')->assertOk()->json('data'))->pluck('slug')->all());
        $this->pkg()->update(['hotel_makkah_distance_m' => 150, 'hotel_madinah_distance_m' => 250]);
        $rows = collect($this->getJson('/api/v1/public/packages?elderly=1')->assertOk()->json('data'))->keyBy('slug');
        $this->assertEqualsCanonicalizing(['umrah-ramadhan-15-hari', 'umrah-reguler-12-hari'], $rows->keys()->all());
        $this->assertTrue($rows['umrah-reguler-12-hari']['elderly_friendly']);
        $this->assertSame(95, $rows['umrah-reguler-12-hari']['transparency_score']);
    }

    // ---------- 4. Rencana tabungan ----------

    public function test_rencana_tabungan_setoran_dan_pengingat(): void
    {
        $this->actingAs($this->jamaah);
        $plan = $this->postJson('/api/v1/me/savings-plans', ['package_id' => $this->pkg()->id, 'persons' => 2, 'target_date' => '2027-10-01', 'initial_amount' => 5000000, 'reminder_day' => 14])
            ->assertCreated()->json('data');
        $this->assertSame([65000000, 5000000, 'Umrah Reguler 12 Hari'], [$plan['target_amount'], $plan['saved_amount'], $plan['label']]);
        $this->assertSame(11, $plan['months_left']);
        $this->assertSame(5455000, $plan['monthly_amount']); // (65 jt − 5 jt) / 11 bulan, dibulatkan ke atas per seribu

        $this->postJson('/api/v1/me/savings-plans', ['persons' => 1, 'target_date' => '2027-10-01'])->assertStatus(422);
        $this->postJson('/api/v1/me/savings-plans', ['persons' => 1, 'target_date' => '2026-01-01', 'target_amount' => 30000000])->assertStatus(422);

        $this->postJson("/api/v1/me/savings-plans/{$plan['id']}/entries", ['amount' => 2000000, 'note' => 'Gaji November'])->assertCreated()->assertJsonPath('data.saved_amount', 7000000);
        $this->postJson("/api/v1/me/savings-plans/{$plan['id']}/entries", ['amount' => -9000000])->assertStatus(422);
        $this->getJson('/api/v1/me/savings-plans')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.entries');

        $other = User::create(['name' => 'Orang Lain', 'email' => 'lain@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->actingAs($other)->postJson("/api/v1/me/savings-plans/{$plan['id']}/entries", ['amount' => 1000])->assertNotFound();

        $this->artisan('jobs:run', ['job' => 'savings-reminders'])->assertSuccessful();
        $this->assertTrue(Notification::where('user_id', $this->jamaah->id)->where('event', 'savings.reminder')->where('channel', 'in_app')->exists());

        $this->actingAs($this->jamaah)->postJson("/api/v1/me/savings-plans/{$plan['id']}/entries", ['amount' => 58000000])->assertCreated()->assertJsonPath('data.status', 'reached');
        $this->deleteJson("/api/v1/me/savings-plans/{$plan['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('savings_entries')->count());
    }
}
