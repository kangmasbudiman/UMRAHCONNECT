<?php

namespace Tests\Support;

use App\Support\Otp\OtpSender;

class FakeOtpSender implements OtpSender
{
    /** @var array<string, string> identifier|purpose => kode terakhir */
    public array $codes = [];

    public function send(string $identifier, string $channel, string $code, string $purpose): void
    {
        $this->codes[$identifier.'|'.$purpose] = $code;
    }
}
