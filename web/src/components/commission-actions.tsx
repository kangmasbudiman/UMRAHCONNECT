'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner, StatusPill } from '@/components/form';
import { api, errorMessage } from '@/lib/client-api';
import { rupiah, tanggal } from '@/lib/format';
import type { CommissionRow } from '@/lib/finance-types';

/**
 * Daftar komisi dengan pilihan (checkbox). Mode:
 * - 'approve' (PPIU): setujui komisi Diperoleh;
 * - 'payout-tenant' (PPIU): buat pencairan langsung untuk satu agen dari komisi Disetujui;
 * - 'payout-agent' (agen): ajukan pencairan komisi Disetujui di satu PPIU.
 */
export function CommissionTable({ rows, mode, tenantId, showAgent = true, showTenant = false }: {
  rows: CommissionRow[]; mode: 'approve' | 'payout-tenant' | 'payout-agent' | 'view'; tenantId?: string; showAgent?: boolean; showTenant?: boolean;
}) {
  const router = useRouter();
  const [sel, setSel] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [key, setKey] = useState(() => crypto.randomUUID());
  const selectable = (c: CommissionRow) => (mode === 'approve' ? c.status === 'earned' : mode !== 'view' && c.status === 'approved' && !c.payout_id);
  const chosen = rows.filter((r) => sel.includes(r.id));
  const sum = chosen.reduce((a, c) => a + c.amount, 0);
  // Pencairan harus satu agen (PPIU) atau satu PPIU (agen).
  const groupKey = (c: CommissionRow) => (mode === 'payout-agent' ? c.tenant.id : c.agent?.id ?? '');
  const lockedGroup = chosen[0] ? groupKey(chosen[0]) : null;
  const canPick = (c: CommissionRow) => selectable(c) && (!lockedGroup || groupKey(c) === lockedGroup || mode === 'approve');
  const anySelectable = rows.some(selectable);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      if (mode === 'approve') {
        await api(`/tenants/${tenantId}/commissions/approve`, { json: { ids: sel } });
        setDone(`${sel.length} komisi disetujui.`);
      } else if (mode === 'payout-tenant') {
        await api(`/tenants/${tenantId}/payouts`, { json: { agent_id: chosen[0].agent!.id, ids: sel }, headers: { 'Idempotency-Key': key } });
        setDone('Pencairan dibuat. Catat transfernya di daftar pencairan.');
      } else {
        await api('/agent/payout-requests', { json: { tenant_id: chosen[0].tenant.id, ids: sel }, headers: { 'Idempotency-Key': key } });
        setDone('Pengajuan pencairan terkirim ke PPIU.');
      }
      setSel([]);
      setKey(crypto.randomUUID());
      router.refresh();
    } catch (e) { setError(errorMessage(e)); }
    setBusy(false);
  }

  if (rows.length === 0) return <p className="rounded-[12px] border border-dashed border-border-input p-6 text-center text-ink-600">Belum ada komisi.</p>;
  const action = mode === 'approve' ? 'Setujui' : mode === 'payout-tenant' ? 'Buat pencairan' : 'Ajukan pencairan';

  return (
    <div className="space-y-3">
      {done && <Alert tone="success">{done}</Alert>}
      {error && <Alert tone="danger">{error}</Alert>}
      <div className="overflow-x-auto rounded-[12px] border border-border-subtle bg-white">
        <table className="w-full min-w-[640px] text-left text-sm">
          <thead className="bg-surface text-ink-600">
            <tr>
              {mode !== 'view' && anySelectable && <th className="w-10 px-3 py-2"><span className="sr-only">Pilih</span></th>}
              <th className="px-3 py-2">Booking</th>
              {showAgent && <th className="px-3 py-2">Agen</th>}
              {showTenant && <th className="px-3 py-2">PPIU</th>}
              <th className="px-3 py-2">Berangkat</th>
              <th className="px-3 py-2 text-right">Komisi</th>
              <th className="px-3 py-2">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border-subtle">
            {rows.map((c) => (
              <tr key={c.id} className={sel.includes(c.id) ? 'bg-primary-50' : ''}>
                {mode !== 'view' && anySelectable && (
                  <td className="px-3 py-2">
                    {selectable(c) && <input type="checkbox" aria-label={`Pilih komisi ${c.booking.code}`} className="h-5 w-5 accent-primary-700" disabled={!canPick(c)}
                      checked={sel.includes(c.id)} onChange={(e) => setSel((s) => (e.target.checked ? [...s, c.id] : s.filter((x) => x !== c.id)))} />}
                  </td>
                )}
                <td className="px-3 py-2"><span className="num font-semibold">{c.booking.code}</span><br /><span className="text-ink-600">{c.booking.package_name} · {c.booking.participant_count} jamaah</span></td>
                {showAgent && <td className="px-3 py-2">{c.agent?.display_name} <span className="num text-ink-600">({c.agent?.code})</span></td>}
                {showTenant && <td className="px-3 py-2">{c.tenant.brand_name}</td>}
                <td className="px-3 py-2">{tanggal(c.booking.departure_date)}</td>
                <td className="num px-3 py-2 text-right font-bold">{rupiah(c.amount)}</td>
                <td className="px-3 py-2"><StatusPill status={`com_${c.status}`} />{c.payout_id && c.status === 'approved' && <span className="ml-1 text-xs text-ink-600">diajukan</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {mode !== 'view' && anySelectable && (
        <div className="flex flex-wrap items-center gap-3">
          <button type="button" className="btn btn-gold" disabled={busy || sel.length === 0} onClick={submit}>{busy && <Spinner />}{action}{sel.length > 0 && ` (${sel.length} · ${rupiah(sum)})`}</button>
          {mode !== 'approve' && <p className="text-sm text-ink-600">Satu pencairan untuk satu {mode === 'payout-agent' ? 'PPIU' : 'agen'}.</p>}
        </div>
      )}
    </div>
  );
}

/** Catat transfer komisi (bukti wajib) atau tolak pengajuan pencairan. */
export function PayoutActions({ tenantId, payoutId }: { tenantId: string; payoutId: string }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [key] = useState(() => crypto.randomUUID());
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());

  if (!open) {
    return (
      <div className="flex flex-wrap gap-2">
        <button type="button" className="btn btn-primary min-h-10 px-4" onClick={() => setOpen(true)}>Catat transfer</button>
        <button type="button" className="btn border border-danger bg-white text-danger hover:bg-danger-bg min-h-10 px-4" disabled={busy} onClick={async () => {
          const reason = prompt('Alasan menolak pencairan (ditampilkan ke agen):');
          if (!reason || reason.trim().length < 3) return;
          setBusy(true);
          try { await api(`/tenants/${tenantId}/payouts/${payoutId}/reject`, { json: { reason: reason.trim() } }); router.refresh(); }
          catch (e) { setError(errorMessage(e)); }
          setBusy(false);
        }}>Tolak</button>
        {error && <p role="alert" className="w-full text-sm font-semibold text-danger">{error}</p>}
      </div>
    );
  }
  return (
    <form className="grid gap-3 rounded-[12px] bg-surface p-3 sm:grid-cols-2" onSubmit={async (e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      setBusy(true);
      setError(null);
      try {
        const file = fd.get('proof') as File | null;
        if (!file || file.size === 0) throw new Error('Unggah bukti transfer komisi.');
        const up = new FormData();
        up.set('purpose', 'payout_proof');
        up.set('file', file);
        const f = await api<{ data: { id: string } }>('/files', { form: up });
        const paidAt = new Date(`${fd.get('paid_on')}T12:00:00+07:00`).toISOString();
        await api(`/tenants/${tenantId}/payouts/${payoutId}/pay`, { json: { paid_at: paidAt, proof_file_id: f.data.id }, headers: { 'Idempotency-Key': key } });
        router.refresh();
      } catch (err) { setError(err instanceof Error ? err.message : errorMessage(err)); setBusy(false); }
    }}>
      <Field id={`po-date-${payoutId}`} label="Tanggal transfer"><input id={`po-date-${payoutId}`} name="paid_on" type="date" className="input" defaultValue={today} max={today} /></Field>
      <Field id={`po-proof-${payoutId}`} label="Bukti transfer"><input id={`po-proof-${payoutId}`} name="proof" type="file" accept="image/jpeg,image/png,application/pdf" className="input py-2.5" /></Field>
      {error && <p role="alert" className="text-sm font-semibold text-danger sm:col-span-2">{error}</p>}
      <div className="flex gap-2 sm:col-span-2">
        <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Simpan, komisi jadi Dibayar</button>
        <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={() => setOpen(false)}>Batal</button>
      </div>
    </form>
  );
}
