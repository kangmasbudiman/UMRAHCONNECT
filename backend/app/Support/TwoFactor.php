<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP (RFC 6238) dan kode cadangan untuk 2FA (FR-AUTH-03). */
class TwoFactor
{
    public function __construct(private Google2FA $g = new Google2FA) {}

    public function newSecret(): string
    {
        return $this->g->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->g->getQRCodeUrl('UMRAH CONNECT', $user->email ?? $user->phone, $secret);
    }

    public function qrSvg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return trim(preg_replace('/^<\?xml.*?\?>/', '', $writer->writeString($url)));
    }

    public function verifyTotp(string $secret, string $code): bool
    {
        return (bool) $this->g->verifyKey($secret, preg_replace('/\s+/', '', $code), 1);
    }

    /** @return string[] 8 kode cadangan format XXXX-XXXX */
    public function newRecoveryCodes(): array
    {
        return array_map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)), range(1, 8));
    }

    /** Cocokkan TOTP atau kode cadangan (sekali pakai). */
    public function verifyUser(User $user, string $code): bool
    {
        if (! $user->two_factor_enabled || ! $user->two_factor_secret_enc) {
            return false;
        }
        if (preg_match('/^\s*\d{6}\s*$/', $code)) {
            return $this->verifyTotp($user->two_factor_secret_enc, $code);
        }
        $codes = json_decode($user->two_factor_recovery_codes_enc ?? '[]', true) ?: [];
        $needle = Str::upper(trim($code));
        $idx = array_search($needle, $codes, true);
        if ($idx === false) {
            return false;
        }
        unset($codes[$idx]);
        $user->forceFill(['two_factor_recovery_codes_enc' => json_encode(array_values($codes))])->save();

        return true;
    }
}
