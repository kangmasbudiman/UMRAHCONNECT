<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pemeriksaan kesiapan produksi sebelum Gate MVP / go-live (Roadmap S7, Deployment).
 * Keluar dengan kode gagal bila ada pemeriksaan wajib yang tidak lolos.
 */
class Preflight extends Command
{
    protected $signature = 'app:preflight {--production : perlakukan lingkungan ini sebagai produksi walau APP_ENV berbeda}';

    protected $description = 'Periksa konfigurasi dan data sebelum go-live';

    public function handle(): int
    {
        $prod = $this->option('production') || app()->isProduction();
        $checks = [];
        $add = function (string $label, bool $ok, string $fix, bool $required = true) use (&$checks) {
            $checks[] = [$ok ? 'LOLOS' : ($required ? 'GAGAL' : 'PERINGATAN'), $label, $ok ? '' : $fix];
        };

        $add('APP_KEY terisi', (bool) config('app.key'), 'Jalankan php artisan key:generate di server.');
        $add('APP_DEBUG mati', ! config('app.debug') || ! $prod, 'Set APP_DEBUG=false.');
        $add('APP_URL / web_url memakai https', ! $prod || (str_starts_with((string) config('app.url'), 'https://') && str_starts_with((string) config('app.web_url'), 'https://')), 'Set APP_URL dan WEB_URL ke alamat https.');
        $blind = (string) config('app.blind_index_key');
        $add('BLIND_INDEX_KEY kuat dan bukan nilai uji', strlen($blind) >= 32 && ! str_contains($blind, 'test'), 'Isi BLIND_INDEX_KEY dengan string acak ≥32 karakter. JANGAN diganti setelah ada data (NIK tidak bisa dicari lagi).');
        $add('2FA wajib untuk Owner/Finance/staf platform', (bool) config('permissions.enforce_two_factor') || ! $prod, 'Set AUTH_ENFORCE_2FA=true.');
        $add('Cookie sesi hanya lewat https', (bool) config('session.secure') || ! $prod, 'Set SESSION_SECURE_COOKIE=true.');
        $add('Mailer sungguhan (bukan log/array)', ! in_array(config('mail.default'), ['log', 'array'], true) || ! $prod, 'Set MAIL_MAILER=smtp (atau layanan email) beserta kredensialnya.');
        $add('Rate limit publik tidak dilonggarkan', (int) config('app.public_rate_limit') <= 120, 'Hapus PUBLIC_RATE_LIMIT (hanya untuk uji beban).');
        $add('Cache bukan array', config('cache.default') !== 'array', 'Set CACHE_STORE=database atau redis (rate limit & idempotensi butuh cache bersama).');

        try {
            DB::select('select 1');
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }
        $add('Database terhubung', $dbOk, 'Periksa DB_* di .env.');
        if ($dbOk) {
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys()
                ->diff(DB::table('migrations')->pluck('migration'));
            $add('Semua migrasi sudah dijalankan', $pending->isEmpty(), 'Jalankan php artisan migrate --force. Belum: '.$pending->join(', '));
            $add('Audit log append-only (trigger aktif)', DB::table('pg_trigger')->where('tgname', 'audit_logs_no_update_delete')->exists(), 'Jalankan migrasi 2026_11_14_100003_harden_audit_logs.');
            $add('Pengaturan platform terisi', DB::table('settings')->where('key', 'attribution.window_days')->exists(), 'Jalankan php artisan db:seed --class=ProductionSeeder --force.');
            $add('Ada Super Admin', User::where('platform_role', 'super_admin')->exists(), 'Jalankan php artisan platform:create-admin email@domain --role=super_admin.');
            $demo = User::where('email', 'like', '%@umrahconnect.test')->count();
            $add('Tidak ada akun data contoh', ! $prod || $demo === 0, "Ada $demo akun @umrahconnect.test. Basis data produksi harus bersih dari DemoSeeder.");
        }
        $add('Penjadwal berjalan (schedule:run tiap menit lewat cron)', false, 'Pastikan cron: * * * * * php artisan schedule:run. Tidak bisa dicek otomatis.', false);

        $this->table(['Hasil', 'Pemeriksaan', 'Perbaikan'], $checks);
        $failed = collect($checks)->where(0, 'GAGAL')->count();
        $failed ? $this->error("$failed pemeriksaan wajib gagal.") : $this->info('Semua pemeriksaan wajib lolos.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
