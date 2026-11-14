<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * NFR-08 Backup harian: pg_dump format custom (terkompresi) ke BACKUP_PATH, retensi BACKUP_RETENTION_DAYS (bawaan 30).
 * Salinan di luar server (object storage) tetap wajib — atur sinkronisasi folder ini di dokumen Deployment.
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:backup';

    protected $description = 'Backup database (pg_dump) dengan retensi';

    public function handle(): int
    {
        $dir = config('backup.path');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            $this->error("Folder backup tidak bisa dibuat: {$dir}");

            return self::FAILURE;
        }
        $db = config('database.connections.pgsql');
        $file = $dir.'/umrah-connect-'.now()->utc()->format('Ymd-His').'.dump';
        $result = Process::timeout(3600)->env(['PGPASSWORD' => (string) $db['password']])->run([
            config('backup.pg_dump'), '--format=custom', '--no-owner', '--no-privileges',
            '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'], '--file='.$file, $db['database'],
        ]);
        if (! $result->successful() || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);
            $this->error('Backup gagal: '.trim($result->errorOutput()));

            return self::FAILURE;
        }
        chmod($file, 0600);
        Cache::forever('backup:last', ['file' => basename($file), 'at' => now()->toIso8601String(), 'bytes' => filesize($file)]);

        $cutoff = now()->subDays((int) config('backup.retention_days'))->getTimestamp();
        $removed = 0;
        foreach (glob($dir.'/umrah-connect-*.dump') ?: [] as $old) {
            if (filemtime($old) < $cutoff) {
                unlink($old);
                $removed++;
            }
        }
        $this->info(sprintf('Backup %s (%.1f MB). %d backup lama dihapus.', basename($file), filesize($file) / 1048576, $removed));

        return self::SUCCESS;
    }
}
