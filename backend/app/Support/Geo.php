<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/** Titik lokasi WGS84 (peta MapLibre/aplikasi ponsel) dan jarak garis lurus (haversine) tanpa PostGIS. */
class Geo
{
    /** Kotak wilayah Indonesia (sama dengan CHECK di database). */
    public const LAT = [-11.5, 6.5];

    public const LNG = [94.5, 141.5];

    /** Aturan validasi pasangan latitude/longitude. $required: 'required' | 'sometimes' | 'nullable'. */
    public static function rules(string $required): array
    {
        // 'sometimes' (PATCH) akan melewati required_with bila salah satu tidak dikirim; 'filled' + required_with
        // memastikan keduanya selalu berpasangan dan tidak bisa dikosongkan.
        $base = match ($required) {
            'nullable' => ['nullable'],
            'sometimes' => ['filled'],
            default => [$required],
        };

        return [
            'latitude' => [...$base, 'numeric', 'between:'.self::LAT[0].','.self::LAT[1], 'required_with:longitude'],
            'longitude' => [...$base, 'numeric', 'between:'.self::LNG[0].','.self::LNG[1], 'required_with:latitude'],
        ];
    }

    public static function messages(): array
    {
        $msg = 'Titik lokasi harus berada di wilayah Indonesia. Geser pin ke lokasi yang benar.';

        return [
            'latitude.required' => 'Tandai lokasi di peta.', 'longitude.required' => 'Tandai lokasi di peta.',
            'latitude.between' => $msg, 'longitude.between' => $msg,
            'latitude.required_with' => 'Tandai lokasi di peta.', 'longitude.required_with' => 'Tandai lokasi di peta.',
        ];
    }

    /** Bulatkan ke 6 desimal (±11 cm), cukup untuk lokasi kantor. */
    public static function round(array $data): array
    {
        foreach (['latitude', 'longitude'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $data[$k] = round((float) $data[$k], 6);
            }
        }

        return $data;
    }

    /** Ekspresi SQL jarak (km) dari titik ke kolom latitude/longitude tabel. */
    public static function distanceSql(string $table): string
    {
        return "(6371 * 2 * asin(sqrt(power(sin(radians(({$table}.latitude - ?) / 2)), 2)"
            ." + cos(radians(?)) * cos(radians({$table}.latitude)) * power(sin(radians(({$table}.longitude - ?) / 2)), 2))))";
    }

    /** Batasi ke radius (km) dan urutkan dari yang terdekat; menambah kolom distance_km. */
    public static function nearest(Builder $query, string $table, float $lat, float $lng, float $radiusKm): Builder
    {
        $expr = self::distanceSql($table);
        $bind = [$lat, $lat, $lng];
        // Saringan kotak kasar dulu (pakai indeks), lalu jarak tepat.
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / max(1.0, 111.0 * cos(deg2rad($lat)));

        return $query->whereNotNull("{$table}.latitude")
            ->whereBetween("{$table}.latitude", [$lat - $dLat, $lat + $dLat])
            ->whereBetween("{$table}.longitude", [$lng - $dLng, $lng + $dLng])
            ->whereRaw("{$expr} <= ?", [...$bind, $radiusKm])
            ->selectRaw("round(cast({$expr} as numeric), 1) as distance_km", $bind)
            ->reorder()->orderByRaw($expr, $bind);
    }

    /** "lat,lng" → [lat, lng] atau null bila format/rentang salah. */
    public static function parsePoint(?string $s): ?array
    {
        if (! $s || ! preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $s, $m)) {
            return null;
        }
        [$lat, $lng] = [(float) $m[1], (float) $m[2]];

        return $lat >= self::LAT[0] && $lat <= self::LAT[1] && $lng >= self::LNG[0] && $lng <= self::LNG[1] ? [$lat, $lng] : null;
    }
}
