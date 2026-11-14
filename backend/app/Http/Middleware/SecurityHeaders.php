<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * NFR-10 / OWASP A05: header keamanan untuk setiap respons API.
 * Respons yang memuat data pengguna tidak boleh disimpan cache bersama (proxy/CDN).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // CSP ketat hanya untuk JSON; PDF/gambar dibuka penampil bawaan browser.
        if (str_contains((string) $h->get('Content-Type'), 'json') && ! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($request->user() && ! str_contains((string) $h->get('Cache-Control'), 'public')) {
            $h->set('Cache-Control', 'private, no-store');
        }
        $h->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By'); // ditambahkan PHP sendiri (expose_php); di produksi juga set expose_php=Off
        }

        return $response;
    }
}
