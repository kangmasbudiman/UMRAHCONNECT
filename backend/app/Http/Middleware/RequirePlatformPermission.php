<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** platform:<permission> — hanya platform_role; pengguna non-platform mendapat 404 (rute tidak diungkap). */
class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        if (! $user->platform_role) {
            throw new NotFoundHttpException;
        }
        if (! Permissions::platformAllows($user, $permission)) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        return $next($request);
    }
}
