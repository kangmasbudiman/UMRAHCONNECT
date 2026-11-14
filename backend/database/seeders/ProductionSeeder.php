<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data awal produksi (Roadmap S7): hanya pengaturan platform, tanpa akun atau data contoh.
 * Aman dijalankan berulang; nilai yang sudah diubah admin tidak ditimpa.
 * Akun Super Admin pertama dibuat dengan: php artisan platform:create-admin {email}
 */
class ProductionSeeder extends Seeder
{
    public const DEFAULTS = [
        'attribution.window_days' => 30,                 // CR-01 jendela cookie referral
        'booking.dp_deadline_hours_default' => 48,       // BR-06 tenggat DP bawaan
        'booking.max_participants' => 10,
        'commission.earn_on_default' => 'fully_paid',    // CR-02
        'support_access.max_minutes' => 60,              // User Role §8
        'legal.terms_version' => null,                   // diisi dari config saat seeding
        'legal.privacy_version' => null,
    ];

    public function run(): void
    {
        $values = self::DEFAULTS;
        $values['legal.terms_version'] = config('app.legal.terms_version');
        $values['legal.privacy_version'] = config('app.legal.privacy_version');
        foreach ($values as $key => $value) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => json_encode($value), 'updated_at' => now()]);
        }
    }
}
