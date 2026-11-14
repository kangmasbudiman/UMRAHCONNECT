<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/** perm:<permission> — cek peran anggota tenant aktif terhadap config/permissions.php. 'owner' = khusus Owner. */
class RequireTenantPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $member = $request->attributes->get('membership');
        $ok = $permission === 'owner'
            ? $member?->role === 'owner'
            : ($member && Permissions::tenantRoleAllows($member->role, $permission));
        if (! $ok) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
