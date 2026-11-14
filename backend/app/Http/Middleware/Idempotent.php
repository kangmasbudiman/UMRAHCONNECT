<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key untuk POST uang/kursi (API Documentation §1): kunci sama dalam 24 jam dari pengguna yang sama
 * mengembalikan respons pertama, sehingga klik ganda atau jaringan putus tidak membuat booking dobel.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key');
        if (! Str::isUuid($key)) {
            throw new ApiException(400, 'idempotency_key_required', 'Header Idempotency-Key (UUID) wajib untuk permintaan ini.');
        }
        $cacheKey = 'idem:'.($request->user()?->id ?? $request->ip()).':'.$request->path().':'.$key;
        $hash = hash('sha256', $request->getContent());

        return Cache::lock($cacheKey.':lock', 30)->block(15, function () use ($cacheKey, $hash, $request, $next) {
            if ($hit = Cache::get($cacheKey)) {
                if ($hit['hash'] !== $hash) {
                    throw new ApiException(422, 'idempotency_key_reused', 'Idempotency-Key ini sudah dipakai untuk permintaan berbeda.');
                }

                return response($hit['body'], $hit['status'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
            }
            $response = $next($request);
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, ['hash' => $hash, 'status' => $response->getStatusCode(), 'body' => $response->getContent()], now()->addDay());
            }

            return $response;
        });
    }
}
