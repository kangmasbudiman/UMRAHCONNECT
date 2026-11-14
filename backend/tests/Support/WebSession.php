<?php

namespace Tests\Support;

use App\Support\Otp\OtpSender;

/** Permintaan dari web (Referer domain stateful) agar Sanctum memakai sesi cookie. */
trait WebSession
{
    protected FakeOtpSender $otp;

    protected function setUpWebSession(): void
    {
        $this->otp = new FakeOtpSender;
        $this->app->instance(OtpSender::class, $this->otp);
        $this->withHeader('Referer', 'http://localhost:3000/');
    }

    protected function legal(): array
    {
        return ['terms_version' => config('app.legal.terms_version'), 'privacy_version' => config('app.legal.privacy_version')];
    }
}
