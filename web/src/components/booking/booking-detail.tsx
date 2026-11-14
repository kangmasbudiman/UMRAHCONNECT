import Link from 'next/link';
import { Alert, StatusPill } from '@/components/form';
import { ROOM_LABEL, rupiah, tanggal, waLink } from '@/lib/format';
import { ATTRIBUTION, DOC_TYPE, INVOICE_TYPE, PAY_METHOD, type Booking } from '@/lib/booking-types';
import { WhatsAppButton } from '@/components/ui';
import { Countdown, CopyButton } from './countdown';
import {
  AttributionForm, CancelBookingForm, CancelRequestForm, IdentityButton, NoteForm, PaymentUploadForm, RecordPaymentForm,
  RefundForm, RejectCancelRequestButton, ReviewDocButtons, ViewDocButton,
} from './booking-actions';

const wib = (iso: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso)) + ' WIB';

export type Note = { id: string; body: string; author: string; created_at: string };
type TenantCtx = { tenantId: string; perms: string[]; notes?: Note[]; cancellationTerms?: string | null };

/**
 * JMH-07 / PPIU-14 Detail booking. view menentukan isi dan aksi:
 * - jamaah: bayar (unggah bukti), kwitansi, ajukan batal;
 * - agent: bayar atas nama jamaah, tanpa data identitas (BR-14);
 * - tenant: data tersamar, catat bayar, batal & refund, catatan internal, dokumen jamaah.
 */
