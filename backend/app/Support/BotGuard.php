<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Penangkal bot untuk formulir publik (daftar akun):
 * 1. honeypot — kolom "website" tersembunyi yang hanya diisi bot;
 * 2. waktu isi minimal — formulir yang dikirim < 2,5 detik setelah dibuka dianggap otomatis;
 * 3. Cloudflare Turnstile (opsional, aktif bila TURNSTILE_SECRET_KEY diisi) — tanpa teka-teki gambar, ramah privasi.
 * Klien API lain (aplikasi mobile) yang tidak mengirim kolom 1–2 tetap dibatasi rate limit dan, bila aktif, Turnstile.
 */
class BotGuard
{
    public static function check(Request $request): void
    {
        $reject = fn () => throw new ApiException(422, 'bot_suspected', 'Pendaftaran tidak bisa diproses. Muat ulang halaman, isi formulir, lalu coba lagi.');
        if (filled($request->input('website'))) {
            $reject();
        }
        $started = $request->input('form_started_at');
        if (is_numeric($started) && (now()->getTimestampMs() - (int) $started) < 2500) {
            $reject();
        }
        $secret = config('services.turnstile.secret');
        if (! $secret) {
            return;
        }
        $token = (string) $request->input('turnstile_token');
        if ($token === '') {
            throw new ApiException(422, 'captcha_required', 'Selesaikan pemeriksaan keamanan di formulir terlebih dahulu.');
        }
        try {
            $ok = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret, 'response' => $token, 'remoteip' => $request->ip(),
            ])->json('success') === true;
        } catch (\Throwable $e) {
            Log::warning('Turnstile tidak bisa dihubungi: '.$e->getMessage());
            $ok = false;
        }
        if (! $ok) {
            throw new ApiException(422, 'captcha_failed', 'Pemeriksaan keamanan gagal. Muat ulang halaman lalu coba lagi.');
        }
    }
}
