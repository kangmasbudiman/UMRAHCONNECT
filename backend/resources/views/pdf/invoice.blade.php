@php($R = fn ($n) => \App\Support\Pdf::rupiah((int) $n))
<html><head><meta charset="utf-8">@include('pdf._style')</head><body>
<div class="head">
  <table><tr>
    <td style="border:0;padding:0"><div class="brand">{{ $t->brand_name }}</div>
      <div class="muted">{{ $t->legal_name }} · Izin PPIU {{ $t->ppiu_license_no }}<br>{{ $t->address }}, {{ $t->city }} · {{ $t->phone }}</div></td>
    <td style="border:0;padding:0" class="right"><h1>INVOICE</h1><div class="muted">{{ $inv->number }}</div></td>
  </tr></table>
</div>
<table style="margin-bottom:12px"><tr>
  <td style="border:0;width:50%"><div class="muted">Ditagihkan kepada</div><b>{{ $b->booker->name }}</b><br>{{ $b->booker->phone ?? $b->booker->email }}</td>
  <td style="border:0" class="right"><div class="muted">Kode booking</div><b>{{ $b->code }}</b><br>
    Jenis: <b>{{ ['dp' => 'Uang muka (DP)', 'final' => 'Pelunasan', 'full' => 'Pembayaran penuh'][$inv->type] }}</b><br>
    Jatuh tempo: <b>{{ $inv->due_at->setTimezone('Asia/Jakarta')->translatedFormat('j M Y H:i') }} WIB</b></td>
</tr></table>
<div class="box" style="margin-bottom:12px"><b>{{ $b->package->name }}</b><br>
  <span class="muted">Berangkat {{ $b->departure->departure_date->translatedFormat('j M Y') }} dari {{ $b->departure->departure_city }} · pulang {{ $b->departure->return_date->translatedFormat('j M Y') }}</span></div>
<table>
  <tr><th>Jamaah</th><th>Kamar</th><th class="right">Harga</th></tr>
  @foreach ($b->participants as $pt)
    <tr><td>{{ $pt->pilgrim->full_name }}</td><td>{{ ucfirst($pt->room_type) }}</td><td class="right">{{ $R($pt->price_amount) }}</td></tr>
  @endforeach
  <tr><td colspan="2" class="right"><b>Total booking</b></td><td class="right"><b>{{ $R($b->total_amount) }}</b></td></tr>
</table>
<table style="margin-top:12px">
  <tr><td style="border:0;width:55%"></td><td style="border:0">
    <table>
      <tr><td>Tagihan ini</td><td class="right big">{{ $R($inv->amount) }}</td></tr>
      <tr><td>Sudah dibayar</td><td class="right">{{ $R($inv->paid_amount) }}</td></tr>
      <tr><td><b>Sisa</b></td><td class="right"><b>{{ $R($inv->amount - $inv->paid_amount) }}</b></td></tr>
    </table>
  </td></tr>
</table>
<div class="pay" style="margin-top:12px">
  <b>Transfer ke rekening resmi PPIU</b><br>
  {{ $inv->bankAccount->bank_name }} · <span class="big">{{ $inv->bankAccount->account_no }}</span><br>
  a.n. <b>{{ $inv->bankAccount->account_holder }}</b><br>
  <span class="muted">Cantumkan kode {{ $b->code }} pada berita transfer. Jangan transfer ke rekening pribadi siapa pun.</span>
</div>
<div class="foot">Dokumen dibuat oleh sistem UMRAH CONNECT atas nama {{ $t->legal_name }}. UMRAH CONNECT adalah platform teknologi dan tidak menerima dana jamaah. Dicetak {{ now('Asia/Jakarta')->translatedFormat('j M Y H:i') }} WIB.</div>
</body></html>
