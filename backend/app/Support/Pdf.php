<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Tenant;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/** Invoice dan kwitansi PDF (FR-FIN-02, FR-FIN-05), dibuat saat diunduh dari data tersimpan. */
class Pdf
{
    public static function rupiah(int $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    public static function terbilang(int $n): string
    {
        $w = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        $f = function (int $x) use (&$f, $w): string {
            return match (true) {
                $x < 12 => $w[$x],
                $x < 20 => $f($x - 10).' belas',
                $x < 100 => $f(intdiv($x, 10)).' puluh '.$f($x % 10),
                $x < 200 => 'seratus '.$f($x - 100),
                $x < 1000 => $f(intdiv($x, 100)).' ratus '.$f($x % 100),
                $x < 2000 => 'seribu '.$f($x - 1000),
                $x < 1_000_000 => $f(intdiv($x, 1000)).' ribu '.$f($x % 1000),
                $x < 1_000_000_000 => $f(intdiv($x, 1_000_000)).' juta '.$f($x % 1_000_000),
                default => $f(intdiv($x, 1_000_000_000)).' miliar '.$f($x % 1_000_000_000),
            };
        };

        return ucfirst(trim(preg_replace('/\s+/', ' ', $f($n)))).' rupiah';
    }

    public static function invoice(Invoice $inv): Response
    {
        $b = Booking::withoutTenantScope()->with(['package', 'departure', 'booker', 'participants.pilgrim'])->findOrFail($inv->booking_id);

        return self::render('pdf.invoice', ['inv' => $inv->loadMissing('bankAccount'), 'b' => $b, 't' => Tenant::findOrFail($inv->tenant_id)],
            'invoice-'.str_replace('/', '-', $inv->number).'.pdf');
    }

    public static function receipt(Receipt $r): Response
    {
        $p = $r->payment()->with('invoice')->first();
        $b = Booking::withoutTenantScope()->with(['package', 'departure', 'booker'])->findOrFail($p->invoice->booking_id);

        return self::render('pdf.receipt', ['r' => $r, 'p' => $p, 'b' => $b, 't' => Tenant::findOrFail($r->tenant_id)],
            'kwitansi-'.str_replace('/', '-', $r->number).'.pdf');
    }

    private static function render(string $view, array $data, string $filename): Response
    {
        $opt = new Options;
        $opt->set('isRemoteEnabled', false);
        $opt->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opt);
        $pdf->loadHtml(view($view, $data)->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
