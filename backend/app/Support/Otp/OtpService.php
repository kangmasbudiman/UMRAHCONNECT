<?php

namespace App\Support\Otp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OTP 6 digit (FR-AUTH-01): disimpan sebagai hash di cache, berlaku 10 menit, maksimal 5 percobaan,
 * kirim ulang paling cepat 60 detik. Tidak ada tabel OTP di Database Design — sengaja tidak permanen.
 */
class OtpService
{
    public const TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public function __construct(private OtpSender $sender) {}

    /** @return int|null detik tunggu bila terlalu cepat kirim ulang */
    public function issue(string $identifier, string $channel, string $purpose): ?int
    {
        $key = $this->key($identifier, $purpose);
        $existing = Cache::get($key);
        if ($existing && now()->timestamp - $existing['sent_at'] < self::RESEND_SECONDS) {
            return self::RESEND_SECONDS - (now()->timestamp - $existing['sent_at']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($key, ['hash' => hash('sha256', $code), 'attempts' => 0, 'sent_at' => now()->timestamp], self::TTL_SECONDS);
        $this->sender->send($identifier, $channel, $code, $purpose);

        return null;
    }

    /** @return 'ok'|'invalid'|'expired'|'too_many' */
    public function check(string $identifier, string $purpose, string $code): string
    {
        $key = $this->key($identifier, $purpose);
        $entry = Cache::get($key);
        if (! $entry) {
            return 'expired';
        }
        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return 'too_many';
        }
        if (! hash_equals($entry['hash'], hash('sha256', $code))) {
            $entry['attempts']++;
            Cache::put($key, $entry, self::TTL_SECONDS);

            return 'invalid';
        }
        Cache::forget($key);

        return 'ok';
    }

    public static function normalize(string $identifier): string
    {
        $id = trim($identifier);

        return str_contains($id, '@') ? Str::lower($id) : self::normalizePhone($id);
    }

    /** 0812… / 62812… / +62812… → +62812… (E.164). */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone);
        if (str_starts_with($digits, '+')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+62'.substr($digits, 1);
        }
        if (str_starts_with($digits, '62')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    private function key(string $identifier, string $purpose): string
    {
        return 'otp:'.$purpose.':'.hash('sha256', self::normalize($identifier));
    }
}
