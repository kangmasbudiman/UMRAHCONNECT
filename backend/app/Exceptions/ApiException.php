<?php

namespace App\Exceptions;

use RuntimeException;

/** Error bisnis dengan kode stabil (API Documentation §3: daftar kode error). */
class ApiException extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public ?array $details = null, public ?array $errors = null)
    {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message, ?array $details = null): self
    {
        return new self(409, $code, $message, $details);
    }

    public static function unprocessable(string $code, string $message, ?array $details = null): self
    {
        return new self(422, $code, $message, $details);
    }
}