export function BookingDetail({ b, view, isNew, tenant }: { b: Booking; view: 'jamaah' | 'agent' | 'tenant'; isNew?: boolean; tenant?: TenantCtx }) {
  const can = (p: string) => !!tenant?.perms.includes(p);
  const firstOpen = b.invoices.find((i) => i.status === 'unpaid' || i.status === 'partial');
  const dpInv = b.invoices.find((i) => i.type === 'dp' || i.type === 'full');
  const payTo = dpInv?.pay_to ?? b.pay_to ?? null;
  const awaiting = b.status === 'awaiting_dp';
  const payable = awaiting || b.status === 'dp_verified';
  const pending = (b.payments ?? []).filter((p) => p.status === 'pending');
  const cancelReq = b.cancel_request ?? (b.cancel_request_status ? { status: b.cancel_request_status, requested_at: null, reason: null } : null);
  const receiptBase = view === 'tenant' ? `/api/v1/tenants/${tenant?.tenantId}/receipts` : '/api/v1/me/receipts';
  const invoiceBase = view === 'tenant' ? `/api/v1/tenants/${tenant?.tenantId}/invoices` : view === 'jamaah' ? '/api/v1/me/invoices' : null;
  const wa = waLink(b.tenant.whatsapp, `Assalamualaikum, saya ingin bertanya tentang booking ${b.code} (${b.package.name}).`);
  const refundLeft = (b.refund_decided_amount ?? 0) - (b.refund_total_amount ?? 0);

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
      <div className="space-y-5">
        {isNew && awaiting && (
          <Alert tone="success" title={`Booking ${b.code} berhasil dibuat`}>
            {view === 'jamaah' ? 'Kursi Anda ditahan.' : 'Kursi ditahan untuk jamaah.'} Bayar DP sebelum tenggat agar booking tidak batal otomatis.
          </Alert>
        )}
        {cancelReq?.status === 'pending' && (
          <Alert tone="warning" title="Pengajuan pembatalan sedang diproses PPIU">
            {cancelReq.reason && <p>Alasan: {cancelReq.reason}</p>}
            {view === 'tenant' && can('booking.cancel') && (
              <div className="mt-3 flex flex-wrap gap-2">
                <CancelBookingForm tenantId={tenant!.tenantId} bookingId={b.id} paid={b.paid_amount} requested cancellationTerms={tenant?.cancellationTerms} />
                <RejectCancelRequestButton tenantId={tenant!.tenantId} bookingId={b.id} />
              </div>
            )}
          </Alert>
        )}
        {cancelReq?.status === 'rejected' && view !== 'tenant' && b.status !== 'cancelled' && <Alert tone="info">Pengajuan pembatalan ditolak PPIU. Hubungi {b.tenant.brand_name} untuk penjelasan.</Alert>}
        {b.status === 'dp_verified' && <Alert tone="success" title="DP terverifikasi, kursi terkonfirmasi">Lunasi sisa pembayaran paling lambat {tanggal(b.departure.final_payment_due_on)}.</Alert>}
        {b.status === 'fully_paid' && <Alert tone="success" title="Lunas">Semua pembayaran sudah terverifikasi. Kwitansi bisa diunduh di bawah.</Alert>}
        {b.status === 'expired' && <Alert tone="info" title="Booking kedaluwarsa">DP tidak dibayar sebelum tenggat sehingga kursi dilepas. Silakan booking ulang bila kursi masih tersedia.</Alert>}
        {b.status === 'cancelled' && (
          <Alert tone="danger" title="Booking dibatalkan">
            <p>{b.cancel_reason}</p>
            {b.refund_decided_amount !== null && b.refund_decided_amount !== undefined && (
              <p className="mt-1">Refund diputuskan <span className="num font-bold">{rupiah(b.refund_decided_amount)}</span>{b.refund_basis && <> ({b.refund_basis})</>}; sudah diproses <span className="num font-bold">{rupiah(b.refund_total_amount ?? 0)}</span>.</p>
            )}
          </Alert>
        )}

        {payable && view !== 'tenant' && firstOpen && payTo && (
          <section className="card overflow-hidden border-gold-500" aria-labelledby="bayar-h">
            <div className="bg-gold-50 p-5">
              <h2 id="bayar-h" className="text-xl font-extrabold">{firstOpen.type === 'final' ? 'Bayar pelunasan' : firstOpen.type === 'full' ? 'Bayar penuh' : 'Bayar DP'} {rupiah(firstOpen.amount - firstOpen.paid_amount)}</h2>
              {awaiting && b.hold_expires_at
                ? <p className="mt-1">Paling lambat <span className="font-bold">{wib(b.hold_expires_at)}</span> · <Countdown until={b.hold_expires_at} /></p>
                : <p className="mt-1">Paling lambat <span className="font-bold">{wib(firstOpen.due_at)}</span></p>}
            </div>
            <div className="space-y-4 p-5">
              <p className="text-ink-600">Transfer ke rekening resmi {b.tenant.brand_name}:</p>
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-border-subtle p-4">
                <div>
                  <p className="text-sm text-ink-600">{payTo.bank_name}</p>
                  <p className="num text-2xl font-extrabold tracking-wide">{payTo.account_no}</p>
                  <p className="font-semibold">a.n. {payTo.account_holder}</p>
                </div>
                <CopyButton text={payTo.account_no} label="Salin nomor" />
              </div>
              <Alert tone="warning">Pastikan nama penerima sesuai. UMRAH CONNECT dan agen tidak pernah meminta transfer ke rekening pribadi.</Alert>
              {pending.length > 0
                ? <Alert tone="info" title="Bukti bayar sedang diperiksa PPIU">Anda akan melihat kwitansi di sini setelah pembayaran terverifikasi. Booking tidak dibatalkan otomatis selama bukti diperiksa.</Alert>
                : (
                  <div className="border-t border-border-subtle pt-4">
                    <h3 className="mb-3 font-extrabold">Sudah transfer? Kirim bukti bayar</h3>
                    <PaymentUploadForm base={view === 'agent' ? '/agent' : '/me'} invoices={b.invoices} />
                  </div>
                )}
            </div>
          </section>
        )}

        {view === 'tenant' && pending.length > 0 && (
          <Alert tone="warning" title={`${pending.length} bukti bayar menunggu verifikasi`}>
            Periksa di <Link href={`/dasbor/ppiu/${tenant!.tenantId}/pembayaran`} className="font-bold text-primary-700 underline">antrean pembayaran</Link>.
          </Alert>
        )}

        <section className="card p-5" aria-labelledby="inv-h">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 id="inv-h" className="text-lg font-extrabold">Tagihan</h2>
            {view === 'tenant' && payable && can('payment.record') && <RecordPaymentForm tenantId={tenant!.tenantId} invoices={b.invoices} />}
          </div>
          <ul className="mt-3 divide-y divide-border-subtle">
            {b.invoices.map((i) => (
              <li key={i.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div>
                  <p className="font-bold">{INVOICE_TYPE[i.type]} <span className="num text-sm font-normal text-ink-600">{i.number}</span></p>
                  <p className="text-sm text-ink-600">Jatuh tempo {wib(i.due_at)}{i.paid_amount > 0 && i.status !== 'paid' && <> · dibayar {rupiah(i.paid_amount)}</>}</p>
                  {invoiceBase && <a href={`${invoiceBase}/${i.id}/pdf`} target="_blank" rel="noopener" className="text-sm font-semibold text-primary-700 underline">Unduh invoice PDF</a>}
                </div>
                <div className="flex items-center gap-3"><span className="num font-extrabold">{rupiah(i.amount)}</span><StatusPill status={i.status} /></div>
              </li>
            ))}
          </ul>
        </section>

        {(b.payments?.length ?? 0) > 0 && (
          <section className="card p-5" aria-labelledby="pay-h">
            <h2 id="pay-h" className="text-lg font-extrabold">Pembayaran</h2>
            <ul className="mt-3 divide-y divide-border-subtle">
              {b.payments!.map((p) => (
                <li key={p.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="font-bold">{PAY_METHOD[p.method]} <span className="font-normal text-ink-600">· {p.paid_on && tanggal(p.paid_on)}{p.split_from_payment_id && ' · pecahan kelebihan bayar'}</span></p>
                    <p className="text-sm text-ink-600">{p.invoice_type ? INVOICE_TYPE[p.invoice_type] : ''} {p.sender_name && <>· dari {p.sender_name}</>}</p>
                    {p.status === 'rejected' && p.reject_reason && <p className="text-sm font-semibold text-danger">Ditolak: {p.reject_reason}</p>}
                    <div className="flex flex-wrap gap-3 text-sm">
                      {p.receipt && view !== 'agent' && <a href={`${receiptBase}/${p.receipt.id}/pdf`} target="_blank" rel="noopener" className="font-semibold text-primary-700 underline">Kwitansi {p.receipt.number}</a>}
                      {p.receipt && view === 'agent' && <span className="text-ink-600">Kwitansi {p.receipt.number}</span>}
                      {p.proof_url && <a href={p.proof_url} target="_blank" rel="noopener" className="font-semibold text-primary-700 underline">Lihat bukti</a>}
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="num font-extrabold">{rupiah(p.verified_amount ?? p.claimed_amount)}</p>
                    <StatusPill status={`pay_${p.status}`} />
                  </div>
                </li>
              ))}
            </ul>
          </section>
        )}

        {(b.status === 'cancelled' && view === 'tenant') && (
          <section className="card space-y-3 p-5" aria-labelledby="rf-h">
            <h2 id="rf-h" className="text-lg font-extrabold">Refund</h2>
            {(b.refunds ?? []).length === 0 && <p className="text-ink-600">Belum ada refund dicatat.</p>}
            <ul className="divide-y divide-border-subtle">
              {(b.refunds ?? []).map((r) => (
                <li key={r.id} className="flex flex-wrap justify-between gap-3 py-2">
                  <span>{r.type === 'reallocation' ? `Dialihkan ke ${r.target_booking_code}` : 'Transfer ke jamaah'} · {tanggal(r.paid_on)}<br /><span className="text-sm text-ink-600">{r.note}</span></span>
                  <span className="num font-bold">{rupiah(r.amount)}</span>
                </li>
              ))}
            </ul>
            {can('refund.record') && <RefundForm tenantId={tenant!.tenantId} bookingId={b.id} remaining={refundLeft} />}
          </section>
        )}
        {b.status === 'cancelled' && view !== 'tenant' && (b.refunds?.length ?? 0) > 0 && (
          <section className="card p-5"><h2 className="text-lg font-extrabold">Refund</h2>
            <ul className="mt-2 space-y-1">{b.refunds!.map((r) => <li key={r.id} className="flex justify-between"><span>{r.type === 'reallocation' ? `Dialihkan ke ${r.target_booking_code}` : 'Transfer'} · {tanggal(r.paid_on)}</span><span className="num font-bold">{rupiah(r.amount)}</span></li>)}</ul>
          </section>
        )}

        <section className="card p-5" aria-labelledby="pst-h">
          <h2 id="pst-h" className="text-lg font-extrabold">Jamaah ({b.participant_count})</h2>
          <ul className="mt-3 divide-y divide-border-subtle">
            {b.participants.map((p, i) => (
              <li key={p.pilgrim_id ?? i} className="space-y-2 py-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="font-bold">{p.full_name}{p.gender && <span className="ml-2 text-sm font-normal text-ink-600">{p.gender === 'L' ? 'Laki-laki' : 'Perempuan'}</span>}</p>
                    {view === 'tenant' && <p className="num text-sm text-ink-600">NIK {p.nik_masked}{p.passport_no_masked ? ` · Paspor ${p.passport_no_masked}` : ' · paspor belum diisi'}{p.birth_date ? ` · lahir ${tanggal(p.birth_date)}` : ''}</p>}
                    <p className="text-sm text-ink-600">{ROOM_LABEL[p.room_type]}</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    {p.price_amount !== undefined && <span className="num font-semibold">{rupiah(p.price_amount)}</span>}
                    {view === 'jamaah' && p.pilgrim_id && <Link href={`/akun/jamaah/${p.pilgrim_id}`} className="btn btn-outline min-h-10 px-3 text-sm">Dokumen</Link>}
                  </div>
                </div>
                {view === 'tenant' && (
                  <div className="rounded-[10px] bg-surface px-3 py-2 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-semibold">Dokumen</span>
                      {can('pilgrim.identity.view') && p.pilgrim_id && <IdentityButton tenantId={tenant!.tenantId} pilgrimId={p.pilgrim_id} />}
                    </div>
                    {(p.documents ?? []).length === 0 ? <p className="text-ink-600">Belum ada dokumen diunggah jamaah.</p> : (
                      <ul className="mt-1 space-y-1">
                        {p.documents!.map((d) => {
                          const rv = d.reviews[0];
                          return (
                            <li key={d.id} className="flex flex-wrap items-center justify-between gap-2">
                              <span>{DOC_TYPE[d.type]} {rv && <StatusPill status={rv.status} />}{rv?.note && <span className="text-danger"> · {rv.note}</span>}</span>
                              <span className="inline-flex flex-wrap items-center">
                                {can('pilgrim.identity.view') && <ViewDocButton tenantId={tenant!.tenantId} docId={d.id} />}
                                {can('pilgrim_document.review') && <ReviewDocButtons tenantId={tenant!.tenantId} docId={d.id} />}
                              </span>
                            </li>
                          );
                        })}
                      </ul>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </section>

        {view === 'tenant' && tenant?.notes && (
          <section className="card p-5" aria-labelledby="note-h">
            <h2 id="note-h" className="text-lg font-extrabold">Catatan internal</h2>
            <p className="text-sm text-ink-600">Hanya terlihat tim PPIU. Catatan tidak bisa diubah atau dihapus.</p>
            {can('booking.note') && <div className="mt-3"><NoteForm tenantId={tenant.tenantId} bookingId={b.id} /></div>}
            <ol className="mt-4 space-y-3 border-l-2 border-border-subtle pl-4">
              {tenant.notes.map((n) => (
                <li key={n.id}><p className="whitespace-pre-line">{n.body}</p><p className="text-sm text-ink-600">{n.author} · {wib(n.created_at)}</p></li>
              ))}
            </ol>
          </section>
        )}
      </div>

      <aside className="space-y-4 lg:sticky lg:top-24 lg:self-start">
        <div className="card overflow-hidden">
          <div className="bg-hero p-4 text-white">
            <p className="num text-sm text-gold-500">{b.code}</p>
            <p className="text-lg font-extrabold">{b.package.name}</p>
            <p className="text-sm text-white/85">{b.tenant.brand_name}</p>
          </div>
          <dl className="space-y-2 p-4 text-sm">
            <div className="flex justify-between gap-3"><dt className="text-ink-600">Status</dt><dd><StatusPill status={b.status} /></dd></div>
            <div className="flex justify-between gap-3"><dt className="text-ink-600">Berangkat</dt><dd className="font-semibold">{tanggal(b.departure.departure_date)} · {b.departure.departure_city}</dd></div>
            <div className="flex justify-between gap-3"><dt className="text-ink-600">Pulang</dt><dd className="font-semibold">{tanggal(b.departure.return_date)}</dd></div>
            <div className="flex justify-between gap-3"><dt className="text-ink-600">Pemesan</dt><dd className="text-right font-semibold">{b.booker.name}<br />{b.booker.phone}</dd></div>
            {view !== 'agent' && b.agent && <div className="flex justify-between gap-3"><dt className="text-ink-600">Agen</dt><dd className="text-right font-semibold">{b.agent.display_name} ({b.agent.code})</dd></div>}
            {view === 'tenant' && <div className="flex justify-between gap-3"><dt className="text-ink-600">Atribusi</dt><dd className="font-semibold">{ATTRIBUTION[b.attribution_source] ?? b.attribution_source}</dd></div>}
            <div className="flex justify-between gap-3 border-t border-dashed border-border-subtle pt-2"><dt className="font-bold">Total</dt><dd className="num text-lg font-extrabold text-primary-800">{rupiah(b.total_amount)}</dd></div>
            <div className="flex justify-between gap-3"><dt className="text-ink-600">Sudah dibayar</dt><dd className="num font-semibold">{rupiah(b.paid_amount)}</dd></div>
            {b.status !== 'cancelled' && <div className="flex justify-between gap-3"><dt className="text-ink-600">Sisa</dt><dd className="num font-semibold">{rupiah(b.total_amount - b.paid_amount)}</dd></div>}
          </dl>
        </div>
        {view === 'tenant' && b.commission_snapshot && (
          <div className="card p-4 text-sm">
            <p className="font-bold">Komisi agen (skema terkunci untuk booking ini)</p>
            <p className="text-ink-600">{b.commission_snapshot.type === 'flat' ? `${rupiah(b.commission_snapshot.flat_amount ?? 0)} per jamaah` : `${((b.commission_snapshot.percent_bp ?? 0) / 100).toLocaleString('id-ID')}% dari harga`}, diperoleh {b.commission_snapshot.earn_on === 'dp_verified' ? 'saat DP terverifikasi' : 'saat lunas'}.</p>
          </div>
        )}
        {view === 'tenant' && payable && can('booking.attribution.change') && (
          <div className="card p-4"><AttributionForm tenantId={tenant!.tenantId} bookingId={b.id} currentAgentId={b.agent?.id ?? null} /></div>
        )}
        {view === 'tenant' && payable && can('booking.cancel') && cancelReq?.status !== 'pending' && (
          <div className="card p-4"><CancelBookingForm tenantId={tenant!.tenantId} bookingId={b.id} paid={b.paid_amount} requested={false} cancellationTerms={tenant?.cancellationTerms} /></div>
        )}
        {view !== 'tenant' && (
          <div className="card space-y-3 p-4">
            <WhatsAppButton href={wa} label={`Hubungi ${b.tenant.brand_name}`} className="w-full" />
            {view === 'jamaah' && ['awaiting_dp', 'dp_verified', 'fully_paid'].includes(b.status) && cancelReq?.status !== 'pending' && <CancelRequestForm bookingId={b.id} />}
          </div>
        )}
      </aside>
    </div>
  );
}
