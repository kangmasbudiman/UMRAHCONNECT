'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { rupiah } from '@/lib/format';
import { INVOICE_TYPE, type Invoice } from '@/lib/booking-types';
import { IconCash, IconCircleX, IconIdCard, IconNote, IconRefresh, IconTransfer } from '@/components/icons';

type Err = ApiClientError | string | null;
const msgOf = (e: Err) => (typeof e === 'string' ? e : e?.message);
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
const digits = (v: FormDataEntryValue | null) => Number(String(v ?? '').replace(/\D/g, '')) || 0;

async function uploadFile(file: File, purpose: string): Promise<string> {
  if (file.size > 5 * 1024 * 1024) throw new Error('Ukuran file maksimal 5 MB.');
  const fd = new FormData();
  fd.set('purpose', purpose);
  fd.set('file', file);
  return (await api<{ data: { id: string } }>('/files', { form: fd })).data.id;
}

/** JMH-08 Unggah bukti transfer (jamaah atau agen). Nominal default = sisa tagihan terdekat. */
export function PaymentUploadForm({ base, invoices }: { base: '/me' | '/agent'; invoices: Invoice[] }) {
  const router = useRouter();
  const open = invoices.filter((i) => i.status === 'unpaid' || i.status === 'partial');
  const [invId, setInvId] = useState(open[0]?.id ?? '');
  const inv = open.find((i) => i.id === invId);
  const [error, setError] = useState<Err>(null);
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);
  const [key] = useState(() => crypto.randomUUID());
  if (!inv) return null;
  const apiErr = error instanceof ApiClientError ? error : null;

  if (done) return <Alert tone="success" title="Bukti bayar terkirim">PPIU akan memeriksa mutasi rekening dan memverifikasi pembayaran. Kwitansi terbit setelah terverifikasi.</Alert>;

  return (
    <form className="grid gap-4 sm:grid-cols-2" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      setBusy(true);
      setError(null);
      try {
        const file = fd.get('proof') as File | null;
        if (!file || file.size === 0) throw new Error('Pilih foto atau PDF bukti transfer.');
        const proof = await uploadFile(file, 'payment_proof');
        await api(`${base}/invoices/${inv.id}/payments`, {
          json: { amount: digits(fd.get('amount')), paid_on: fd.get('paid_on'), sender_bank: fd.get('sender_bank') || null, sender_name: fd.get('sender_name') || null, proof_file_id: proof },
          headers: { 'Idempotency-Key': key },
        });
        setDone(true);
        router.refresh();
      } catch (err) { setError(err instanceof ApiClientError ? err : err instanceof Error ? err.message : errorMessage(err)); }
      setBusy(false);
    }}>
      {msgOf(error) && (!apiErr?.errors) && <div className="sm:col-span-2"><Alert tone="danger">{msgOf(error)}</Alert></div>}
      {open.length > 1 && (
        <div className="sm:col-span-2">
          <Field id="pay-inv" label="Untuk tagihan">
            <select id="pay-inv" className="input" value={invId} onChange={(e) => setInvId(e.target.value)}>
              {open.map((i) => <option key={i.id} value={i.id}>{INVOICE_TYPE[i.type]} · sisa {rupiah(i.amount - i.paid_amount)}</option>)}
            </select>
          </Field>
        </div>
      )}
      <Field id="pay-amount" label="Nominal yang ditransfer" error={apiErr?.field('amount')}>
        <input id="pay-amount" name="amount" key={inv.id} inputMode="numeric" className="input num" defaultValue={inv.amount - inv.paid_amount} required />
      </Field>
      <Field id="pay-date" label="Tanggal transfer" error={apiErr?.field('paid_on')}>
        <input id="pay-date" name="paid_on" type="date" className="input" defaultValue={today()} max={today()} required />
      </Field>
      <Field id="pay-bank" label="Dari bank" optional><input id="pay-bank" name="sender_bank" className="input" placeholder="BRI, BSI, Mandiri…" /></Field>
      <Field id="pay-name" label="Nama pemilik rekening pengirim" optional><input id="pay-name" name="sender_name" className="input" /></Field>
      <div className="sm:col-span-2">
        <Field id="pay-proof" label="Bukti transfer" hint="Foto struk/tangkapan layar atau PDF, maksimal 5 MB." error={apiErr?.field('proof_file_id') ?? apiErr?.field('file')}>
          <input id="pay-proof" name="proof" type="file" accept="image/jpeg,image/png,application/pdf" className="input py-2.5" required />
        </Field>
      </div>
      <div className="sm:col-span-2"><button className="btn btn-gold" disabled={busy}>{busy && <Spinner />}Kirim bukti bayar</button></div>
    </form>
  );
}

