<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Laporan PPIU (FR-PPIU-16, FR-FIN-10). Rentang tanggal dalam WIB. Tanpa NIK, paspor, atau rekening utuh.
 * Bentuk: columns (key + label), rows, totals.
 */
class ReportService
{
    /** @return array{0: Carbon, 1: Carbon} */
    private function range(?string $from, ?string $to): array
    {
        $f = Carbon::parse($from ?? now('Asia/Jakarta')->startOfMonth()->toDateString(), 'Asia/Jakarta')->startOfDay();
        $t = Carbon::parse($to ?? now('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->endOfDay();
        if ($f->gt($t) || $f->diffInDays($t) > 366) {
            throw ApiException::unprocessable('range_invalid', 'Rentang tanggal tidak valid (maksimal 1 tahun).');
        }

        return [$f->utc(), $t->utc()];
    }

    public function sales(string $tenantId, string $groupBy, ?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);
        [$keyExpr, $labelExpr, $join, $label] = match ($groupBy) {
            'package' => ['p.id', 'p.name', 'join packages p on p.id = b.package_id', 'Paket'],
            'departure' => ['d.id', "concat(p.name, ' — ', to_char(d.departure_date, 'DD-MM-YYYY'), ' ', d.departure_city)", 'join departures d on d.id = b.departure_id join packages p on p.id = b.package_id', 'Keberangkatan'],
            'agent' => ["coalesce(a.id::text, 'none')", "coalesce(u.name || ' (' || a.code || ')', 'Tanpa agen')", 'left join agents a on a.id = b.agent_id left join users u on u.id = a.user_id', 'Agen'],
        };
        $rows = DB::select("
            select {$labelExpr} as name,
                   count(*) filter (where b.status not in ('expired','cancelled')) as bookings,
                   coalesce(sum(b.participant_count) filter (where b.status not in ('expired','cancelled')), 0) as participants,
                   coalesce(sum(b.total_amount) filter (where b.status not in ('expired','cancelled')), 0) as sales_amount,
                   coalesce(sum(b.paid_amount) filter (where b.status not in ('expired','cancelled')), 0) as paid_amount,
                   count(*) filter (where b.status in ('expired','cancelled')) as lost
            from bookings b {$join}
            where b.tenant_id = ? and b.created_at between ? and ?
            group by {$keyExpr}, {$labelExpr}
            order by sales_amount desc", [$tenantId, $f, $t]);
        $rows = array_map(fn ($r) => [
            'name' => $r->name, 'bookings' => (int) $r->bookings, 'participants' => (int) $r->participants,
            'sales_amount' => (int) $r->sales_amount, 'paid_amount' => (int) $r->paid_amount, 'lost' => (int) $r->lost,
        ], $rows);

        return [
            'columns' => [['key' => 'name', 'label' => $label], ['key' => 'bookings', 'label' => 'Booking aktif'], ['key' => 'participants', 'label' => 'Jamaah'],
                ['key' => 'sales_amount', 'label' => 'Nilai penjualan', 'money' => true], ['key' => 'paid_amount', 'label' => 'Sudah dibayar', 'money' => true],
                ['key' => 'lost', 'label' => 'Kedaluwarsa/batal']],
            'rows' => $rows,
            'totals' => ['bookings' => array_sum(array_column($rows, 'bookings')), 'participants' => array_sum(array_column($rows, 'participants')),
                'sales_amount' => array_sum(array_column($rows, 'sales_amount')), 'paid_amount' => array_sum(array_column($rows, 'paid_amount')), 'lost' => array_sum(array_column($rows, 'lost'))],
        ];
    }

    public function finance(string $tenantId, string $type, ?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        return match ($type) {
            'receipts' => $this->wrap(DB::select("
                select to_char(p.verified_at at time zone 'Asia/Jakarta', 'YYYY-MM-DD') as date, r.number as receipt_no, b.code as booking_code,
                       u.name as payer, p.method, i.type as invoice_type, r.amount
                from receipts r join payments p on p.id = r.payment_id join invoices i on i.id = p.invoice_id
                join bookings b on b.id = i.booking_id join users u on u.id = b.booker_user_id
                where r.tenant_id = ? and p.verified_at between ? and ? order by p.verified_at", [$tenantId, $f, $t]),
                [['key' => 'date', 'label' => 'Tanggal verifikasi'], ['key' => 'receipt_no', 'label' => 'No. kwitansi'], ['key' => 'booking_code', 'label' => 'Booking'],
                    ['key' => 'payer', 'label' => 'Pemesan'], ['key' => 'method', 'label' => 'Cara bayar'], ['key' => 'invoice_type', 'label' => 'Tagihan'], ['key' => 'amount', 'label' => 'Nominal', 'money' => true]], ['amount']),
            'receivables' => $this->wrap(DB::select("
                select b.code as booking_code, u.name as booker, pk.name as package, to_char(d.departure_date, 'YYYY-MM-DD') as departure_date,
                       b.status, b.total_amount, b.paid_amount, b.total_amount - b.paid_amount as outstanding,
                       (select to_char(min(i.due_at) at time zone 'Asia/Jakarta', 'YYYY-MM-DD') from invoices i where i.booking_id = b.id and i.status in ('unpaid','partial')) as next_due,
                       case when exists (select 1 from invoices i where i.booking_id = b.id and i.status in ('unpaid','partial') and i.due_at < now()) then 'Ya' else 'Tidak' end as overdue
                from bookings b join users u on u.id = b.booker_user_id join departures d on d.id = b.departure_id join packages pk on pk.id = b.package_id
                where b.tenant_id = ? and b.status in ('awaiting_dp','dp_verified') order by next_due nulls last", [$tenantId]),
                [['key' => 'booking_code', 'label' => 'Booking'], ['key' => 'booker', 'label' => 'Pemesan'], ['key' => 'package', 'label' => 'Paket'], ['key' => 'departure_date', 'label' => 'Berangkat'],
                    ['key' => 'status', 'label' => 'Status'], ['key' => 'total_amount', 'label' => 'Total', 'money' => true], ['key' => 'paid_amount', 'label' => 'Dibayar', 'money' => true],
                    ['key' => 'outstanding', 'label' => 'Sisa', 'money' => true], ['key' => 'next_due', 'label' => 'Jatuh tempo terdekat'], ['key' => 'overdue', 'label' => 'Lewat tempo']], ['total_amount', 'paid_amount', 'outstanding']),
            'commissions_due' => $this->wrap(DB::select("
                select u.name || ' (' || a.code || ')' as agent, count(*) as commissions, sum(c.amount) as amount,
                       sum(c.amount) filter (where c.status = 'earned') as earned, sum(c.amount) filter (where c.status = 'approved') as approved
                from commissions c join agents a on a.id = c.agent_id join users u on u.id = a.user_id
                where c.tenant_id = ? and c.status in ('earned','approved') group by u.name, a.code order by amount desc", [$tenantId]),
                [['key' => 'agent', 'label' => 'Agen'], ['key' => 'commissions', 'label' => 'Jumlah komisi'], ['key' => 'earned', 'label' => 'Menunggu persetujuan', 'money' => true],
                    ['key' => 'approved', 'label' => 'Siap dibayar', 'money' => true], ['key' => 'amount', 'label' => 'Total terutang', 'money' => true]], ['earned', 'approved', 'amount']),
            'refunds' => $this->wrap(DB::select("
                select to_char(r.paid_on, 'YYYY-MM-DD') as date, b.code as booking_code, r.type, r.amount, t.code as target_booking, r.note
                from refunds r join bookings b on b.id = r.booking_id left join bookings t on t.id = r.target_booking_id
                where r.tenant_id = ? and r.created_at between ? and ? order by r.paid_on", [$tenantId, $f, $t]),
                [['key' => 'date', 'label' => 'Tanggal'], ['key' => 'booking_code', 'label' => 'Booking'], ['key' => 'type', 'label' => 'Jenis'], ['key' => 'amount', 'label' => 'Nominal', 'money' => true],
                    ['key' => 'target_booking', 'label' => 'Dialihkan ke'], ['key' => 'note', 'label' => 'Catatan']], ['amount']),
        };
    }

    private function wrap(array $rows, array $columns, array $sumKeys): array
    {
        $labels = ['bank_transfer' => 'Transfer bank', 'cash' => 'Tunai', 'reallocation' => 'Pengalihan dana', 'gateway' => 'Gateway',
            'dp' => 'DP', 'final' => 'Pelunasan', 'full' => 'Penuh', 'awaiting_dp' => 'Menunggu DP', 'dp_verified' => 'DP terverifikasi'];
        $rows = array_map(function ($r) use ($labels, $columns) {
            $out = [];
            foreach ($columns as $c) {
                $v = $r->{$c['key']} ?? null;
                $out[$c['key']] = ! empty($c['money']) ? (int) $v : (in_array($c['key'], ['method', 'invoice_type', 'type', 'status'], true) ? ($labels[$v] ?? $v) : $v);
            }

            return $out;
        }, $rows);
        $totals = [];
        foreach ($sumKeys as $k) {
            $totals[$k] = array_sum(array_column($rows, $k));
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /** CSV ber-BOM, pemisah titik koma (cocok untuk Excel berlokal Indonesia). */
    public static function csv(array $report, string $filename)
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_column($report['columns'], 'label'), ';');
        foreach ($report['rows'] as $r) {
            fputcsv($out, array_map(fn ($c) => $r[$c['key']] ?? '', $report['columns']), ';');
        }
        if (! empty($report['totals'])) {
            fputcsv($out, array_map(fn ($c, $i) => $i === 0 ? 'TOTAL' : ($report['totals'][$c['key']] ?? ''), $report['columns'], array_keys($report['columns'])), ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }
}
