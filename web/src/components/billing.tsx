'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner, StatusPill } from '@/components/form';
import { IconCheck, IconCircleCheck, IconReceipt, IconSparkles, IconUpload } from '@/components/icons';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { INVOICE_STATUS, planBullets, type FeaturedPlacement, type Plan, type PlatformInvoice } from '@/lib/billing-types';
import { rupiah, tanggal } from '@/lib/format';

const bulan = (p: string) => new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${p}-01T00:00:00`));

/** Pilihan paket langganan. `endpoint` = POST yang menerima { plan_code }. */
export function PlanCards({ plans, current, endpoint, canManage, pastDue }: { plans: Plan[]; current: string; endpoint: string; canManage: boolean; pastDue: boolean }) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  return (
    <div className="space-y-3">
      <ul className="grid gap-3 md:grid-cols-2">
        {plans.map((p) => {
          const on = p.code === current;
          return (
            <li key={p.code} className={`card flex flex-col gap-3 p-5 ${on ? 'border-primary-700 ring-2 ring-primary-700/20' : ''}`}>
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="text-lg font-extrabold">{p.name}</p>
                  <p className="num text-2xl font-extrabold text-primary-800">{p.price_monthly ? rupiah(p.price_monthly) : 'Gratis'}<span className="text-sm font-normal text-ink-600">{p.price_monthly ? ' /bulan' : ''}</span></p>
                </div>
                {on && <span className="rounded-full bg-primary-700 px-2.5 py-1 text-xs font-bold text-white">Paket Anda</span>}
              </div>
              <ul className="space-y-1.5 text-sm">{planBullets(p).map((b) => <li key={b} className="flex gap-2"><IconCheck className="h-4 w-4 text-success" />{b}</li>)}</ul>
              {canManage && !on && (
                <button type="button" className={`btn mt-auto ${p.price_monthly ? 'btn-gold' : 'btn-outline'}`} disabled={!!busy || pastDue}
                  onClick={async () => {
                    if (!confirm(p.price_monthly ? `Beralih ke ${p.name}? Biaya ${rupiah(p.price_monthly)}/bulan ditagih prorata per hari pada tagihan bulan depan.` : `Beralih ke ${p.name}? Fitur berbayar berhenti hari ini; pemakaian sampai hari ini tetap ditagih.`)) return;
                    setBusy(p.code); setError(null);
                    try { await api(endpoint, { json: { plan_code: p.code } }); router.refresh(); } catch (e) { setError(errorMessage(e)); }
                    setBusy(null);
                  }}>{busy === p.code ? <Spinner /> : p.price_monthly ? <IconSparkles className="h-5 w-5" /> : null}{p.price_monthly ? `Pakai ${p.name}` : 'Pindah ke paket gratis'}</button>
              )}
            </li>
          );
        })}
      </ul>
      {error && <Alert tone="danger">{error}</Alert>}
      <p className="text-sm text-ink-600">Tagihan pascabayar: setiap tanggal 1 kami menagih pemakaian bulan sebelumnya (prorata per hari). Jamaah tidak pernah dikenai biaya platform.</p>
    </div>
  );
}

async function upload(file: File): Promise<string> {
  if (file.size > 5 * 1024 * 1024) throw new Error('Ukuran file maksimal 5 MB.');
  const fd = new FormData();
  fd.set('purpose', 'payment_proof');
  fd.set('file', file);
  return (await api<{ data: { id: string } }>('/files', { form: fd })).data.id;
}

/** Daftar tagihan platform + konfirmasi pembayaran (unggah bukti transfer). */
export function InvoiceList({ invoices, confirmBase, canPay, bankAccount }: { invoices: PlatformInvoice[]; confirmBase: string; canPay: boolean; bankAccount: string }) {
  const router = useRouter();
  const [open, setOpen] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (invoices.length === 0) return <p className="text-ink-600">Belum ada tagihan. Tagihan pertama terbit tanggal 1 bulan depan bila ada pemakaian berbayar.</p>;
  return (
    <ul className="space-y-3">
      {invoices.map((i) => (
        <li key={i.id} className={`rounded-[12px] border p-4 ${i.overdue ? 'border-danger' : 'border-border-subtle'}`}>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="flex items-center gap-2 font-bold"><IconReceipt className="h-5 w-5 text-primary-700" />{i.number} · {bulan(i.period)}</p>
              <p className="text-sm text-ink-600">Jatuh tempo {tanggal(i.due_on)}{i.overdue && <span className="font-semibold text-danger"> · lewat jatuh tempo</span>}</p>
            </div>
            <div className="text-right">
              <p className="num text-lg font-extrabold text-primary-800">{rupiah(i.total)}</p>
              <StatusPill status={i.status === 'paid' ? 'paid' : i.status === 'void' ? 'cancelled' : i.status === 'awaiting_confirmation' ? 'pending' : 'unpaid'} />
            </div>
          </div>
          <ul className="mt-2 divide-y divide-border-subtle text-sm">
            {i.items.map((it, k) => (
              <li key={k} className="flex justify-between gap-3 py-1"><span>{it.description}{it.quantity > 1 && ` × ${it.quantity}`}</span><span className="num">{rupiah(it.amount)}</span></li>
            ))}
          </ul>
          {i.review_note && i.status === 'unpaid' && <p className="mt-2 text-sm font-semibold text-danger">Catatan tim: {i.review_note}</p>}
          {i.status === 'awaiting_confirmation' && <p className="mt-2 text-sm text-ink-600">Bukti pembayaran sedang diperiksa tim UMRAH CONNECT ({INVOICE_STATUS[i.status].toLowerCase()}).</p>}
          {i.status === 'paid' && i.paid_at && <p className="mt-2 flex items-center gap-1 text-sm text-success"><IconCircleCheck className="h-4 w-4" />Lunas {tanggal(i.paid_at.slice(0, 10))}</p>}
          {canPay && i.status === 'unpaid' && (open === i.id ? (
            <form className="mt-3 space-y-2 rounded-[12px] bg-surface p-3" noValidate onSubmit={async (e) => {
              e.preventDefault();
              const fd = new FormData(e.currentTarget);
              const file = fd.get('proof') as File | null;
              setBusy(true); setError(null);
              try {
                const proof = file && file.size > 0 ? await upload(file) : null;
                if (!proof) throw new Error('Lampirkan bukti transfer.');
                await api(`${confirmBase}/${i.id}/confirm`, { json: { proof_file_id: proof, note: String(fd.get('note') ?? '').trim() || null } });
                setOpen(null); router.refresh();
              } catch (err) { setError(err instanceof ApiClientError ? err.message : err instanceof Error ? err.message : errorMessage(err)); }
              setBusy(false);
            }}>
              <p className="text-sm">Transfer <span className="num font-bold">{rupiah(i.total)}</span> ke <strong>{bankAccount}</strong>, berita: <span className="num font-semibold">{i.number}</span>.</p>
              <Field id={`pf-${i.id}`} label="Bukti transfer"><input id={`pf-${i.id}`} name="proof" type="file" accept="image/jpeg,image/png,application/pdf" className="input py-2.5" /></Field>
              <Field id={`pn-${i.id}`} label="Catatan" optional><input id={`pn-${i.id}`} name="note" className="input" maxLength={500} placeholder="Mis. transfer dari rekening BSI a.n. PT …" /></Field>
              {error && <Alert tone="danger">{error}</Alert>}
              <div className="flex gap-2">
                <button className="btn btn-primary" disabled={busy}>{busy ? <Spinner /> : <IconUpload className="h-5 w-5" />}Kirim konfirmasi</button>
                <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={() => setOpen(null)}>Batal</button>
              </div>
            </form>
          ) : (
            <button type="button" className="btn btn-gold mt-3" onClick={() => { setOpen(i.id); setError(null); }}><IconUpload className="h-5 w-5" />Saya sudah transfer</button>
          ))}
        </li>
      ))}
    </ul>
  );
}

/** Paket pilihan (promosi berbayar) milik PPIU. */
export function FeaturedPanel({ tenantId, placements, packages, price, slotsLeft, canManage, pastDue }: {
  tenantId: string; placements: FeaturedPlacement[]; packages: { id: string; name: string }[]; price: number; slotsLeft: number; canManage: boolean; pastDue: boolean;
}) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const active = placements.filter((p) => p.status === 'active');
  const available = packages.filter((p) => !active.some((a) => a.package.id === p.id));
  const run = async (key: string, fn: () => Promise<unknown>) => {
    setBusy(key); setError(null);
    try { await fn(); router.refresh(); } catch (e) { setError(errorMessage(e)); }
    setBusy(null);
  };
  return (
    <div className="space-y-3">
      <p className="text-sm text-ink-600">Paket tampil di bagian &quot;Paket pilihan&quot; beranda dan di urutan teratas pencarian, selalu berlabel <strong>Promosi</strong>. Biaya {rupiah(price)}/bulan per paket, ditagih prorata per hari. Sisa slot: <strong>{slotsLeft}</strong>.</p>
      {active.length > 0 && (
        <ul className="divide-y divide-border-subtle rounded-[12px] border border-border-subtle">
          {active.map((f) => (
            <li key={f.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
              <span><span className="font-semibold">{f.package.name}</span> <span className="text-sm text-ink-600">· sejak {tanggal(f.starts_at.slice(0, 10))} · {rupiah(f.price_monthly)}/bulan</span></span>
              {canManage && <button type="button" className="text-sm font-semibold text-danger hover:underline" disabled={!!busy}
                onClick={() => { if (confirm(`Hentikan promosi "${f.package.name}"? Biaya dihitung sampai hari ini.`)) run(f.id, () => api(`/tenants/${tenantId}/featured/${f.id}/end`, { json: {} })); }}>Hentikan</button>}
            </li>
          ))}
        </ul>
      )}
      {canManage && (available.length === 0 ? <p className="text-sm text-ink-600">Semua paket terbit Anda sudah dipromosikan.</p> : (
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => {
          e.preventDefault();
          const id = String(new FormData(e.currentTarget).get('package_id') ?? '');
          const name = available.find((p) => p.id === id)?.name;
          if (id && confirm(`Promosikan "${name}" mulai hari ini? Biaya ${rupiah(price)}/bulan.`)) run('start', () => api(`/tenants/${tenantId}/featured`, { json: { package_id: id } }));
        }}>
          <div className="min-w-60 flex-1">
            <label htmlFor="ft-pkg" className="mb-1 block text-sm font-semibold">Paket</label>
            <select id="ft-pkg" name="package_id" className="input">{available.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
          </div>
          <button className="btn btn-gold" disabled={!!busy || slotsLeft === 0 || pastDue}>{busy === 'start' ? <Spinner /> : <IconSparkles className="h-5 w-5" />}Promosikan</button>
        </form>
      ))}
      {error && <Alert tone="danger">{error}</Alert>}
    </div>
  );
}