/** FR-JMH-10 Ajukan pembatalan; PPIU memutuskan nominal refund sesuai kebijakan paket. */
export function CancelRequestForm({ bookingId }: { bookingId: string }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  if (!open) return <button type="button" className="min-h-12 font-semibold text-danger hover:underline" onClick={() => setOpen(true)}>Ajukan pembatalan</button>;
  return (
    <form className="space-y-3 rounded-[12px] border border-danger/40 bg-danger-bg/40 p-4" onSubmit={async (e) => {
      e.preventDefault();
      setBusy(true);
      setError(null);
      try { await api(`/me/bookings/${bookingId}/cancel-request`, { json: { reason: new FormData(e.currentTarget).get('reason') } }); router.refresh(); }
      catch (err) { setError(errorMessage(err)); setBusy(false); }
    }}>
      <Field id="cr-reason" label="Alasan pembatalan" hint="PPIU akan menghubungi Anda dan menghitung refund sesuai kebijakan pembatalan paket.">
        <textarea id="cr-reason" name="reason" rows={3} className="input" required minLength={3} />
      </Field>
      {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
      <div className="flex gap-2">
        <button className="btn border border-danger bg-white text-danger hover:bg-danger-bg" disabled={busy}>{busy && <Spinner />}Kirim pengajuan</button>
        <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={() => setOpen(false)}>Batal</button>
      </div>
    </form>
  );
}

// ---------------------------- PPIU ----------------------------

/** Tombol dengan form kecil yang bisa dibuka/tutup. */
function Expand({ label, tone = 'outline', onOpen, icon: Icon, children }: { label: string; tone?: 'outline' | 'danger' | 'gold'; onOpen?: () => void; icon?: (p: { className?: string }) => React.ReactNode; children: (close: () => void) => React.ReactNode }) {
  const [open, setOpen] = useState(false);
  const cls = tone === 'danger' ? 'btn border border-danger bg-white text-danger hover:bg-danger-bg' : tone === 'gold' ? 'btn btn-gold' : 'btn btn-outline';
  return open ? <div className="w-full">{children(() => setOpen(false))}</div> : <button type="button" className={cls} onClick={() => { setOpen(true); onOpen?.(); }}>{Icon && <Icon className="h-5 w-5" />}{label}</button>;
}

/** FR-FIN-06 Catat pembayaran langsung (tunai di kantor / transfer yang sudah dicek). */
export function RecordPaymentForm({ tenantId, invoices }: { tenantId: string; invoices: Invoice[] }) {
  const router = useRouter();
  const open = invoices.filter((i) => i.status === 'unpaid' || i.status === 'partial');
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState(false);
  const [key, setKey] = useState(() => crypto.randomUUID());
  if (!open.length) return null;
  return (
    <Expand label="Catat pembayaran langsung" icon={IconCash}>
      {(close) => (
        <form className="grid gap-3 rounded-[12px] bg-surface p-4 sm:grid-cols-2" noValidate onSubmit={async (e) => {
          e.preventDefault();
          const fd = new FormData(e.currentTarget);
          setBusy(true);
          setError(null);
          try {
            await api(`/tenants/${tenantId}/invoices/${fd.get('invoice')}/payments`, {
              json: { method: fd.get('method'), amount: digits(fd.get('amount')), paid_on: fd.get('paid_on'), sender_name: fd.get('sender_name') || null },
              headers: { 'Idempotency-Key': key },
            });
            setKey(crypto.randomUUID());
            close();
            router.refresh();
          } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
          setBusy(false);
        }}>
          <Field id="rp-inv" label="Tagihan"><select id="rp-inv" name="invoice" className="input">{open.map((i) => <option key={i.id} value={i.id}>{INVOICE_TYPE[i.type]} · sisa {rupiah(i.amount - i.paid_amount)}</option>)}</select></Field>
          <Field id="rp-method" label="Cara bayar"><select id="rp-method" name="method" className="input"><option value="cash">Tunai di kantor</option><option value="bank_transfer">Transfer (sudah dicek di mutasi)</option></select></Field>
          <Field id="rp-amount" label="Nominal"><input id="rp-amount" name="amount" inputMode="numeric" className="input num" defaultValue={open[0].amount - open[0].paid_amount} /></Field>
          <Field id="rp-date" label="Tanggal"><input id="rp-date" name="paid_on" type="date" className="input" defaultValue={today()} max={today()} /></Field>
          <div className="sm:col-span-2"><Field id="rp-name" label="Diterima dari" optional><input id="rp-name" name="sender_name" className="input" /></Field></div>
          {msgOf(error) && <div className="sm:col-span-2"><Alert tone="danger">{msgOf(error)}</Alert></div>}
          <div className="flex gap-2 sm:col-span-2">
            <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Simpan & terbitkan kwitansi</button>
            <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={close}>Batal</button>
          </div>
        </form>
      )}
    </Expand>
  );
}

/** BP-08 Batalkan booking (inisiatif PPIU atau menyetujui pengajuan) dan putuskan refund. */
export function CancelBookingForm({ tenantId, bookingId, paid, requested, cancellationTerms }: { tenantId: string; bookingId: string; paid: number; requested: boolean; cancellationTerms?: string | null }) {
  const router = useRouter();
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState(false);
  const [key] = useState(() => crypto.randomUUID());
  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <Expand label={requested ? 'Setujui pembatalan' : 'Batalkan booking'} tone="danger" icon={IconCircleX}>
      {(close) => (
        <form className="space-y-3 rounded-[12px] border border-danger/40 bg-danger-bg/40 p-4" noValidate onSubmit={async (e) => {
          e.preventDefault();
          const fd = new FormData(e.currentTarget);
          if (!confirm('Batalkan booking ini? Kursi dilepas dan tagihan yang belum dibayar dibatalkan. Tindakan ini tidak bisa diurungkan.')) return;
          setBusy(true);
          setError(null);
          try {
            await api(`/tenants/${tenantId}/bookings/${bookingId}/cancel`, { json: { reason: fd.get('reason'), refund_amount: digits(fd.get('refund')), refund_basis: fd.get('basis') }, headers: { 'Idempotency-Key': key } });
            router.refresh();
          } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); setBusy(false); }
        }}>
          {cancellationTerms && <p className="rounded-[10px] bg-white p-3 text-sm"><span className="font-semibold">Kebijakan paket:</span> {cancellationTerms}</p>}
          <Field id="cb-reason" label="Alasan pembatalan" error={apiErr?.field('reason')}><textarea id="cb-reason" name="reason" rows={2} className="input" defaultValue={requested ? 'Atas pengajuan jamaah' : ''} /></Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field id="cb-refund" label="Nominal refund" hint={`Sudah dibayar ${rupiah(paid)}. Isi 0 bila tidak ada refund.`} error={apiErr?.field('refund_amount')}>
              <input id="cb-refund" name="refund" inputMode="numeric" className="input num" defaultValue={0} />
            </Field>
            <Field id="cb-basis" label="Dasar perhitungan" error={apiErr?.field('refund_basis')}><input id="cb-basis" name="basis" className="input" placeholder="mis. Batal > 45 hari: 75% DP" /></Field>
          </div>
          {msgOf(error) && !apiErr?.errors && <Alert tone="danger">{msgOf(error)}</Alert>}
          <div className="flex gap-2">
            <button className="btn bg-danger text-white hover:opacity-90" disabled={busy}>{busy && <Spinner />}Batalkan booking</button>
            <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={close}>Kembali</button>
          </div>
        </form>
      )}
    </Expand>
  );
}

