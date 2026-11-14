<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WebSession;
use Tests\TestCase;

/** FR-AUTH-01..04, FR-AUTH-08 — TC-AUTH di Testing Documentation. */
class AuthTest extends TestCase
{
    use RefreshDatabase, WebSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
    }

    public function test_registrasi_mencatat_persetujuan_mengirim_otp_dan_login(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Siti Aminah', 'email' => 'Siti@Contoh.test', 'password' => 'rahasia123'] + $this->legal())
            ->assertCreated()
            ->assertJsonPath('data.email', 'siti@contoh.test')
            ->assertJsonPath('data.email_verified', false);

        $user = User::where('email', 'siti@contoh.test')->firstOrFail();
        $this->assertDatabaseCount('consents', 2);
        $this->assertArrayHasKey('siti@contoh.test|verify', $this->otp->codes);
        $this->assertAuthenticatedAs($user);

        // Verifikasi OTP
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => 'siti@contoh.test', 'purpose' => 'verify', 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => 'siti@contoh.test', 'purpose' => 'verify', 'code' => $this->otp->codes['siti@contoh.test|verify']])
            ->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_registrasi_dengan_nomor_hp_dinormalisasi_dan_versi_lama_ditolak(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Budi', 'phone' => '0812-3456-7890', 'password' => 'rahasia123'] + $this->legal())
            ->assertCreated()->assertJsonPath('data.phone', '+6281234567890');
        $this->assertArrayHasKey('+6281234567890|verify', $this->otp->codes);

        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'x@contoh.test', 'password' => 'rahasia123', 'terms_version' => 'lama', 'privacy_version' => 'lama'])
            ->assertStatus(422)->assertJsonValidationErrors(['terms_version', 'privacy_version'], 'error.errors');
    }

    public function test_login_salah_lima_kali_mengunci_akun_15_menit(): void
    {
        User::create(['name' => 'A', 'email' => 'a@contoh.test', 'password_hash' => 'benar1234', 'status' => 'active']);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['identifier' => 'a@contoh.test', 'password' => 'salah'])
                ->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
        }
        $this->postJson('/api/v1/auth/login', ['identifier' => 'a@contoh.test', 'password' => 'salah'])
            ->assertStatus(423)->assertJsonPath('error.code', 'account_locked');
        // Kata sandi benar pun ditolak selama terkunci
        $this->postJson('/api/v1/auth/login', ['identifier' => 'a@contoh.test', 'password' => 'benar1234'])->assertStatus(423);

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/login', ['identifier' => 'a@contoh.test', 'password' => 'benar1234'])
            ->assertOk()->assertJsonPath('data.user.email', 'a@contoh.test');
    }

    public function test_akun_tidak_dikenal_mendapat_pesan_yang_sama(): void
    {
        $this->postJson('/api/v1/auth/login', ['identifier' => 'tidakada@contoh.test', 'password' => 'apapun'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
        // OTP reset untuk akun tak dikenal tetap 204 (anti-enumerasi) dan tidak mengirim apa pun
        $this->postJson('/api/v1/auth/otp/send', ['identifier' => 'tidakada@contoh.test', 'channel' => 'email', 'purpose' => 'reset'])->assertNoContent();
        $this->assertSame([], $this->otp->codes);
    }

    public function test_atur_ulang_kata_sandi_lewat_otp(): void
    {
        $u = User::create(['name' => 'A', 'email' => 'a@contoh.test', 'password_hash' => 'lama12345', 'status' => 'active']);
        $this->postJson('/api/v1/auth/otp/send', ['identifier' => 'a@contoh.test', 'channel' => 'email', 'purpose' => 'reset'])->assertNoContent();
        $this->postJson('/api/v1/auth/otp/send', ['identifier' => 'a@contoh.test', 'channel' => 'email', 'purpose' => 'reset'])
            ->assertStatus(429)->assertJsonPath('error.code', 'otp_resend_too_soon');

        $token = $this->postJson('/api/v1/auth/otp/verify', ['identifier' => 'a@contoh.test', 'purpose' => 'reset', 'code' => $this->otp->codes['a@contoh.test|reset']])
            ->assertOk()->json('data.reset_token');
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $token, 'password' => 'baru12345'])->assertNoContent();
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $token, 'password' => 'baru12345'])->assertStatus(422);

        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->postJson('/api/v1/auth/login', ['identifier' => 'a@contoh.test', 'password' => 'baru12345'])->assertOk();
    }

    public function test_2fa_setup_konfirmasi_lalu_login_butuh_tantangan(): void
    {
        $u = User::create(['name' => 'A', 'email' => 'a@contoh.test', 'password_hash' => 'benar1234', 'status' => 'active']);
        $this->actingAs($u);
        $secret = $this->postJson('/api/v1/auth/two-factor/setup')->assertOk()
            ->assertJsonStructure(['data' => ['otpauth_url', 'qr_svg', 'secret']])->json('data.secret');
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->assertJsonCount(8, 'data.recovery_codes')->json('data.recovery_codes');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $res = $this->postJson('/api/v1/auth/login', ['identifier' => 'a@contoh.test', 'password' => 'benar1234'])
            ->assertOk()->assertJsonPath('data.two_factor_required', true);
        $this->assertGuest('web');

        // Kode cadangan hanya sekali pakai
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_token' => $res->json('data.challenge_token'), 'code' => $codes[0]])
            ->assertOk()->assertJsonPath('data.email', 'a@contoh.test');
        $this->assertFalse(app(TwoFactor::class)->verifyUser($u->fresh(), $codes[0]));
    }

    public function test_konteks_menandai_2fa_wajib_untuk_super_admin(): void
    {
        $u = User::create(['name' => 'S', 'email' => 's@contoh.test', 'password_hash' => 'benar1234', 'status' => 'active', 'platform_role' => 'super_admin']);
        $this->actingAs($u)->getJson('/api/v1/me/contexts')->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonPath('data.platform_role', 'super_admin');
        $this->getJson('/api/v1/platform/tenants')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_required');
    }

    public function test_endpoint_butuh_login(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }
}
