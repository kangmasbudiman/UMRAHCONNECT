<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Paginasi kursor opaque (API Documentation §1): ?limit=20&cursor=<opaque>, meta.next_cursor null di halaman terakhir.
 * Implementasi MVP memakai offset yang dikodekan; klien tidak boleh menafsirkan isinya.
 */
class OffsetCursor
{
    /** @return array{0: Collection, 1: array{next_cursor: ?string, limit: int}} */
    public static function paginate(Builder $query, Request $request, int $default = 20, int $max = 100): array
    {
        $limit = max(1, min($max, (int) $request->query('limit', $default)));
        $offset = 0;
        if ($cursor = $request->query('cursor')) {
            $decoded = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
            $offset = max(0, (int) ($decoded['o'] ?? 0));
        }
        $rows = $query->offset($offset)->limit($limit + 1)->get();
        $next = null;
        if ($rows->count() > $limit) {
            $rows = $rows->slice(0, $limit)->values();
            $next = rtrim(strtr(base64_encode(json_encode(['o' => $offset + $limit])), '+/', '-_'), '=');
        }

        return [$rows, ['next_cursor' => $next, 'limit' => $limit]];
    }
}
