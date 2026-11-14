<?php

namespace App\Support\Otp;

/** Pengirim OTP. Fase 1: email (mailer) dan WhatsApp lewat log; Fase 2: WhatsApp Business API. */
interface OtpSender
{
    public function send(string $identifier, string $channel, string $code, string $purpose): void;
}
