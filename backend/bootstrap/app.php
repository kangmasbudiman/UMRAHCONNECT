<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Bentuk error tunggal untuk API (API Documentation §3, skema Error). */
$apiError = function (Request $request, int $status, string $code, string $message, ?array $errors = null, ?array $details = null) {
    return response()->json(['error' => [
        'code' => $code,
        'message' => $message,
        'details' => $details,
        'errors' => $errors,
        'request_id' => $request->attributes->get('request_id'),
    ]], $status);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        // Server Next.js lokal meneruskan IP pengunjung lewat X-Forwarded-For (rate limit per pengunjung, bukan per server).
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        // Web (Next.js) memakai sesi cookie Sanctum SPA lewat proxy same-origin (API Documentation §2).
        $middleware->statefulApi();
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'perm' => \App\Http\Middleware\RequireTenantPermission::class,
            'platform' => \App\Http\Middleware\RequirePlatformPermission::class,
            '2fa' => \App\Http\Middleware\EnsureTwoFactorEnrolled::class,
            'contact.verified' => \App\Http\Middleware\EnsureContactVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $isApi = fn (Request $r) => $r->is('api/*');

        $exceptions->render(function (ApiException $e, Request $r) use ($apiError) {
            return $apiError($r, $e->status, $e->errorCode, $e->getMessage(), $e->errors, $e->details);
        });
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, Request $r) use ($apiError, $isApi) {
            return $isApi($r) ? $apiError($r, 419, 'csrf_mismatch', 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.') : null;
        });
        $exceptions->render(function (ValidationException $e, Request $r) use ($apiError, $isApi) {
            return $isApi($r) ? $apiError($r, 422, 'validation_failed', 'Data yang dikirim belum valid.', $e->errors()) : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($apiError, $isApi) {
            return $isApi($r) ? $apiError($r, 401, 'unauthenticated', 'Sesi Anda berakhir. Silakan masuk lagi.') : null;
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $r) use ($apiError, $isApi) {
            return $isApi($r) ? $apiError($r, 403, 'forbidden', 'Anda tidak memiliki akses untuk tindakan ini.') : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $r) use ($apiError, $isApi) {
            return $isApi($r) ? $apiError($r, 404, 'not_found', 'Data tidak ditemukan.') : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $r) use ($apiError, $isApi) {
            if (! $isApi($r)) {
                return null;
            }
            $resp = $apiError($r, 429, 'rate_limited', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.');
            foreach ($e->getHeaders() as $k => $v) {
                $resp->headers->set($k, $v);
            }

            return $resp;
        });
        $exceptions->render(function (Throwable $e, Request $r) use ($apiError, $isApi) {
            if (! $isApi($r)) {
                return null;
            }
            if ($e instanceof HttpExceptionInterface) {
                return $apiError($r, $e->getStatusCode(), 'bad_request', $e->getMessage() ?: 'Permintaan tidak valid.');
            }
            if (config('app.debug')) {
                return null; // tampilkan detail error di development
            }

            return $apiError($r, 500, 'server_error', 'Terjadi kendala di sistem kami. Coba lagi, atau hubungi kami dengan kode permintaan ini.');
        });
    })->create();
