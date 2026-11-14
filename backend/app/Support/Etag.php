<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * ETag berbasis isi untuk kontrol konkurensi optimistis (API Documentation: If-Match pada PATCH paket,
 * keberangkatan, dan harga). Berbasis isi, bukan updated_at, agar dua perubahan dalam detik yang sama tetap terdeteksi.
 */
class Etag
{
    public static function of(array $representation): string
    {
        return '"'.substr(sha1(json_encode($representation)), 0, 20).'"';
    }

    /** 428 bila If-Match tidak dikirim, 412 bila data sudah diubah orang lain. */
    public static function require(Request $request, array $current): void
    {
        $given = $request->header('If-Match');
        if ($given === null || $given === '') {
            throw new ApiException(428, 'precondition_required', 'Muat ulang halaman lalu simpan lagi (header If-Match wajib).');
        }
        $given = preg_replace('/^W\//', '', trim($given));
        if (! hash_equals(self::of($current), $given)) {
            throw new ApiException(412, 'precondition_failed', 'Data ini baru saja diubah oleh anggota tim lain. Muat ulang halaman untuk melihat versi terbaru, lalu ulangi perubahan Anda.');
        }
    }
}