export function RejectCancelRequestButton({ tenantId, bookingId }: { tenantId: string; bookingId: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  return (
    <button type="button" className="btn btn-outline" disabled={busy} onClick={async () => {
      const reason = prompt('Alasan menolak pengajuan pembatalan (dicatat di catatan internal):');
      if (!reason || reason.trim().length < 3) return;
      setBusy(true);
      try { await api(`/tenants/${tenantId}/bookings/${bookingId}/cancel-request/reject`, { json: { reason: reason.trim() } }); router.refresh(); }
      catch (e) { alert(errorMessage(e)); }
      setBusy(false);
    }}>{busy && <Spinner />}Tolak pengajuan</button>
  );
}

/** FR-FIN-13 Catat refund transfer atau pengalihan ke booking lain (cari dengan kode booking). */
export function RefundForm({ tenantId, bookingId, remaining }: { tenantId: string; bookingId: string; remaining: number }) {
  const router = useRouter();
  const [type, setType] = useState<'bank_transfer' | 'reallocation'>('bank_transfer');
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState(false);
  const [key, setKey] = useState(() => crypto.randomUUID());
  if (remaining <= 0) return null;
  return (
    <Expand label="Catat refund" tone="gold" icon={IconRefresh}>
      {(close) => (
        <form className="grid gap-3 rounded-[12px] bg-surface p-4 sm:grid-cols-2" noValidate onSubmit={async (e) => {
          e.preventDefault();
          const fd = new FormData(e.currentTarget);
          setBusy(true);
          setError(null);
          try {
            let target: string | null = null;
            if (type === 'reallocation') {
              const code = String(fd.get('target') ?? '').trim().toUpperCase();
              const r = await api<{ data: { id: string; code: string }[] }>(`/tenants/${tenantId}/bookings?q=${encodeURIComponent(code)}&limit=5`);
              target = r.data.find((b) => b.code === code)?.id ?? null;
              if (!target) throw new Error(`Booking ${code} tidak ditemukan di PPIU ini.`);
            }
            const file = fd.get('proof') as File | null;
            const proof = file && file.size > 0 ? await uploadFile(file, 'refund_proof') : null;
            await api(`/tenants/${tenantId}/bookings/${bookingId}/refunds`, {
              json: { type, amount: digits(fd.get('amount')), target_booking_id: target, paid_on: fd.get('paid_on'), proof_file_id: proof, note: fd.get('note') },
              headers: { 'Idempotency-Key': key },
            });
            setKey(crypto.randomUUID());
            close();
            router.refresh();
          } catch (err) { setError(err instanceof ApiClientError ? err : err instanceof Error ? err.message : errorMessage(err)); }
          setBusy(false);
        }}>
          <Field id="rf-type" label="Jenis">
            <select id="rf-type" className="input" value={type} onChange={(e) => setType(e.target.value as typeof type)}>
              <option value="bank_transfer">Transfer ke jamaah</option><option value="reallocation">Alihkan ke booking lain</option>
            </select>
          </Field>
          <Field id="rf-amount" label="Nominal" hint={`Sisa refund ${rupiah(remaining)}`}><input id="rf-amount" name="amount" inputMode="numeric" className="input num" defaultValue={remaining} /></Field>
          {type === 'reallocation' && <Field id="rf-target" label="Kode booking tujuan"><input id="rf-target" name="target" className="input uppercase" placeholder="UC-2611-0001" /></Field>}
          <Field id="rf-date" label="Tanggal"><input id="rf-date" name="paid_on" type="date" className="input" defaultValue={today()} max={today()} /></Field>
          {type === 'bank_transfer' && <Field id="rf-proof" label="Bukti transfer" optional><input id="rf-proof" name="proof" type="file" accept="image/jpeg,image/png,application/pdf" className="input py-2.5" /></Field>}
          <div className="sm:col-span-2"><Field id="rf-note" label="Catatan" hint="Rekening tujuan atau dasar pengalihan."><input id="rf-note" name="note" className="input" /></Field></div>
          {msgOf(error) && <div className="sm:col-span-2"><Alert tone="danger">{msgOf(error)}</Alert></div>}
          <div className="flex gap-2 sm:col-span-2">
            <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Simpan refund</button>
            <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={close}>Batal</button>
          </div>
        </form>
      )}
    </Expand>
  );
}

/** FR-PPIU-21 Catatan internal: hanya bisa ditambah, tidak diubah. */
export function NoteForm({ tenantId, bookingId }: { tenantId: string; bookingId: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <form className="space-y-2" onSubmit={async (e) => {
      e.preventDefault();
      const form = e.currentTarget;
      const body = String(new FormData(form).get('body') ?? '').trim();
      if (!body) return;
      setBusy(true);
      setError(null);
      try { await api(`/tenants/${tenantId}/bookings/${bookingId}/notes`, { json: { body } }); form.reset(); router.refresh(); }
      catch (err) { setError(errorMessage(err)); }
      setBusy(false);
    }}>
      <label htmlFor="note-body" className="sr-only">Catatan baru</label>
      <textarea id="note-body" name="body" rows={2} className="input" placeholder="Tulis catatan untuk tim (tidak terlihat jamaah/agen)" />
      {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
      <button className="btn btn-outline min-h-10 px-4" disabled={busy}>{busy ? <Spinner /> : <IconNote className="h-5 w-5" />}Tambah catatan</button>
    </form>
  );
}

/** Buka dokumen jamaah lewat signed URL (akses tercatat). */
export function ViewDocButton({ tenantId, docId }: { tenantId: string; docId: string }) {
  return (
    <button type="button" className="min-h-10 px-2 text-sm font-semibold text-primary-700 underline" onClick={async () => {
      const w = window.open('', '_blank', 'noopener');
      try {
        const r = await api<{ data: { url: string } }>(`/tenants/${tenantId}/pilgrim-documents/${docId}/file`);
        if (w) w.location.href = r.data.url; else window.location.href = r.data.url;
      } catch (e) { w?.close(); alert(errorMessage(e)); }
    }}>Lihat</button>
  );
}

export function ReviewDocButtons({ tenantId, docId }: { tenantId: string; docId: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const send = async (status: 'accepted' | 'rejected') => {
    let note: string | null = null;
    if (status === 'rejected') {
      note = prompt('Alasan dokumen ditolak (ditampilkan ke jamaah):');
      if (!note || !note.trim()) return;
    }
    setBusy(true);
    try { await api(`/tenants/${tenantId}/pilgrim-documents/${docId}/review`, { method: 'PUT', json: { status, note } }); router.refresh(); }
    catch (e) { alert(errorMessage(e)); }
    setBusy(false);
  };
  return (
    <span className="inline-flex gap-1">
      <button type="button" disabled={busy} className="min-h-10 rounded-lg px-2 text-sm font-semibold text-success hover:bg-success-bg" onClick={() => send('accepted')}>Terima</button>
      <button type="button" disabled={busy} className="min-h-10 rounded-lg px-2 text-sm font-semibold text-danger hover:bg-danger-bg" onClick={() => send('rejected')}>Tolak</button>
    </span>
  );
}

/** NIK & paspor utuh: hanya saat dibutuhkan (mis. pengurusan visa); setiap akses tercatat di audit log. */
export function IdentityButton({ tenantId, pilgrimId }: { tenantId: string; pilgrimId: string }) {
  const [data, setData] = useState<{ nik: string; passport_no: string | null } | null>(null);
  if (data) return <span className="num text-sm">NIK {data.nik}{data.passport_no ? ` · Paspor ${data.passport_no}` : ''}</span>;
  return (
    <button type="button" className="inline-flex min-h-10 items-center gap-1 px-2 text-sm font-semibold text-primary-700 underline" onClick={async () => {
      if (!confirm('Tampilkan NIK dan paspor lengkap? Akses ini dicatat di audit log.')) return;
      try { setData((await api<{ data: { nik: string; passport_no: string | null } }>(`/tenants/${tenantId}/pilgrims/${pilgrimId}/identity`)).data); }
      catch (e) { alert(errorMessage(e)); }
    }}><IconIdCard className="h-4 w-4" />Lihat NIK lengkap</button>
  );
}

type PartnerOpt = { agent: { id: string; code: string; display_name: string } };

/** FR-PPIU-14 / BR-09 Ubah atribusi agen (Owner saja, alasan wajib, tercatat di audit log). */
export function AttributionForm({ tenantId, bookingId, currentAgentId }: { tenantId: string; bookingId: string; currentAgentId: string | null }) {
  const router = useRouter();
  const [partners, setPartners] = useState<PartnerOpt[] | null>(null);
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState(false);
  const load = async () => {
    if (partners) return;
    try { setPartners((await api<{ data: PartnerOpt[] }>(`/tenants/${tenantId}/partnerships?filter[status]=active&limit=100`)).data); }
    catch (e) { setError(errorMessage(e)); setPartners([]); }
  };
  return (
    <Expand label="Ubah atribusi agen" onOpen={load} icon={IconTransfer}>
      {(close) => (
        <form className="space-y-3 rounded-[12px] bg-surface p-4" noValidate onSubmit={async (e) => {
          e.preventDefault();
          const fd = new FormData(e.currentTarget);
          const agent = String(fd.get('agent_id') ?? '');
          setBusy(true);
          setError(null);
          try {
            await api(`/tenants/${tenantId}/bookings/${bookingId}/attribution`, { method: 'PATCH', json: { agent_id: agent === '' ? null : agent, reason: String(fd.get('reason') ?? '').trim() } });
            close();
            router.refresh();
          } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
          setBusy(false);
        }}>
          <p className="text-sm text-ink-600">Komisi yang belum disetujui ikut pindah dan dihitung ulang dengan skema agen baru. Agen lama dan baru diberi tahu.</p>
          <Field id="at-agent" label="Agen" error={error instanceof ApiClientError ? error.field('agent_id') : undefined}>
            <select id="at-agent" name="agent_id" className="input" defaultValue={currentAgentId ?? ''} disabled={!partners}>
              <option value="">Tanpa agen</option>
              {partners?.map((p) => <option key={p.agent.id} value={p.agent.id}>{p.agent.display_name} ({p.agent.code})</option>)}
            </select>
          </Field>
          <Field id="at-reason" label="Alasan" hint="Wajib, minimal 5 karakter. Tercatat di audit log dan catatan internal." error={error instanceof ApiClientError ? error.field('reason') : undefined}>
            <textarea id="at-reason" name="reason" rows={2} className="input" />
          </Field>
          {msgOf(error) && !(error instanceof ApiClientError && error.errors) && <Alert tone="danger">{msgOf(error)}</Alert>}
          <div className="flex gap-2">
            <button className="btn btn-primary" disabled={busy || !partners}>{busy && <Spinner />}Simpan atribusi</button>
            <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={close}>Batal</button>
          </div>
        </form>
      )}
    </Expand>
  );
}
