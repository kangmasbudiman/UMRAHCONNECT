<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Pemindai virus unggahan lewat clamd (protokol INSTREAM). Aktif bila CLAMAV_SOCKET diisi.
 * Hasil: 'clean' | 'infected' | 'error' | 'disabled'.
 */
class VirusScanner
{
    public function enabled(): bool
    {
        return filled(config('services.clamav.socket'));
    }

    public function scan(string $path): string
    {
        if (! $this->enabled()) {
            return 'disabled';
        }
        try {
            $sock = @stream_socket_client((string) config('services.clamav.socket'), $errno, $errstr, 5);
            if (! $sock) {
                throw new \RuntimeException("clamd tidak bisa dihubungi: {$errstr}");
            }
            stream_set_timeout($sock, 30);
            fwrite($sock, "zINSTREAM\0");
            $fh = fopen($path, 'rb');
            while (! feof($fh)) {
                $chunk = fread($fh, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                fwrite($sock, pack('N', strlen($chunk)).$chunk);
            }
            fclose($fh);
            fwrite($sock, pack('N', 0));
            $reply = trim((string) stream_get_contents($sock), "\0\n ");
            fclose($sock);
            if (str_ends_with($reply, 'OK')) {
                return 'clean';
            }
            if (str_contains($reply, 'FOUND')) {
                Log::warning('Unggahan berbahaya ditolak: '.$reply);

                return 'infected';
            }
            throw new \RuntimeException('Balasan clamd tidak dikenal: '.$reply);
        } catch (\Throwable $e) {
            Log::error('Pemindaian virus gagal: '.$e->getMessage());

            return 'error';
        }
    }
}
