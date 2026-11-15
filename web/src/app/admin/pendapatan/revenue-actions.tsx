'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Spinner } from '@/components/form';
import { IconCircleCheck, IconCircleX, IconRefresh } from '@/components/icons';
import { api, errorMessage } from '@/lib/client-api';
import { rupiah } from '@/lib/format';

/** Aksi Super Admin atas tagihan: tandai lunas, tolak konfirmasi, batalkan. */
export function InvoiceActions({ id, status }: { id: string; status: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const run = async (path: string, needNote: string | null, confirmText: string) => {
    let note: string | null = null;
    if (needNote) {
      note = prompt(needNote);
      if (!note || note.trim().length < 5) return;
    } else if (!confirm(confirmText)) return;
    setBusy(true);
    try { await api(`/platform/invoices/${id}/${path}`, { json: note ? { note: note.trim() } : {} }); router.refresh(); } catch (e) { alert(errorMessage(e)); }
    setBusy(false);
  };
  if (status === 'paid' || status === 'void') return null;
  return (
    <div className="flex flex-wrap gap-2">
      <button type="button" className="btn btn-primary min-h-10 px-3 text-sm" disabled={busy} onClick={() => run('mark-paid', null, 'Tandai tagihan ini lunas? Pastikan dana sudah masuk rekening platform.')}>{busy ? <Spinner /> : <IconCircleCheck className="h-4 w-4" />}Tandai lunas</button>
      {status === 'awaiting_confirmation' && <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" disabled={busy} onClick={() => run('reject', 'Alasan menolak bukti (dikirim ke penagih):', '')}><IconCircleX className="h-4 w-4" />Tolak bukti</button>}
      <button type="button" className="min-h-10 px-2 text-sm font-semibold text-danger hover:underline" disabled={busy} onClick={() => run('void', 'Alasan membatalkan tagihan:', '')}>Batalkan</button>
    </div>
  );
}

export function PlanPriceForm({ code, price }: { code: string; price: number }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  return (
    <form className="flex flex-wrap items-center gap-2" onSubmit={async (e) => {
      e.preventDefault();
      const v = Number(String(new FormData(e.currentTarget).get('price') ?? '').replace(/\D/g, ''));
      setBusy(true); setMsg(null);
      try { await api(`/platform/plans/${code}`, { method: 'PUT', json: { price_monthly: v } }); setMsg('Tersimpan'); router.refresh(); } catch (err) { setMsg(errorMessage(err)); }
      setBusy(false);
    }}>
      <label htmlFor={`pp-${code}`} className="sr-only">Harga per bulan</label>
      <input id={`pp-${code}`} name="price" inputMode="numeric" className="input num min-h-10 w-36 py-1.5" defaultValue={price} />
      <button className="btn btn-outline min-h-10 px-3 text-sm" disabled={busy}>{busy && <Spinner />}Simpan</button>
      {msg && <span role="status" className="text-sm text-ink-600">{msg}</span>}
    </form>
  );
}

export function RunBilling({ period, billed }: { period: string; billed: boolean }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  return (
    <div className="flex flex-wrap items-center gap-3">
      <button type="button" className="btn btn-outline" disabled={busy} onClick={async () => {
        if (!confirm(`Buat tagihan periode ${period} sekarang? Periode yang sudah ditagih dilewati otomatis.`)) return;
        setBusy(true);
        try { const r = await api<{ data: { created: number } }>('/platform/billing/run', { json: { period } }); setMsg(`${r.data.created} tagihan dibuat.`); router.refresh(); } catch (e) { setMsg(errorMessage(e)); }
        setBusy(false);
      }}>{busy ? <Spinner /> : <IconRefresh className="h-5 w-5" />}Buat tagihan {period}</button>
      <span className="text-sm text-ink-600">{msg ?? (billed ? 'Periode ini sudah ditagih.' : 'Otomatis setiap tanggal 1 pukul 06.00 WIB.')}</span>
    </div>
  );
}

/** Kontrak per PPIU: paket (termasuk Enterprise), harga khusus, tarif biaya per transaksi. */
export function ContractForm({ tenantId, plan, priceOverride, fee, defaultFee }: { tenantId: string; plan: string; priceOverride: number | null; fee: number | null; defaultFee: number }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const num = (v: FormDataEntryValue | null) => { const s = String(v ?? '').replace(/\D/g, ''); return s === '' ? null : Number(s); };
  return (
    <form className="space-y-2" onSubmit={async (e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      setBusy(true); setMsg(null);
      try {
        await api(`/platform/tenants/${tenantId}/billing`, { method: 'PUT', json: { plan_code: fd.get('plan_code'), price_override: num(fd.get('price_override')), transaction_fee_amount: num(fd.get('fee')) } });
        setMsg('Kontrak tersimpan.'); router.refresh();
      } catch (err) { setMsg(errorMessage(err)); }
      setBusy(false);
    }}>
      <label htmlFor="ct-plan" className="block text-sm font-semibold">Paket</label>
      <select id="ct-plan" name="plan_code" className="input" defaultValue={plan}>
        <option value="ppiu_free">PPIU Free</option><option value="ppiu_business">PPIU Business</option><option value="ppiu_enterprise">PPIU Enterprise (kontrak)</option>
      </select>
      <label htmlFor="ct-price" className="block text-sm font-semibold">Harga khusus/bulan <span className="font-normal text-ink-600">(kosong = harga paket)</span></label>
      <input id="ct-price" name="price_override" inputMode="numeric" className="input num" defaultValue={priceOverride ?? ''} />
      <label htmlFor="ct-fee" className="block text-sm font-semibold">Biaya per booking lunas <span className="font-normal text-ink-600">(kosong = bawaan {rupiah(defaultFee)})</span></label>
      <input id="ct-fee" name="fee" inputMode="numeric" className="input num" defaultValue={fee ?? ''} />
      <button className="btn btn-primary w-full" disabled={busy}>{busy && <Spinner />}Simpan kontrak</button>
      {msg && <p role="status" className="text-sm text-ink-600">{msg}</p>}
    </form>
  );
}
