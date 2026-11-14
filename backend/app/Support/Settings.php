<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pengaturan platform (tabel settings, FR-ADM-09). Hanya kunci di EDITABLE yang bisa diubah Super Admin;
 * nilai bawaan dipakai bila baris belum ada (mis. sebelum ProductionSeeder dijalankan).
 */
class Settings
{
    /** key => [label, aturan validasi, bawaan, keterangan] */
    public const EDITABLE = [
        'attribution.window_days' => ['Jendela atribusi link agen (hari)', ['required', 'integer', 'min:1', 'max:90'], 30,
            'Berapa lama klik link agen masih dihitung saat jamaah booking (CR-01).'],
        'booking.dp_deadline_hours_default' => ['Tenggat DP bawaan PPIU baru (jam)', ['required', 'integer', 'min:6', 'max:168'], 48,
            'Dipakai sebagai bawaan untuk PPIU yang baru mendaftar. PPIU lama tidak berubah.'],
        'commission.earn_on_default' => ['Komisi diperoleh saat (bawaan PPIU baru)', ['required', 'in:dp_verified,fully_paid'], 'fully_paid',
            'Kapan komisi agen dianggap diperoleh: saat DP terverifikasi atau saat lunas. Hanya untuk PPIU baru.'],
        'support_access.max_minutes' => ['Lama sesi akses dukungan (menit)', ['required', 'integer', 'min:15', 'max:120'], 60,
            'Batas waktu akses baca-saja staf platform ke data PPIU.'],
    ];

    public static function get(string $key): mixed
    {
        $default = self::EDITABLE[$key][2] ?? null;
        $raw = Cache::rememberForever("settings:$key", fn () => DB::table('settings')->where('key', $key)->value('value') ?? '__none__');

        return $raw === '__none__' ? $default : json_decode($raw, true);
    }

    public static function rules(string $key): array
    {
        return ['value' => self::EDITABLE[$key][1]];
    }

    public static function put(string $key, mixed $value, ?string $userId): void
    {
        DB::table('settings')->upsert([['key' => $key, 'value' => json_encode($value), 'updated_by' => $userId, 'updated_at' => now()]], ['key'], ['value', 'updated_by', 'updated_at']);
        Cache::forget("settings:$key");
    }

    public static function present(string $key): array
    {
        [$label, , $default, $help] = self::EDITABLE[$key];
        $row = DB::table('settings')->leftJoin('users', 'users.id', '=', 'settings.updated_by')->where('key', $key)->first(['settings.updated_at', 'users.name']);

        return ['key' => $key, 'label' => $label, 'help' => $help, 'value' => self::get($key), 'default' => $default,
            'updated_at' => $row?->updated_at ? \Illuminate\Support\Carbon::parse($row->updated_at)->toIso8601String() : null, 'updated_by' => $row?->name];
    }
}
