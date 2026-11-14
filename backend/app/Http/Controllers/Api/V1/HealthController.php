<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * NFR-07 Endpoint pemantauan uptime (UptimeRobot, Better Stack, dll.). Tanpa detail sensitif.
 * 200 bila database dan cache sehat; 503 bila tidak. Penjadwal & backup dilaporkan sebagai peringatan.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'down';
        }
        try {
            Cache::put('health:ping', 1, 10);
            $checks['cache'] = Cache::get('health:ping') === 1 ? 'ok' : 'down';
        } catch (\Throwable) {
            $checks['cache'] = 'down';
        }
        $beat = Cache::get('scheduler:heartbeat');
        $checks['scheduler'] = $beat && Carbon::parse($beat)->gt(now()->subMinutes(5)) ? 'ok' : 'stale';
        $backup = Cache::get('backup:last');
        $checks['backup'] = $backup && Carbon::parse($backup['at'])->gt(now()->subHours(26)) ? 'ok' : 'stale';
        $healthy = $checks['database'] === 'ok' && $checks['cache'] === 'ok';

        return response()->json(['status' => $healthy ? 'ok' : 'down', 'checks' => $checks, 'time' => now()->toIso8601String()], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
