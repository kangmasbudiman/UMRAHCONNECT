<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Pilgrim;
use App\Models\Tenant;
use App\Models\User;
use App\Support\VirusScanner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\WebSession;
use Tests\TestCase;

/** Pengerasan setelah tinjauan keamanan: lacak publik, hak data UU PDP, perangkat, anti-bot, virus, WhatsApp, health. */
class PrivacyHardeningTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private User $jamaah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 14)->setTime(10, 0)->utc());
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        config(['permissions.enforce_two_factor' => false]);
        $this->jamaah = User::create(['name' => 'Hasan Basri', 'email' => 'hasan@contoh.test', 'phone' => '+6281234567890', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
    }

    private function book(): array
    {
        $a = Tenant::where('slug', 'ppiu-a-jambi')->first();
        $pkg = Package::withoutTenantScope()->where('tenant_id', $a->id)->where('slug', 'umrah-reguler-12-hari')->first();
        $dep = Departure::withoutTenantScope()->where('package_id', $pkg->id)->where('departure_date', '2027-02-10')->first();

        return $this->actingAs($this->jamaah)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/bookings', [
            'departure_id' => $dep->id, 'accept_package_terms' => true,
            'participants' => [['room_type' => 'quad', 'pilgrim' => ['full_name' => 'Hasan Basri', 'nik' => '1571011705750001', 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => 'L']]],
        ])->assertCreated()->json('data');
    }

    public function test_lacak_publik_dikunci_per_kode_setelah_5_kali_salah(): void
    {
        $b = $this->book();
        for ($i = 0; $i < 5; $i++) {
            $this->getJson("/api/v1/public/track?code={$b['code']}&phone=000{$i}")->assertNotFound();
        }
        // Percobaan ke-6, walau benar, ditolak selama kode terkunci.
        $this->getJson("/api/v1/public/track?code={$b['code']}&phone=7890")->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
        $this->travel(61)->minutes();
        $this->getJson("/api/v1/public/track?code={$b['code']}&phone=7890")->assertOk();
    }

    public function test_unduh_data_saya_butuh_kata_sandi_dan_memuat_data_lengkap(): void
    {
        $b = $this->book();
        $this->postJson('/api/v1/me/data-export', ['password' => 'salah'])->assertStatus(422)->assertJsonPath('error.code', 'password_invalid');
        $res = $this->postJson('/api/v1/me/data-export', ['password' => 'rahasia123'])->assertOk();
        $this->assertStringContainsString('attachment;', $res->headers->get('Content-Disposition'));
        $res->assertJsonPath('account.email', 'hasan@contoh.test')->assertJsonPath('pilgrims.0.nik', '1571011705750001')->assertJsonPath('bookings.0.code', $b['code']);
        $this->assertTrue(AuditLog::where('action', 'user.data_exported')->where('entity_id', $this->jamaah->id)->exists());
    }

    public function test_hapus_akun_ditahan_bila_ada_booking_berjalan_lalu_dianonimkan(): void
    {
        $b = $this->book();
        Pilgrim::create(['managed_by_user_id' => $this->jamaah->id, 'full_name' => 'Belum Dipesan', 'birth_place' => 'Jambi', 'birth_date' => '1990-01-01', 'gender' => 'P']);
        $this->getJson('/api/v1/me/account/deletion')->assertOk()->assertJsonCount(1, 'data.blockers');
        $this->postJson('/api/v1/me/account/delete', ['password' => 'rahasia123', 'confirm' => 'HAPUS'])->assertStatus(409)->assertJsonPath('error.code', 'account_not_deletable');

        Booking::withoutTenantScope()->whereKey($b['id'])->update(['status' => 'completed']);
        $this->postJson('/api/v1/me/account/delete', ['password' => 'rahasia123', 'confirm' => 'hapus'])->assertStatus(422);
        $this->postJson('/api/v1/me/account/delete', ['password' => 'rahasia123', 'confirm' => 'HAPUS'])->assertNoContent();

        $u = $this->jamaah->fresh();
        $this->assertSame(['Pengguna terhapus', null, 'deactivated'], [$u->name, $u->phone, $u->status]);
        $this->assertStringEndsWith('@deleted.invalid', $u->email);
        // Jamaah yang ada di booking tetap disimpan (manifes PPIU); yang belum pernah dipesan dihapus.
        $this->assertSame(['Hasan Basri'], Pilgrim::where('managed_by_user_id', $u->id)->pluck('full_name')->all());
        $this->assertTrue(AuditLog::where('action', 'user.deleted')->where('entity_id', $u->id)->exists());
        // PPIU masih bisa membuka booking lama.
        $this->assertSame('completed', Booking::withoutTenantScope()->find($b['id'])->status);

        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['identifier' => 'hasan@contoh.test', 'password' => 'rahasia123'])->assertStatus(422);
    }

    public function test_perangkat_aktif_dan_notifikasi_login_perangkat_baru(): void
    {
        $login = fn (string $ua) => $this->withHeader('User-Agent', $ua)->postJson('/api/v1/auth/login', ['identifier' => 'hasan@contoh.test', 'password' => 'rahasia123'])->assertOk();
        $login('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0 Safari/537.36');
        $this->assertFalse(Notification::where('user_id', $this->jamaah->id)->where('event', 'security.new_device')->exists(), 'Login pertama tidak perlu peringatan');
        $login('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/131.0 Safari/537.36');
        $this->assertFalse(Notification::where('user_id', $this->jamaah->id)->where('event', 'security.new_device')->exists(), 'Perangkat sama (versi Chrome berbeda) bukan perangkat baru');
        $login('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36');
        $n = Notification::where('user_id', $this->jamaah->id)->where('event', 'security.new_device')->where('channel', 'in_app')->firstOrFail();
        $this->assertStringContainsString('Chrome di Android', $n->payload['body']);

        // Daftar & cabut sesi (driver sesi tes = array, jadi baris sesi disisipkan langsung).
        foreach (['sesi-a', 'sesi-b'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $this->jamaah->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (iPhone) Safari/604.1', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }
        $this->actingAs($this->jamaah);
        $list = $this->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.device', 'Safari di iOS')->json('data');
        $this->assertArrayNotHasKey('id', $list[0]);
        $this->deleteJson('/api/v1/me/sessions/'.$list[0]['key'])->assertNoContent();
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->jamaah->id)->count());
        $this->postJson('/api/v1/me/sessions/revoke-others')->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->jamaah->id)->count());
    }

    public function test_penangkal_bot_pendaftaran(): void
    {
        $base = ['name' => 'Bot', 'email' => 'bot@contoh.test', 'password' => 'rahasia123'] + $this->legal();
        $this->postJson('/api/v1/auth/register', $base + ['website' => 'http://spam.example'])->assertStatus(422)->assertJsonPath('error.code', 'bot_suspected');
        $this->postJson('/api/v1/auth/register', $base + ['form_started_at' => now()->getTimestampMs()])->assertStatus(422)->assertJsonPath('error.code', 'bot_suspected');

        config(['services.turnstile.secret' => 'rahasia-turnstile']);
        $this->postJson('/api/v1/auth/register', $base)->assertStatus(422)->assertJsonPath('error.code', 'captcha_required');
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);
        $this->postJson('/api/v1/auth/register', $base + ['turnstile_token' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'captcha_failed');
        $this->postJson('/api/v1/auth/register', $base + ['turnstile_token' => 'ok', 'form_started_at' => now()->subSeconds(30)->getTimestampMs()])->assertCreated();
    }

    public function test_pindai_virus_unggahan(): void
    {
        $this->actingAs($this->jamaah);
        $fake = fn (string $result) => $this->app->instance(VirusScanner::class, new class($result) extends VirusScanner {
            public function __construct(private string $r) {}

            public function scan(string $path): string
            {
                return $this->r;
            }
        });
        $fake('infected');
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('tf.png')])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['file']]]);
        $fake('error');
        config(['services.clamav.fail_closed' => true]);
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('tf.png')])->assertStatus(503)->assertJsonPath('error.code', 'scanner_unavailable');
        $fake('clean');
        $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('tf.png')])->assertCreated();
    }

    public function test_otp_whatsapp_lewat_gateway(): void
    {
        config(['services.whatsapp.driver' => 'fonnte', 'services.whatsapp.token' => 'tok', 'services.whatsapp.url' => 'https://api.fonnte.com/send']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        $this->app->forgetInstance(\App\Support\Otp\OtpSender::class);
        $this->app->bind(\App\Support\Otp\OtpSender::class, \App\Support\Otp\LogOtpSender::class);
        app(\App\Support\Otp\OtpSender::class)->send('+6281234567890', 'whatsapp', '123456', 'verify');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.fonnte.com/send' && $r['target'] === '6281234567890' && str_contains($r['message'], '123456') && $r->hasHeader('Authorization', 'tok'));
    }

    public function test_endpoint_health(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database', 'ok')->assertJsonPath('checks.cache', 'ok');
    }
}
