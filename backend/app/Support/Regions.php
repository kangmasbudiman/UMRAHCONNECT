<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wilayah administratif Indonesia (tabel regions). Kode bertingkat: 15 → 15.71 → 15.71.01 → 15.71.01.1001.
 * Formulir hanya mengirim kode kelurahan/desa; induknya diturunkan dari kode itu sendiri sehingga
 * provinsi–kab/kota–kecamatan selalu konsisten dan tidak bisa dicampur dari daerah lain.
 */
class Regions
{
    public const LEVEL_NAMES = [1 => 'province', 2 => 'regency', 3 => 'district', 4 => 'village'];

    /** Anak langsung dari sebuah wilayah (null = daftar provinsi). Data statis, di-cache permanen. */
    public static function children(?string $parent): array
    {
        return Cache::rememberForever('regions:children:'.($parent ?? 'root'), fn () => DB::table('regions')
            ->when($parent === null, fn ($q) => $q->whereNull('parent_code'), fn ($q) => $q->where('parent_code', $parent))
            ->orderBy('name')->get(['code', 'name'])->map(fn ($r) => ['code' => $r->code, 'name' => $r->name])->all());
    }

    /** Kode berformat benar untuk tingkat tertentu (1–4). */
    public static function looksLike(string $code, int $level): bool
    {
        return (bool) preg_match([1 => '/^\d{2}$/', 2 => '/^\d{2}\.\d{2}$/', 3 => '/^\d{2}\.\d{2}\.\d{2}$/', 4 => '/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/'][$level], $code);
    }

    /**
     * Ubah kode kelurahan/desa menjadi kolom alamat lengkap (+ nama untuk kolom teks lama).
     * Melempar 422 dengan pesan di field $field bila kode tidak ada.
     *
     * @return array{province_code:string,regency_code:string,district_code:string,village_code:string,province_name:string,regency_name:string,district_name:string,village_name:string}
     */
    public static function resolveVillage(string $code, string $field = 'region_code'): array
    {
        if (! self::looksLike($code, 4)) {
            throw new ApiException(422, 'validation_failed', 'Pilih alamat sampai kelurahan/desa.', errors: [$field => ['Pilih provinsi, kab/kota, kecamatan, dan kelurahan/desa.']]);
        }
        $p = explode('.', $code);
        $codes = [$p[0], "{$p[0]}.{$p[1]}", "{$p[0]}.{$p[1]}.{$p[2]}", $code];
        $names = DB::table('regions')->whereIn('code', $codes)->pluck('name', 'code');
        if ($names->count() !== 4) {
            throw new ApiException(422, 'validation_failed', 'Kelurahan/desa tidak ditemukan.', errors: [$field => ['Kelurahan/desa tidak ditemukan. Pilih ulang dari daftar.']]);
        }

        return [
            'province_code' => $codes[0], 'regency_code' => $codes[1], 'district_code' => $codes[2], 'village_code' => $codes[3],
            'province_name' => $names[$codes[0]], 'regency_name' => $names[$codes[1]], 'district_name' => $names[$codes[2]], 'village_name' => $names[$codes[3]],
        ];
    }

    /** Peta kode → nama untuk banyak kode sekaligus (mis. ekspor manifes). */
    public static function names(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes)));

        return $codes ? DB::table('regions')->whereIn('code', $codes)->pluck('name', 'code')->all() : [];
    }

    /** Kolom kode saja (untuk disimpan ke tabel). */
    public static function columns(array $resolved): array
    {
        return array_intersect_key($resolved, array_flip(['province_code', 'regency_code', 'district_code', 'village_code']));
    }

    /**
     * Bentuk tampilan alamat dari sebuah model/objek yang punya kolom *_code.
     *
     * @return array{province:?array,regency:?array,district:?array,village:?array}|null
     */
    public static function describe(object $m): ?array
    {
        $codes = array_filter([
            'province' => $m->province_code ?? null, 'regency' => $m->regency_code ?? null,
            'district' => $m->district_code ?? null, 'village' => $m->village_code ?? null,
        ]);
        if (! $codes) {
            return null;
        }
        $names = DB::table('regions')->whereIn('code', array_values($codes))->pluck('name', 'code');

        $out = [];
        foreach (array_values(self::LEVEL_NAMES) as $k) {
            $out[$k] = isset($codes[$k]) ? ['code' => $codes[$k], 'name' => $names[$codes[$k]] ?? null] : null;
        }

        return $out;
    }
}
