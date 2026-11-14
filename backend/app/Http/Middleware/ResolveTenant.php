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
            ?? $this->supportMember($request, $tenant)
            ?? $this->denied($request, $tenant);

        app(TenantContext::class)->set($tenant->id);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('membership', $member);
        $request->route()->setParameter('tenant', $tenant);

        return $next($request);
    }

    /** AZ-01: percobaan membuka data tenant lain dicatat di audit log, tetapi dijawab 404 agar keberadaan data tidak bocor. */
    private function denied(Request $request, Tenant $tenant): never
    {
        \App\Support\Audit::log('tenant.access_denied', $tenant, null, ['method' => $request->method(), 'path' => '/'.$request->path()], null, $tenant->id, $request->user()->platform_role ? 'platform' : 'tenant');

        throw new NotFoundHttpException;
    }

    /** Akses dukungan staf platform: sesi aktif miliknya untuk tenant ini, hanya GET, tercatat di audit log. */
    private function supportMember(Request $request, Tenant $tenant): ?TenantMember
    {
        $sid = $request->header('X-Support-Session');
        $user = $request->user();
        if (! $sid || ! $user->platform_role || ! \Illuminate\Support\Str::isUuid($sid)) {
            return null;
        }
        $s = \App\Models\SupportSession::whereKey($sid)->where('admin_user_id', $user->id)->where('tenant_id', $tenant->id)->first();
        if (! $s || ! $s->isActive()) {
            throw new \App\Exceptions\ApiException(403, 'support_session_expired', 'Sesi akses dukungan sudah berakhir. Buka sesi baru bila masih diperlukan.');
        }
        if (! $request->isMethod('GET')) {
            throw new \App\Exceptions\ApiException(403, 'support_read_only', 'Akses dukungan hanya untuk melihat data.');
        }
        \App\Support\Audit::log('support.viewed', $s, null, ['path' => '/'.$request->path()], null, $tenant->id, 'platform');
        $m = new TenantMember(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'support', 'status' => 'active']);
        $m->setRelation('user', $user);

        return $m;
    }
}
