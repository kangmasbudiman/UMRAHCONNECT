<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

/** Akun harus memverifikasi email atau nomor HP (OTP) sebelum mendaftarkan PPIU atau bertransaksi. */
class EnsureContactVerified
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u->email_verified_at && ! $u->phone_verified_at) {
            throw new ApiException(403, 'verification_required', 'Verifikasi email atau nomor HP Anda dulu dengan kode OTP.');
        }

        return $next($request);
    }
}
