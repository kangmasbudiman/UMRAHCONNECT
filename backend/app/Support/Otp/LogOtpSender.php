<?php

namespace App\Support\Otp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pengiriman OTP untuk development: email lewat mailer aktif (MAIL_MAILER=log menulis ke storage/logs),
 * WhatsApp hanya dicatat ke log sampai integrasi WhatsApp API (Fase 2).
 */
class LogOtpSender implements OtpSender
{
    private const PURPOSE_TEXT = [
        'verify' => 'verifikasi akun',
        'reset' => 'atur ulang kata sandi',
        'two_factor' => 'masuk',
    ];

    public function send(string $identifier, string $channel, string $code, string $purpose): void
    {
        $text = "Kode {$code} untuk ".(self::PURPOSE_TEXT[$purpose] ?? $purpose).' UMRAH CONNECT. Berlaku 10 menit. Jangan berikan kode ini kepada siapa pun, termasuk petugas UMRAH CONNECT.';

        if ($channel === 'email') {
            Mail::raw($text, fn ($m) => $m->to($identifier)->subject("Kode {$code} — UMRAH CONNECT"));

            return;
        }
        Log::info('[OTP whatsapp] '.$identifier.': '.$text);
    }
}
