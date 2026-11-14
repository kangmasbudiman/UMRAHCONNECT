<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Memberi setiap permintaan request_id untuk penelusuran error (API Documentation §3). */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = 'req_'.Str::ulid();
        $request->attributes->set('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
