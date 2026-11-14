<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;

/** Peran wajib 2FA (Super Admin, Admin Platform, Owner, Finance) harus mengaktifkan 2FA sebelum memakai konteksnya. */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (Permissions::enforceTwoFactor() && ! $user->two_factor_enabled) {
            $member = $request->attributes->get('membership');
            $platform = $request->is('api/v1/platform/*') && $user->platform_role;
            $tenantRole = $member && in_array($member->role, config('permissions.two_factor_required.tenant'), true);
            if ($platform || $tenantRole) {
                throw new ApiException(403, 'two_factor_required', 'Aktifkan verifikasi dua langkah (2FA) di halaman Akun sebelum melanjutkan.');
            }
        }

        return $next($request);
    }
}
