<?php

namespace Tests\Feature\Security;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Departure;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\WebSession;
use Tests\TestCase;

/**
 * Sprint 7 — Gate MVP: kriteria penerimaan (AC) dan otorisasi (AZ) yang belum dicakup tes sprint sebelumnya,
 * plus pemeriksaan keamanan TC-SEC-03/06/07 dan header NFR-10.
 */
class MvpGateTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private Tenant $b;

    private User $owner;

    private User $finance;

    private User $admin;

    private User $jamaah;

    private Agent $budi;

    private Agent $siti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 14)->setTime(10, 0)->utc());
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        config(['permissions.enforce_two_factor' => false]);
        $this->a = Tenant::where('slug', 'ppiu-a-jambi')->firstOrFail();
        $this->b = Tenant::where('slug', 'ppiu-b-jambi')->firstOrFail();
        $this->owner = $this->ownerOf($this->a);
        $this->finance = User::where('email', 'ppiua.finance@umrahconnect.test')->firstOrFail();
        $this->admin = User::create(['name' => 'Admin PPIU A', 'email' => 'ppiua.admin@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        TenantMember::create(['tenant_id' => $this->a->id, 'user_id' => $this->admin->id, 'role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $this->jamaah = User::create(['name' => 'Hasan', 'email' => 'hasan@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->budi = Agent::where('code', 'BUDI24')->firstOrFail();
        $sitiUser = User::create(['name' => 'Siti', 'email' => 'siti@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->siti = Agent::create(['user_id' => $sitiUser->id, 'code' => 'SITI77', 'nik_enc' => '9999010101900077', 'nik_hash' => Agent::nikHash('9999010101900077'),
            'domicile_city' => 'Kota Jambi', 'payout_bank_name' => 'BSI', 'payout_account_no_enc' => '000000000077', 'payout_account_holder' => 'Siti']);
        Partnership::create(['tenant_id' => $this->a->id, 'agent_id' => $this->siti->id, 'status' => 'active', 'source' => 'applied', 'decided_at' => now()]);
    }

    private function ownerOf(Tenant $t): User
    {
        return TenantMember::withoutTenantScope()->where('tenant_id', $t->id)->where('role', 'owner')->first()->user;
    }

    private function key(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function url(string $p, ?Tenant $t = null): string
    {
        return '/api/v1/tenants/'.($t ?? $this->a)->id.$p;
    }

    private function dep(): Departure
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();

        return Departure::withoutTenantScope()->where('package_id', $pkg->id)->where('departure_date', '2027-02-10')->firstOrFail();
    }

    private function pilgrim(int $i): array
    {
        return ['full_name' => "Jamaah $i", 'nik' => '157101170575'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => 'L'];
    }

    private function agentBooking(Agent $agent, int $pax = 1, int $offset = 0): array
    {
        $this->actingAs($agent->user);
        $participants = [];
        for ($i = 1; $i <= $pax; $i++) {
            $participants[] = ['room_type' => 'quad', 'pilgrim' => $this->pilgrim($offset + $i)];
        }

        return $this->key()->postJson('/api/v1/agent/bookings', ['departure_id' => $this->dep()->id, 'accept_package_terms' => true,
            'booker' => ['name' => 'Pak Rahman', 'phone' => '081299990001'], 'participants' => $participants])->assertCreated()->json('data');
    }

    private function myBooking(User $u, int $i): array
    {
        $this->actingAs($u);

        return $this->key()->postJson('/api/v1/me/bookings', ['departure_id' => $this->dep()->id, 'accept_package_terms' => true,
            'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim($i)]]])->assertCreated()->json('data');
    }

    private function pay(array $inv): void
    {
        $this->actingAs($this->finance);
        $this->key()->postJson($this->url("/invoices/{$inv['id']}/payments"), ['method' => 'cash', 'amount' => $inv['amount'], 'paid_on' => '2026-11-14'])->assertCreated();
    }

    /** AC-12, AZ-05: hanya Owner, alasan wajib; komisi berpindah ke agen baru; tercatat di audit log dan catatan internal. */
    public function test_ac12_owner_mengubah_atribusi_dengan_alasan_tercatat_di_audit_log(): void
    {
        $b = $this->agentBooking($this->budi, 2);
        $url = $this->url("/bookings/{$b['id']}/attribution");

        $this->actingAs($this->admin)->patchJson($url, ['agent_id' => $this->siti->id, 'reason' => 'Jamaah datang lewat Siti'])->assertForbidden();
        $this->actingAs($this->owner)->patchJson($url, ['agent_id' => $this->siti->id, 'reason' => ''])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['reason']]]);
        $this->patchJson($url, ['agent_id' => $this->budi->id, 'reason' => 'Tidak berubah'])->assertStatus(422);
        $outsider = Agent::create(['user_id' => $this->jamaah->id, 'code' => 'LUAR01', 'nik_enc' => '9999010101900099', 'nik_hash' => Agent::nikHash('9999010101900099'), 'domicile_city' => 'Jambi']);
        $this->patchJson($url, ['agent_id' => $outsider->id, 'reason' => 'Bukan mitra'])->assertStatus(422)->assertJsonPath('error.code', 'agent_not_partner');

        $this->patchJson($url, ['agent_id' => $this->siti->id, 'reason' => 'Jamaah konfirmasi datang lewat Siti'])->assertOk()
            ->assertJsonPath('data.attribution_source', 'ppiu_assigned');
        $c = Commission::withoutTenantScope()->where('booking_id', $b['id'])->firstOrFail();
        $this->assertSame([$this->siti->id, 'estimated', 2000000], [$c->agent_id, $c->status, $c->amount]);
        $log = AuditLog::where('action', 'booking.attribution_changed')->where('entity_id', $b['id'])->firstOrFail();
        $this->assertSame('Jamaah konfirmasi datang lewat Siti', $log->reason);
        $this->assertSame($this->budi->id, $log->old_values['agent_id']);
        $this->assertSame($this->siti->id, $log->new_values['agent_id']);
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->budi->user_id)->where('event', 'booking.attribution_changed')->exists());
        $this->getJson($this->url("/bookings/{$b['id']}/notes"))->assertOk()->assertJsonFragment(['body' => 'Atribusi agen diubah dari Budi (BUDI24) ke Siti (SITI77). Alasan: Jamaah konfirmasi datang lewat Siti']);

        // Budi tidak lagi melihat booking itu; Siti melihatnya.
        $this->actingAs($this->budi->user)->getJson("/api/v1/agent/bookings/{$b['id']}")->assertNotFound();
        $this->actingAs($this->siti->user)->getJson("/api/v1/agent/bookings/{$b['id']}")->assertOk();

        // Tanpa agen → komisi hilang.
        $this->actingAs($this->owner)->patchJson($url, ['agent_id' => null, 'reason' => 'Ternyata walk-in'])->assertOk()->assertJsonPath('data.attribution_source', 'none');
        $this->assertFalse(Commission::withoutTenantScope()->where('booking_id', $b['id'])->exists());

        // Booking lunas tidak bisa diubah.
        foreach ($b['invoices'] as $inv) {
            $this->pay($inv);
        }
        $this->actingAs($this->owner)->patchJson($url, ['agent_id' => $this->budi->id, 'reason' => 'Coba setelah lunas'])->assertStatus(409)->assertJsonPath('error.code', 'booking_not_changeable');
    }

    public function test_ac12_komisi_yang_sudah_disetujui_mengunci_atribusi(): void
    {
        DB::table('commission_schemes')->where('tenant_id', $this->a->id)->update(['earn_on' => 'dp_verified']);
        $b = $this->agentBooking($this->budi);
        $this->pay($b['invoices'][0]);
        $c = Commission::withoutTenantScope()->where('booking_id', $b['id'])->firstOrFail();
        $this->assertSame('earned', $c->status);
        $this->actingAs($this->finance)->postJson($this->url('/commissions/approve'), ['ids' => [$c->id]])->assertOk();
        $this->actingAs($this->owner)->patchJson($this->url("/bookings/{$b['id']}/attribution"), ['agent_id' => $this->siti->id, 'reason' => 'Salah input agen'])
            ->assertStatus(409)->assertJsonPath('error.code', 'commission_locked');
    }

    /** AC-11: harga baru tidak mengubah invoice lama; booking baru memakai harga baru. */
    public function test_ac11_perubahan_harga_tidak_mengubah_invoice_lama(): void
    {
        $old = $this->myBooking($this->jamaah, 1);
        $dep = $this->dep();
        $this->actingAs($this->owner);
        $etag = $this->getJson($this->url("/departures/{$dep->id}"))->assertOk()->headers->get('ETag');
        $this->withHeader('If-Match', $etag)->putJson($this->url("/departures/{$dep->id}/prices"), ['prices' => [
            ['room_type' => 'quad', 'price_amount' => 34000000], ['room_type' => 'triple', 'price_amount' => 36000000], ['room_type' => 'double', 'price_amount' => 39000000],
        ]])->assertOk();

        $this->assertSame(32500000, Booking::withoutTenantScope()->find($old['id'])->total_amount);
        $this->assertSame([5000000, 27500000], Invoice::withoutTenantScope()->where('booking_id', $old['id'])->orderBy('due_at')->pluck('amount')->all());
        $new = $this->myBooking($this->jamaah, 2);
        $this->assertSame(34000000, $new['total_amount']);
    }

    /** AZ-01, AZ-02, AC-09: lintas tenant → 404 dan percobaan tercatat di audit log. */
    public function test_az01_az02_akses_lintas_tenant_dijawab_404_dan_tercatat(): void
    {
        $this->actingAs($this->admin)->getJson($this->url('/bookings', $this->b))->assertNotFound();
        $log = AuditLog::where('action', 'tenant.access_denied')->where('actor_user_id', $this->admin->id)->firstOrFail();
        $this->assertSame($this->b->id, $log->tenant_id);

        $bOwner = $this->ownerOf($this->b);
        $pkgB = Package::withoutTenantScope()->where('tenant_id', $this->b->id)->first();
        $depB = Departure::withoutTenantScope()->where('package_id', $pkgB->id)->first();
        $bookingB = $this->actingAs($bOwner)->key()->postJson($this->url('/bookings', $this->b), ['departure_id' => $depB->id, 'accept_package_terms' => true,
            'booker' => ['name' => 'Walk-in B', 'phone' => '081299990009'], 'participants' => [['room_type' => 'quad', 'pilgrim' => $this->pilgrim(50)]]])->assertCreated()->json('data');

        $this->actingAs($this->owner);
        $this->getJson($this->url("/bookings/{$bookingB['id']}"))->assertNotFound();
        $this->getJson($this->url("/invoices/{$bookingB['invoices'][0]['id']}/pdf"))->assertNotFound();
        $this->key()->postJson($this->url("/invoices/{$bookingB['invoices'][0]['id']}/payments"), ['method' => 'cash', 'amount' => 1000000, 'paid_on' => '2026-11-14'])->assertNotFound();
        $this->patchJson($this->url("/bookings/{$bookingB['id']}/attribution"), ['agent_id' => null, 'reason' => 'Coba lintas tenant'])->assertNotFound();
    }

    /** AZ-03, AZ-04, AZ-16: matriks peran. */
    public function test_az03_az04_az16_peran_dibatasi_permission(): void
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->first();
        $this->actingAs($this->finance)->patchJson($this->url("/packages/{$pkg->id}"), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->admin)->postJson($this->url('/payments/'.Str::uuid().'/verify'))->assertForbidden();
        $b = $this->myBooking($this->jamaah, 3);
        $this->actingAs($this->admin)->key()->postJson($this->url("/bookings/{$b['id']}/refunds"), ['type' => 'bank_transfer', 'amount' => 1000000, 'paid_on' => '2026-11-14'])->assertForbidden();
        $this->actingAs($this->admin)->getJson($this->url('/commissions'))->assertOk();
        $this->actingAs($this->admin)->postJson($this->url('/commissions/approve'), ['ids' => [(string) Str::uuid()]])->assertForbidden();
    }

    /** AZ-07, AZ-08, AZ-09, AZ-17: batas data agen dan pemesan. */
    public function test_az07_az08_az09_az17_batas_data_agen_dan_pemesan(): void
    {
        $budiBooking = $this->agentBooking($this->budi, 1, 10);
        $this->actingAs($this->owner)->postJson($this->url("/bookings/{$budiBooking['id']}/notes"), ['body' => 'RAHASIA-INTERNAL jamaah minta kursi lorong'])->assertCreated();

        // AZ-07: agen lain di PPIU yang sama
        $this->actingAs($this->siti->user)->getJson("/api/v1/agent/bookings/{$budiBooking['id']}")->assertNotFound();

        // AZ-08 + AZ-17: respons agen tanpa NIK, paspor, tanggal lahir, catatan internal
        $res = $this->actingAs($this->budi->user)->getJson("/api/v1/agent/bookings/{$budiBooking['id']}")->assertOk();
        $json = $res->getContent();
        foreach (['nik', 'nik_masked', 'passport_no_masked', 'birth_date', 'notes'] as $field) {
            $this->assertStringNotContainsString("\"$field\"", $json, "Respons agen memuat $field");
        }
        $this->assertStringNotContainsString('RAHASIA-INTERNAL', $json);
        $this->assertStringNotContainsString('1571011705750011', $json);

        // AZ-09 + AZ-17: pemesan lain → 404; pemesan sendiri tidak melihat catatan internal
        $mine = $this->myBooking($this->jamaah, 20);
        $this->actingAs($this->owner)->postJson($this->url("/bookings/{$mine['id']}/notes"), ['body' => 'RAHASIA-INTERNAL kedua'])->assertCreated();
        $other = User::create(['name' => 'Orang Lain', 'email' => 'lain@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->actingAs($other)->getJson("/api/v1/me/bookings/{$mine['id']}")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/me/invoices/{$mine['invoices'][0]['id']}/pdf")->assertNotFound();
        $this->actingAs($this->jamaah)->getJson("/api/v1/me/bookings/{$mine['id']}")->assertOk()->assertDontSee('RAHASIA-INTERNAL');
    }

    /** AZ-10, AZ-11: Super Admin tidak bisa memverifikasi pembayaran tenant; akses dukungan baca-saja dan identitas tersamar. */
    public function test_az10_az11_staf_platform_tidak_bisa_menulis_data_tenant(): void
    {
        $super = User::where('email', 'superadmin@umrahconnect.test')->firstOrFail();
        $mine = $this->myBooking($this->jamaah, 30);
        $this->actingAs($super)->postJson($this->url('/payments/'.Str::uuid().'/verify'))->assertNotFound();

        $sid = $this->postJson('/api/v1/platform/support-sessions', ['tenant_id' => $this->a->id, 'ticket_no' => 'TKT-1', 'reason' => 'Pemilik minta bantuan cek booking'])->assertCreated()->json('data.id');
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->owner->id)->where('event', 'support.opened')->where('channel', 'in_app')->exists());
        $h = ['X-Support-Session' => $sid];
        $json = $this->withHeaders($h)->getJson($this->url("/bookings/{$mine['id']}"))->assertOk()->getContent();
        $this->assertStringNotContainsString('1571011705750030', $json, 'NIK utuh tampil di sesi dukungan');
        $this->withHeaders($h)->postJson($this->url("/bookings/{$mine['id']}/notes"), ['body' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'support_read_only');
        $this->withHeaders($h)->getJson($this->url('/pilgrims/'.Str::uuid().'/identity'))->assertStatus(403);

        $this->travel(61)->minutes();
        $this->withHeaders($h)->getJson($this->url('/bookings'))->assertForbidden()->assertJsonPath('error.code', 'support_session_expired');
    }

    /** AZ-12: tenant Ditangguhkan tidak bisa menerbitkan paket, tetapi tetap bisa mencatat/memverifikasi pembayaran yang berjalan. */
    public function test_az12_tenant_ditangguhkan_tidak_bisa_terbit_tetapi_tetap_menerima_pembayaran(): void
    {
        $b = $this->myBooking($this->jamaah, 40);
        $this->a->update(['verification_status' => 'suspended']);
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('status', 'draft')->firstOrFail();
        $this->actingAs($this->owner)->postJson($this->url("/packages/{$pkg->id}/publish"))->assertForbidden();
        $this->pay($b['invoices'][0]);
        $this->assertSame('dp_verified', Booking::withoutTenantScope()->find($b['id'])->status);
    }

    /** AZ-13: perubahan peran berlaku pada permintaan berikutnya. */
    public function test_az13_perubahan_peran_langsung_berlaku(): void
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->first();
        $this->actingAs($this->admin)->getJson($this->url('/reports/finance?type=receipts'))->assertForbidden();
        $member = TenantMember::withoutTenantScope()->where('user_id', $this->admin->id)->first();
        $this->actingAs($this->owner)->patchJson($this->url("/members/{$member->id}"), ['role' => 'finance'])->assertOk();
        $this->actingAs($this->admin)->getJson($this->url('/reports/finance?type=receipts'))->assertOk();
        $this->actingAs($this->admin)->patchJson($this->url("/packages/{$pkg->id}"), ['name' => 'X'])->assertForbidden();
    }

    /** AZ-15: setiap route tenant punya permission, setiap route platform punya izin platform (tes pemindai route). */
    public function test_az15_setiap_route_terlindungi(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            $mw = $r->gatherMiddleware();
            $has = fn (string $prefix) => collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, $prefix));
            if (str_starts_with($uri, 'api/v1/tenants/{tenant}') && ! $has('perm:')) {
                $missing[] = $r->methods()[0].' '.$uri;
            }
            if (str_starts_with($uri, 'api/v1/platform/') && ! $has('platform:')) {
                $missing[] = $r->methods()[0].' '.$uri;
            }
            // agent/profile: GET/POST dipakai sebelum profil agen ada (pendaftaran agen), jadi tanpa middleware agent.
            if (str_starts_with($uri, 'api/v1/agent/') && $uri !== 'api/v1/agent/profile' && ! $has('agent')) {
                $missing[] = $r->methods()[0].' '.$uri;
            }
            if (str_starts_with($uri, 'api/v1/') && ! str_starts_with($uri, 'api/v1/public') && ! str_starts_with($uri, 'api/v1/auth') && ! $has('auth:sanctum')
                && ! str_starts_with($uri, 'api/v1/files/') && $uri !== 'api/v1/health') { // health: publik untuk pemantau uptime
                $missing[] = $r->methods()[0].' '.$uri.' (tanpa auth)';
            }
        }
        $this->assertSame([], $missing, "Route tanpa permission:\n".implode("\n", $missing));
    }

    /** TC-SEC-03: permintaan login ke-11 per identitas dalam 1 menit → 429. */
    public function test_sec03_rate_limit_login(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['identifier' => 'tidakada@contoh.test', 'password' => 'salah-'.$i])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['identifier' => 'tidakada@contoh.test', 'password' => 'salah-11'])->assertStatus(429);
    }

    /** TC-SEC-06: audit log append-only di level database. */
    public function test_sec06_audit_log_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->actingAs($this->admin)->getJson($this->url('/bookings', $this->b))->assertNotFound();
        $id = AuditLog::query()->value('id');
        foreach (['UPDATE audit_logs SET reason = \'x\' WHERE id = ?', 'DELETE FROM audit_logs WHERE id = ?'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql, [$id]));
                $this->fail("Perintah berhasil: $sql");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    /** TC-SEC-07: tipe file dicek dari isi; PDF berskrip ditolak. */
    public function test_sec07_unggahan_berbahaya_ditolak(): void
    {
        $this->actingAs($this->jamaah);
        $html = UploadedFile::fake()->createWithContent('bukti.jpg', '<html><script>alert(1)</script></html>');
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $html])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['file']]]);

        $pdfJs = UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $pdfJs])->assertStatus(422)->assertJsonPath('error.errors.file.0', fn ($m) => str_contains($m, 'skrip'));

        $pdf = UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => $pdf])->assertCreated();
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('tf.png')])->assertCreated();
    }

    /** NFR-10: header keamanan dan respons privat tidak disimpan cache bersama. */
    public function test_header_keamanan(): void
    {
        $this->getJson('/api/v1/public/packages')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        $res = $this->actingAs($this->jamaah)->getJson('/api/v1/me/bookings')->assertOk();
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }
}
