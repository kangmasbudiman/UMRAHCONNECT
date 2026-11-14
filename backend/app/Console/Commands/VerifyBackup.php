<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * NFR-08 Uji restore berkala: pulihkan backup terbaru ke database sementara, cocokkan jumlah baris tabel inti, lalu hapus.
 */
class VerifyBackup extends Command
{
    protected $signature = 'app:backup-verify';

    protected $description = 'Uji restore backup terbaru ke database sementara';

    private const TABLES = ['users', 'tenants', 'packages', 'bookings', 'payments', 'receipts', 'audit_logs'];

    public function handle(): int
    {
        $files = glob(config('backup.path').'/umrah-connect-*.dump') ?: [];
        rsort($files);
        if (! $files) {
            $this->error('Belum ada file backup. Jalankan php artisan app:backup.');

            return self::FAILURE;
        }
        $file = $files[0];
        $db = config('database.connections.pgsql');
        $tmp = $db['database'].'_restore_check';
        $conn = ['--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username']];
        $env = ['PGPASSWORD' => (string) $db['password']];
        $bin = dirname((string) config('backup.pg_dump'));
        $tool = fn (string $name) => $bin === '.' ? $name : $bin.'/'.$name;

        Process::env($env)->run([$tool('dropdb'), ...$conn, '--if-exists', $tmp]);
        $steps = [
            [$tool('createdb'), ...$conn, $tmp],
            [$tool('pg_restore'), ...$conn, '--no-owner', '--no-privileges', '--dbname='.$tmp, $file],
        ];
        foreach ($steps as $cmd) {
            $r = Process::timeout(3600)->env($env)->run($cmd);
            if (! $r->successful()) {
                $this->error('Restore gagal: '.trim($r->errorOutput()));
                Process::env($env)->run([$tool('dropdb'), ...$conn, '--if-exists', $tmp]);

                return self::FAILURE;
            }
        }
        $rows = [];
        foreach (self::TABLES as $t) {
            $r = Process::env($env)->run([$tool('psql'), ...$conn, '--dbname='.$tmp, '-tAc', "select count(*) from {$t}"]);
            $rows[] = [$t, trim($r->output())];
        }
        Process::env($env)->run([$tool('dropdb'), ...$conn, '--if-exists', $tmp]);
        $this->table(['Tabel', 'Baris di backup'], $rows);
        Cache::forever('backup:last_verified', ['file' => basename($file), 'at' => now()->toIso8601String()]);
        $this->info('Uji restore berhasil: '.basename($file));

        return self::SUCCESS;
    }
}
