<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Agregat ulasan terverifikasi (hanya yang tampil). */
class Ratings
{
    /** @return array<string, array{avg: float, count: int}> keyed by package_id */
    public static function forPackages(array $packageIds): array
    {
        if (! $packageIds) {
            return [];
        }

        return DB::table('reviews')->whereIn('package_id', $packageIds)->where('status', 'published')
            ->selectRaw('package_id, round(avg(rating)::numeric, 1) a, count(*) c')->groupBy('package_id')->get()
            ->mapWithKeys(fn ($r) => [$r->package_id => ['avg' => (float) $r->a, 'count' => (int) $r->c]])->all();
    }

    /** @return array{avg: ?float, count: int} */
    public static function forTenant(string $tenantId): array
    {
        $r = DB::table('reviews')->where('tenant_id', $tenantId)->where('status', 'published')->selectRaw('round(avg(rating)::numeric, 1) a, count(*) c')->first();

        return ['avg' => $r->c ? (float) $r->a : null, 'count' => (int) $r->c];
    }
}
