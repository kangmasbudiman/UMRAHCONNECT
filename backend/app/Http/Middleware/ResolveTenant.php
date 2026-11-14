<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Konteks tenant dari parameter {tenant}: pengguna harus anggota aktif, selain itu 404
 * (API Documentation: data di luar lingkup dijawab 404, bukan 403). Mengisi TenantContext.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $param = $request->route('tenant');
        if ($param instanceof Tenant) {
            $tenant = $param;
        } else {
            if (! is_string($param) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $param)) {
                throw new NotFoundHttpException;
            }
            $tenant = Tenant::find($param) ?? throw new NotFoundHttpException;
        }
        $member = TenantMember::withoutTenantScope()->with('user')
            ->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('status', 'active')->first()
            ?? throw new NotFoundHttpException;

        app(TenantContext::class)->set($tenant->id);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('membership', $member);
        $request->route()->setParameter('tenant', $tenant);

        return $next($request);
    }
}
