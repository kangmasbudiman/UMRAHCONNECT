<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\User;
use App\Support\Otp\OtpService;
use App\Support\Present;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Auth (FR-AUTH-01..04, FR-AUTH-08). Web memakai sesi cookie Sanctum SPA; mobile memakai token (Fase 2). */
class AuthController extends Controller
{
    public const MAX_FAILED_LOGINS = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(private OtpService $otp, private TwoFactor $twoFactor) {}

    public function register(Request $request): JsonResponse
    {
        \App\Support\BotGuard::check($request);
        if ($request->filled('phone')) {
            $request->merge(['phone' => OtpService::normalizePhone($request->input('phone'))]);
        }
        if ($request->filled('email')) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'required_without:email', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'terms_version' => ['required', Rule::in([config('app.legal.terms_version')])],
            'privacy_version' => ['required', Rule::in([config('app.legal.privacy_version')])],
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk atau atur ulang kata sandi.',
            'phone.unique' => 'Nomor HP ini sudah terdaftar. Silakan masuk atau atur ulang kata sandi.',
            'phone.regex' => 'Nomor HP tidak valid. Contoh: 081234567890.',
            'terms_version.in' => 'Syarat dan ketentuan sudah diperbarui. Muat ulang halaman lalu setujui versi terbaru.',
            'privacy_version.in' => 'Kebijakan privasi sudah diperbarui. Muat ulang halaman lalu setujui versi terbaru.',
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password_hash' => $data['password'],
                'status' => 'active',
            ]);
            foreach (['terms' => $data['terms_version'], 'privacy' => $data['privacy_version']] as $doc => $ver) {
                Consent::create(['user_id' => $user->id, 'document' => $doc, 'version' => $ver, 'ip_address' => $request->ip()]);
            }

            return $user;
        });

        // OTP verifikasi dikirim otomatis ke kontak utama.
        $user->email
            ? $this->otp->issue($user->email, 'email', 'verify')
            : $this->otp->issue($user->phone, 'whatsapp', 'verify');

        $this->startSession($request, $user);

        return response()->json(['data' => Present::user($user->fresh())], 201);
    }

    public function sendOtp(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:254'],
            'channel' => ['required', Rule::in(['email', 'whatsapp'])],
            'purpose' => ['required', Rule::in(['verify', 'reset', 'two_factor'])],
        ]);
        if ($data['purpose'] === 'two_factor') {
            throw ApiException::unprocessable('otp_purpose_unsupported', 'Kode 2FA memakai aplikasi autentikator, bukan OTP.');
        }
        $identifier = OtpService::normalize($data['identifier']);
        $isEmail = str_contains($identifier, '@');
        if ($isEmail !== ($data['channel'] === 'email')) {
            throw ApiException::unprocessable('otp_channel_mismatch', $isEmail ? 'Kode untuk email dikirim lewat email.' : 'Kode untuk nomor HP dikirim lewat WhatsApp.');
        }

        // Tidak mengungkap apakah akun ada (anti-enumerasi): selalu 204 bila format benar.
        $user = $this->findByIdentifier($identifier);
        $eligible = $user && in_array($user->status, ['active', 'locked', 'unclaimed'], true) && match ($data['purpose']) {
            'verify' => $isEmail ? $user->email_verified_at === null : $user->phone_verified_at === null,
            'reset' => true,
        };
        if ($eligible) {
            $wait = $this->otp->issue($identifier, $data['channel'], $data['purpose']);
            if ($wait !== null) {
                throw new ApiException(429, 'otp_resend_too_soon', "Tunggu {$wait} detik sebelum meminta kode baru.", ['retry_after' => $wait]);
            }
        }

        return response()->noContent();
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:254'],
            'purpose' => ['required', Rule::in(['verify', 'reset'])],
            'code' => ['required', 'regex:/^[0-9]{6}$/'],
        ], ['code.regex' => 'Kode OTP terdiri dari 6 angka.']);
        $identifier = OtpService::normalize($data['identifier']);

        $result = $this->otp->check($identifier, $data['purpose'], $data['code']);
        match ($result) {
            'ok' => null,
            'expired' => throw ApiException::unprocessable('otp_expired', 'Kode sudah kedaluwarsa atau belum diminta. Minta kode baru.'),
            'too_many' => throw ApiException::unprocessable('otp_too_many_attempts', 'Terlalu banyak percobaan. Minta kode baru.'),
            default => throw ApiException::unprocessable('otp_invalid', 'Kode OTP salah. Periksa lagi 6 angka yang kami kirim.'),
        };

        $user = $this->findByIdentifier($identifier) ?? throw ApiException::unprocessable('otp_expired', 'Kode sudah kedaluwarsa. Minta kode baru.');
        $resetToken = null;
        if ($data['purpose'] === 'verify') {
            $this->markVerified($user, $identifier);
        } else {
            $resetToken = Str::random(64);
            Cache::put('pwreset:'.hash('sha256', $resetToken), ['user_id' => $user->id, 'identifier' => $identifier], now()->addMinutes(15));
        }

        return response()->json(['data' => ['verified' => true, 'reset_token' => $resetToken]]);
    }

    public function resetPassword(Request $request): Response
    {
        $data = $request->validate([
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ]);
        $key = 'pwreset:'.hash('sha256', $data['reset_token']);
        $entry = Cache::pull($key) ?? throw ApiException::unprocessable('reset_token_invalid', 'Tautan atur ulang sudah tidak berlaku. Minta kode OTP baru.');
        $user = User::findOrFail($entry['user_id']);

        DB::transaction(function () use ($user, $data, $entry) {
            $user->forceFill([
                'password_hash' => $data['password'],
                'status' => $user->status === 'deactivated' ? 'deactivated' : 'active',
                'failed_login_count' => 0,
                'locked_until' => null,
            ])->save();
            $this->markVerified($user, $entry['identifier']);
            // Kata sandi diganti → semua sesi dan token lama dicabut.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
        });

        return response()->noContent();
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string'],
        ]);
        $user = $this->findByIdentifier(OtpService::normalize($data['identifier']));

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            $this->throwLocked($user);
        }
        $valid = $user && $user->password_hash && in_array($user->status, ['active', 'locked'], true)
            && Hash::check($data['password'], $user->password_hash);

        if (! $valid) {
            if ($user) {
                $count = $user->failed_login_count + 1;
                if ($count >= self::MAX_FAILED_LOGINS) {
                    $user->forceFill(['failed_login_count' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->save();
                    $this->throwLocked($user);
                }
                $user->forceFill(['failed_login_count' => $count])->save();
            }
            if ($user?->status === 'deactivated') {
                throw new ApiException(403, 'account_deactivated', 'Akun ini sudah dinonaktifkan. Hubungi PPIU atau tim UMRAH CONNECT.');
            }
            throw ApiException::unprocessable('invalid_credentials', 'Email/nomor HP atau kata sandi salah.');
        }

        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null, 'status' => $user->status === 'locked' ? 'active' : $user->status])->save();

        if ($user->two_factor_enabled) {
            $token = Str::random(64);
            Cache::put('2fa-challenge:'.hash('sha256', $token), ['user_id' => $user->id, 'attempts' => 0], now()->addMinutes(5));

            return response()->json(['data' => ['two_factor_required' => true, 'challenge_token' => $token]]);
        }

        $this->startSession($request, $user);

        return response()->json(['data' => ['user' => Present::user($user->fresh())]]);
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_token' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $key = '2fa-challenge:'.hash('sha256', $data['challenge_token']);
        $entry = Cache::get($key) ?? throw ApiException::unprocessable('challenge_expired', 'Waktu memasukkan kode habis. Silakan masuk lagi.');
        $user = User::findOrFail($entry['user_id']);

        if (! $this->twoFactor->verifyUser($user, $data['code'])) {
            $entry['attempts']++;
            $entry['attempts'] >= 5 ? Cache::forget($key) : Cache::put($key, $entry, now()->addMinutes(5));
            throw ApiException::unprocessable('two_factor_invalid', 'Kode tidak cocok. Pakai 6 angka dari aplikasi autentikator atau kode cadangan.');
        }
        Cache::forget($key);
        $this->startSession($request, $user);

        return response()->json(['data' => Present::user($user->fresh())]);
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function twoFactorSetup(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->two_factor_enabled) {
            throw ApiException::conflict('two_factor_already_enabled', '2FA sudah aktif di akun ini.');
        }
        $secret = $this->twoFactor->newSecret();
        $user->forceFill(['two_factor_secret_enc' => $secret])->save();
        $url = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json(['data' => ['otpauth_url' => $url, 'qr_svg' => $this->twoFactor->qrSvg($url), 'secret' => $secret]]);
    }

    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/']], ['code.regex' => 'Kode terdiri dari 6 angka.']);
        $user = $request->user();
        if ($user->two_factor_enabled) {
            throw ApiException::conflict('two_factor_already_enabled', '2FA sudah aktif di akun ini.');
        }
        if (! $user->two_factor_secret_enc) {
            throw ApiException::unprocessable('two_factor_not_started', 'Mulai pengaturan 2FA terlebih dahulu.');
        }
        if (! $this->twoFactor->verifyTotp($user->two_factor_secret_enc, $data['code'])) {
            throw ApiException::unprocessable('two_factor_invalid', 'Kode tidak cocok. Pastikan jam ponsel Anda tepat lalu coba kode terbaru.');
        }
        $codes = $this->twoFactor->newRecoveryCodes();
        $user->forceFill(['two_factor_enabled' => true, 'two_factor_recovery_codes_enc' => json_encode($codes)])->save();

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    private function startSession(Request $request, User $user): void
    {
        if (! $request->hasSession()) {
            throw new ApiException(400, 'session_unavailable', 'Login web memerlukan sesi cookie dari domain UMRAH CONNECT. Aplikasi mobile memakai /auth/tokens.');
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->rememberDevice($request, $user);
    }

    /** Catat perangkat; bila perangkat baru (dan bukan login pertama), kirim pemberitahuan keamanan. */
    private function rememberDevice(Request $request, User $user): void
    {
        $ua = $request->userAgent();
        $fp = \App\Support\Device::fingerprint($ua);
        $label = \App\Support\Device::label($ua);
        $known = DB::table('user_devices')->where('user_id', $user->id)->where('fingerprint', $fp)->exists();
        $hasAny = $known || DB::table('user_devices')->where('user_id', $user->id)->exists();
        DB::table('user_devices')->upsert([['user_id' => $user->id, 'fingerprint' => $fp, 'label' => $label, 'last_ip' => $request->ip(), 'first_seen_at' => now(), 'last_seen_at' => now()]],
            ['user_id', 'fingerprint'], ['last_ip', 'last_seen_at', 'label']);
        if (! $known && $hasAny) {
            $when = now('Asia/Jakarta')->translatedFormat('j F Y H:i');
            app(\App\Services\NotificationService::class)->send($user, 'security.new_device', [
                'body' => "Akun Anda baru saja masuk dari perangkat baru: {$label} (IP {$request->ip()}) pada {$when} WIB. Bila ini bukan Anda, segera ganti kata sandi dan keluarkan perangkat lain dari halaman Akun.",
                'url' => '/akun#perangkat',
            ], null, true);
        }
    }

    private function throwLocked(User $user): never
    {
        $wait = (int) ceil(now()->diffInSeconds($user->locked_until, true) / 60);
        throw new ApiException(423, 'account_locked', "Terlalu banyak percobaan gagal. Akun dikunci sementara, coba lagi dalam {$wait} menit atau atur ulang kata sandi.", ['locked_until' => $user->locked_until->toIso8601String()]);
    }

    private function findByIdentifier(string $identifier): ?User
    {
        return str_contains($identifier, '@')
            ? User::where('email', $identifier)->first()
            : User::where('phone', $identifier)->first();
    }

    private function markVerified(User $user, string $identifier): void
    {
        $col = str_contains($identifier, '@') ? 'email_verified_at' : 'phone_verified_at';
        if ($user->{$col} === null) {
            $user->forceFill([$col => now()])->save();
        }
    }
}
