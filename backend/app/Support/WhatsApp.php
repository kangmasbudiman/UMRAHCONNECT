<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengiriman WhatsApp lewat gateway (WHATSAPP_DRIVER). Driver "fonnte" memakai API Fonnte;
 * tanpa driver, pesan hanya dicatat ke log (perilaku development). Pengganti resmi: WhatsApp Business API (Fase 2).
 */
class WhatsApp
{
    public static function enabled(): bool
    {
        return filled(config('services.whatsapp.driver')) && filled(config('services.whatsapp.token'));
    }

    /** @return bool terkirim (true) atau hanya dicatat/gagal (false) */
    public static function send(string $phone, string $text): bool
    {
        $target = ltrim(preg_replace('/\D/', '', $phone), '0');
        if (! self::enabled()) {
            Log::info('[WA belum aktif] +'.$target.': '.$text);

            return false;
        }
        try {
            $res = match (config('services.whatsapp.driver')) {
                'fonnte' => Http::asForm()->timeout(10)->withHeaders(['Authorization' => (string) config('services.whatsapp.token')])
                    ->post((string) config('services.whatsapp.url'), ['target' => $target, 'message' => $text, 'countryCode' => '0']),
                default => throw new \InvalidArgumentException('WHATSAPP_DRIVER tidak dikenal: '.config('services.whatsapp.driver')),
            };
            $ok = $res->successful() && ($res->json('status') === true || $res->json('status') === 'true');
            if (! $ok) {
                Log::warning('WhatsApp gagal dikirim ke +'.$target.': '.$res->body());
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('WhatsApp gagal dikirim ke +'.$target.': '.$e->getMessage());

            return false;
        }
    }
}
