<?php

namespace Tests\Feature\Booking;

use App\Models\Agent;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\WebSession;
use Tests\TestCase;

/** BP-05 Booking — CR-01 atribusi, CR-03 kursi, CR-04 invoice, JOB-01 kedaluwarsa (exit criteria Sprint 4). */
class BookingTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private User $jamaah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 14)->setTime(10, 0)->utc());
        $this->seed(DemoSeeder::class);
        config(['permissions.enforce_two_factor' => false]);
        $this->a = Tenant::where('slug', 'ppiu-a-jambi')->firstOrFail();
        $this->jamaah = User::create(['name' => 'Hasan Basri', 'email' => 'hasan@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
    }

    private function dep(string $date): Departure
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();

        return Departure::withoutTenantScope()->where('package_id', $pkg->id)->where('departure_date', $date)->firstOrFail();
    }

    private function pilgrim(string $name, string $nik, string $gender = 'L'): array
    {
        return ['full_name' => $name, 'nik' => $nik, 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => $gender];
    }

    private function book(array $body, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/api/v1/me/bookings', $body + ['accept_package_terms' => true]);
    }

    public function test_booking_marketplace_menahan_kursi_dan_menerbitkan_invoice_dp_dan_pelunasan(): void
    {
        $dep = $this->dep('2027-02-10');
        $this->actingAs($this->jamaah);
        $res = $this->book(['departure_id' => $dep->id, 'participants' => [
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Hasan Basri', '1571011705750001')],
            ['room_type' => 'double', 'pilgrim' => $this->pilgrim('Siti Aisyah', '1571015505780002', 'P')],
        ]])->assertCreated();

        $res->assertJsonPath('data.status', 'awaiting_dp')
            ->assertJsonPath('data.total_amount', 32500000 + 38000000)
            ->assertJsonPath('data.dp_amount', 10000000)
            ->assertJsonPath('data.attribution_source', 'none')
            ->assertJsonPath('data.channel', 'marketplace')
            ->assertJsonPath('data.invoices.0.type', 'dp')
            ->assertJsonPath('data.invoices.0.amount', 10000000)
            ->assertJsonPath('data.invoices.1.type', 'final')
            ->assertJsonPath('data.invoices.1.amount', 60500000)
            ->assertJsonPath('data.pay_to.bank_name', 'Bank Syariah Indonesia')
            ->assertJsonPath('data.participants.1.full_name', 'Siti Aisyah');
        $this->assertMatchesRegularExpression('/^UC-2611-\d{4}$/', $res->json('data.code'));
        $this->assertMatchesRegularExpression('#^INV/PPIUA/2026/\d{4}$#', $res->json('data.invoices.0.number'));
        $this->assertSame('2026-11-16T10:00:00+07:00', \Illuminate\Support\Carbon::parse($res->json('data.hold_expires_at'))->setTimezone('Asia/Jakarta')->toIso8601String());
        $this->assertSame(2, $dep->fresh()->seats_held);
        // Harga dari server, data jamaah tersimpan dan NIK tersamar
        $this->getJson('/api/v1/me/pilgrims')->assertJsonCount(2, 'data')->assertJsonPath('data.0.nik_masked', '1571••••••••0001');
        $this->getJson('/api/v1/public/packages/ppiu-a-jambi/umrah-reguler-12-hari')->assertJsonPath('data.departures.0.seats_remaining', 12);

        // Jamaah yang sama tidak bisa dibooking dua kali di keberangkatan yang sama
        $pid = $res->json('data.participants.0.pilgrim_id');
        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim_id' => $pid]]])
            ->assertStatus(409)->assertJsonPath('error.code', 'pilgrim_already_booked');
    }

    public function test_kursi_terakhir_dan_kursi_kurang(): void
    {
        $dep = $this->dep('2027-03-10'); // kuota 2
        $this->actingAs($this->jamaah);
        $this->book(['departure_id' => $dep->id, 'participants' => [
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Satu', '1571010101800001')],
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Dua', '1571010101800002')],
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Tiga', '1571010101800003')],
        ]])->assertStatus(409)->assertJsonPath('error.code', 'seats_unavailable')->assertJsonPath('error.details.seats_remaining', 2);

        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Satu', '1571010101800001')]]])->assertCreated();
        $other = User::create(['name' => 'Lain', 'email' => 'lain@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $this->actingAs($other);
        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('B Satu', '1571010101800009')]]])->assertCreated();
        $this->assertSame('full', $dep->fresh()->sales_status);
        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('B Dua', '1571010101800010')]]])
            ->assertStatus(409)->assertJsonPath('error.details.seats_remaining', 0);
    }

    public function test_tenggat_dp_lewat_kursi_dilepas_job01(): void
    {
        $dep = $this->dep('2027-03-10');
        $this->actingAs($this->jamaah);
        $code = $this->book(['departure_id' => $dep->id, 'participants' => [
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Satu', '1571010101800001')],
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Dua', '1571010101800002')],
        ]])->assertCreated()->json('data.code');
        $this->assertSame('full', $dep->fresh()->sales_status);

        $this->travel(49)->hours();
        $this->artisan('bookings:expire-holds')->expectsOutput('1 booking kedaluwarsa.')->assertSuccessful();
        $b = Booking::withoutTenantScope()->where('code', $code)->first();
        $this->assertSame('expired', $b->status);
        $this->assertSame(['cancelled'], $b->invoices->pluck('status')->unique()->values()->all());
        $d = $dep->fresh();
        $this->assertSame([0, 'open'], [$d->seats_held, $d->sales_status]);
        // Jamaah yang sama bisa booking lagi setelah kedaluwarsa
        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim_id' => $b->participants->first()->pilgrim_id]]])->assertCreated();
    }

    public function test_hold_basi_dilepas_saat_ada_booking_baru_tanpa_menunggu_job(): void
    {
        $dep = $this->dep('2027-03-10');
        $this->actingAs($this->jamaah);
        $this->book(['departure_id' => $dep->id, 'participants' => [
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Satu', '1571010101800001')],
            ['room_type' => 'quad', 'pilgrim' => $this->pilgrim('A Dua', '1571010101800002')],
        ]])->assertCreated();
        $this->travel(49)->hours();
        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('C Satu', '1571010101800021')]]])->assertCreated();
        $this->assertSame(1, $dep->fresh()->seats_held);
    }

    public function test_booking_setelah_batas_pelunasan_dibayar_penuh(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2027, 1, 10)->setTime(9, 0)->utc()); // pelunasan 11 Jan, DP 48 jam → lewat
        $dep = $this->dep('2027-02-10');
        $this->actingAs($this->jamaah);
        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'triple', 'pilgrim' => $this->pilgrim('Hasan', '1571011705750001')]]])
            ->assertCreated()->assertJsonCount(1, 'data.invoices')->assertJsonPath('data.invoices.0.type', 'full')
            ->assertJsonPath('data.dp_amount', 35000000);
    }

    public function test_atribusi_link_referral_kode_manual_dan_snapshot_komisi(): void
    {
        $dep = $this->dep('2027-02-10');
        $this->actingAs($this->jamaah);
        $id = $this->book(['departure_id' => $dep->id, 'referral_code' => 'q7kx2m', 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Hasan', '1571011705750001')]]])
            ->assertCreated()->assertJsonPath('data.attribution_source', 'referral_link')->assertJsonPath('data.agent.code', 'BUDI24')->json('data.id');
        $b = Booking::withoutTenantScope()->find($id);
        $this->assertSame(['flat', 1000000], [$b->commission_snapshot['type'], $b->commission_snapshot['flat_amount']]);

        // Link PPIU B tidak berlaku untuk paket PPIU A → tanpa atribusi
        $this->book(['departure_id' => $dep->id, 'referral_code' => 'B8PPIB', 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Umar', '1571011705750002')]]])
            ->assertCreated()->assertJsonPath('data.attribution_source', 'none');
        // Kode agen diketik: valid → manual_code; tidak valid → 422
        $this->book(['departure_id' => $dep->id, 'agent_code' => 'XXXX99', 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Ali', '1571011705750003')]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'agent_code_invalid');
        $this->book(['departure_id' => $dep->id, 'agent_code' => 'budi24', 'referral_code' => 'Q7KX2M', 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Ali', '1571011705750003')]]])
            ->assertCreated()->assertJsonPath('data.attribution_source', 'manual_code');

        // Agen tidak mendapat atribusi atas booking dirinya sendiri
        $budi = Agent::where('code', 'BUDI24')->first()->user;
        $this->actingAs($budi);
        $this->book(['departure_id' => $dep->id, 'referral_code' => 'Q7KX2M', 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Budi', '9999010101900024')]]])
            ->assertCreated()->assertJsonPath('data.attribution_source', 'none');
    }

    public function test_idempotency_key_mencegah_booking_ganda(): void
    {
        $dep = $this->dep('2027-02-10');
        $this->actingAs($this->jamaah);
        $key = (string) Str::uuid();
        $body = ['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Hasan', '1571011705750001')]]];
        $first = $this->book($body, $key)->assertCreated()->json('data.code');
        $this->book($body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.code', $first);
        $this->assertSame(1, Booking::withoutTenantScope()->count());
        $this->flushHeaders();
        $this->withHeader('Referer', 'http://localhost:3000/');
        $this->postJson('/api/v1/me/bookings', $body + ['accept_package_terms' => true])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_booking_oleh_agen_dan_walkin_ppiu(): void
    {
        $dep = $this->dep('2027-02-10');
        $budi = Agent::where('code', 'BUDI24')->first();
        $this->actingAs($budi->user);
        $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/agent/bookings', [
            'departure_id' => $dep->id, 'accept_package_terms' => true, 'booker' => ['name' => 'Pak Rahman', 'phone' => '0812-9999-0001'],
            'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Rahman', '1571011705750011')]],
        ])->assertCreated()->assertJsonPath('data.booker.phone', '+6281299990001')->assertJsonPath('data.attribution_source', 'agent_created');
        $this->assertArrayNotHasKey('nik_masked', $res->json('data.participants.0'));
        $this->assertSame('unclaimed', User::where('phone', '+6281299990001')->value('status'));
        $this->getJson('/api/v1/agent/bookings')->assertJsonCount(1, 'data');

        // Agen ke PPIU tanpa kemitraan → 403
        $d = Departure::withoutTenantScope()->where('tenant_id', Tenant::where('slug', 'ppiu-b-jambi')->value('id'))->first();
        \App\Models\Partnership::withoutTenantScope()->where('agent_id', $budi->id)->where('tenant_id', $d->tenant_id)->update(['status' => 'suspended']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/agent/bookings', [
            'departure_id' => $d->id, 'accept_package_terms' => true, 'booker' => ['name' => 'Xavier', 'phone' => '081299990002'],
            'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Xavier', '1571011705750012')]],
        ])->assertForbidden()->assertJsonPath('error.code', 'partnership_inactive');

        // Walk-in staf PPIU dengan agen ditugaskan; tampilan PPIU berisi NIK tersamar
        $owner = TenantMember::withoutTenantScope()->where('tenant_id', $this->a->id)->where('role', 'owner')->first()->user;
        $this->actingAs($owner);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/tenants/{$this->a->id}/bookings", [
            'departure_id' => $dep->id, 'accept_package_terms' => true, 'agent_id' => $budi->id, 'booker' => ['name' => 'Bu Halimah', 'phone' => '081299990003'],
            'participants' => [['room_type' => 'double', 'pilgrim' => $this->pilgrim('Halimah', '1571015505780013', 'P')]],
        ])->assertCreated()->assertJsonPath('data.attribution_source', 'ppiu_assigned')->assertJsonPath('data.channel', 'ppiu_dashboard')
            ->assertJsonPath('data.participants.0.nik_masked', '1571••••••••0013');
        $this->getJson("/api/v1/tenants/{$this->a->id}/bookings")->assertOk()->assertJsonPath('meta.counts.awaiting_dp', 2);
        $this->getJson("/api/v1/tenants/{$this->a->id}/bookings?q=halimah")->assertJsonCount(1, 'data');

        // Finance tidak boleh membuat booking; tenant lain tidak melihat booking ini
        $fin = User::where('email', 'ppiua.finance@umrahconnect.test')->first();
        $this->actingAs($fin);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/tenants/{$this->a->id}/bookings", [])->assertForbidden();
        $b = Tenant::where('slug', 'ppiu-b-jambi')->first();
        $ownerB = TenantMember::withoutTenantScope()->where('tenant_id', $b->id)->where('role', 'owner')->first()->user;
        $any = Booking::withoutTenantScope()->where('tenant_id', $this->a->id)->value('id');
        $this->actingAs($ownerB)->getJson("/api/v1/tenants/{$b->id}/bookings/$any")->assertNotFound();
    }

    public function test_booking_butuh_kontak_terverifikasi_dan_persetujuan_syarat(): void
    {
        $dep = $this->dep('2027-02-10');
        $u = User::create(['name' => 'Baru', 'email' => 'baru@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active']);
        $this->actingAs($u);
        $this->book(['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('Baru', '1571011705750031')]]])
            ->assertForbidden()->assertJsonPath('error.code', 'verification_required');
        $this->actingAs($this->jamaah);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/bookings', ['departure_id' => $dep->id, 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim('X', '1571011705750032')]]])
            ->assertStatus(422)->assertJsonValidationErrors(['accept_package_terms'], 'error.errors');
    }
}
