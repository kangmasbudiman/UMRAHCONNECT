@php($R = fn ($n) => \App\Support\Pdf::rupiah((int) $n))
<html><head><meta charset="utf-8">@include('pdf._style')</head><body>
<div class="head">
  <table><tr>
    <td style="border:0;padding:0"><div class="brand">{{ $t->brand_name }}</div>
      <div class="muted">{{ $t->legal_name }} · Izin PPIU {{ $t->ppiu_license_no }}<br>{{ $t->address }}, {{ $t->city }} · {{ $t->phone }}</div></td>
    <td style="border:0;padding:0" class="right"><h1>KWITANSI</h1><div class="muted">{{ $r->number }}</div></td>
  </tr></table>
</div>
<table>
  <tr><td style="width:32%" class="muted">Telah diterima dari</td><td><b>{{ $p->sender_name ?: $b->booker->name }}</b></td></tr>
  <tr><td class="muted">Uang sejumlah</td><td><span class="big">{{ $R($r->amount) }}</span><br><i>{{ \App\Support\Pdf::terbilang((int) $r->amount) }}</i></td></tr>
  <tr><td class="muted">Untuk pembayaran</td><td>{{ ['dp' => 'Uang muka (DP)', 'final' => 'Pelunasan', 'full' => 'Pembayaran penuh'][$p->invoice->type] }} {{ $b->package->name }}<br>
    Booking {{ $b->code }} · invoice {{ $p->invoice->number }}<br>Berangkat {{ $b->departure->departure_date->translatedFormat('j M Y') }} dari {{ $b->departure->departure_city }}</td></tr>
  <tr><td class="muted">Cara bayar</td><td>{{ ['bank_transfer' => 'Transfer bank', 'cash' => 'Tunai', 'reallocation' => 'Pengalihan dana', 'gateway' => 'Payment gateway'][$p->method] }}{{ $p->sender_bank ? ' · '.$p->sender_bank : '' }}, tanggal {{ $p->paid_on->translatedFormat('j M Y') }}</td></tr>
  <tr><td class="muted">Diverifikasi</td><td>{{ $p->verified_at?->setTimezone('Asia/Jakarta')->translatedFormat('j M Y H:i') }} WIB</td></tr>
</table>
<table style="margin-top:14px"><tr>
  <td style="border:0"><div class="muted">Total booking {{ $R($b->total_amount) }} · total dibayar {{ $R($b->paid_amount) }} · sisa {{ $R(max(0, $b->total_amount - $b->paid_amount)) }}</div></td>
  <td style="border:0" class="right"><span class="stamp">DITERIMA {{ $R($r->amount) }}</span></td>
</tr></table>
<div class="foot">Kwitansi elektronik sah tanpa tanda tangan, diterbitkan sistem UMRAH CONNECT atas nama {{ $t->legal_name }} setelah pembayaran diverifikasi PPIU. Dicetak {{ now('Asia/Jakarta')->translatedFormat('j M Y H:i') }} WIB.</div>
</body></html>
