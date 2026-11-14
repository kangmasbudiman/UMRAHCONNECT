<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen berurutan tanpa celah per periode (tabel document_sequences, dikunci baris).
 * Harus dipanggil di dalam transaksi agar nomor ikut batal bila transaksi gagal.
 */
class DocumentNumber
{
    public static function next(?string $tenantId, string $docType, string $period): int
    {
        DB::statement(
            'INSERT INTO document_sequences (tenant_id, doc_type, period, last_number) VALUES (?, ?, ?, 0)
             ON CONFLICT (tenant_id, doc_type, period) DO NOTHING',
            [$tenantId, $docType, $period]
        );
        $row = DB::table('document_sequences')
            ->where('doc_type', $docType)->where('period', $period)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'))
            ->lockForUpdate()->first();
        DB::table('document_sequences')->where('id', $row->id)->update(['last_number' => $row->last_number + 1]);

        return $row->last_number + 1;
    }

    /** UC-2611-0001: kode booking global per bulan (WIB). */
    public static function bookingCode(): string
    {
        $now = now('Asia/Jakarta');

        return sprintf('UC-%s-%04d', $now->format('ym'), self::next(null, 'booking', $now->format('Ym')));
    }

    /** KW/PPIUA/2026/0007: nomor kwitansi per PPIU per tahun. */
    public static function receiptNumber(string $tenantId, string $tenantCode): string
    {
        $year = now('Asia/Jakarta')->format('Y');

        return sprintf('KW/%s/%s/%04d', $tenantCode, $year, self::next($tenantId, 'receipt', $year));
    }

    /** INV/PPIUA/2026/0012: nomor invoice per PPIU per tahun. */
    public static function invoiceNumber(string $tenantId, string $tenantCode): string
    {
        $year = now('Asia/Jakarta')->format('Y');

        return sprintf('INV/%s/%s/%04d', $tenantCode, $year, self::next($tenantId, 'invoice', $year));
    }
}
