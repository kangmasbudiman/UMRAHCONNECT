<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

/** Konteks agen: pengguna harus punya profil agen aktif (FR-AGT). */
class EnsureAgent
{
    public function handle(Request $request, Closure $next)
    {
        $agent = $request->user()->agent()->with('user')->first();
        if (! $agent) {
            throw new ApiException(403, 'agent_required', 'Lengkapi pendaftaran agen terlebih dahulu.');
        }
        if ($agent->status !== 'active') {
            throw new ApiException(403, 'agent_suspended', 'Akun agen Anda sedang ditangguhkan. Hubungi tim UMRAH CONNECT.');
        }
        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
