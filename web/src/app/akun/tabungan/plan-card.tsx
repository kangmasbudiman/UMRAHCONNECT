'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Spinner } from '@/components/form';
import { IconBell, IconPiggy, IconPlus, IconTrash } from '@/components/icons';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { rupiah, tanggal } from '@/lib/format';

export type Plan = {
  id: string; label: string; persons: number; status: 'active' | 'reached' | 'archived'; target_amount: number; saved_amount: number;
  monthly_amount: number; remaining_amount: number; progress: number; target_date: string; months_left: number; reminder_day: number | null;
  package: { id: string; name: string; slug: string; tenant_slug: string | null; tenant_name: string | null } | null;
  entries: { id: string; amount: number; saved_on: string; note: string | null }[];
};
const digits = (v: FormDataEntryValue | null) => Number(String(v ?? '').replace(/\D/g, '')) || 0;

export function PlanCard({ plan }: { plan: Plan }) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showAll, setShowAll] = useState(false);
  const reached = plan.status === 'reached';
  const call = async (key: string, fn: () => Promise<unknown>) => {
    setBusy(key);
    setError(null);
    try { await fn(); router.refresh(); } catch (e) { setError(e instanceof ApiClientError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : errorMessage(e)); }
    setBusy(null);
  };
  return (
    <article className={`card overflow-hidden ${reached ? 'border-success' : ''}`}>
      <div className="flex flex-wrap items-start justify-between gap-3 p-5">
        <div className="min-w-0">
          <h2 className="flex items-center gap-2 text-lg font-extrabold"><IconPiggy className="h-5 w-5 text-primary-700" />{plan.label}</h2>
          <p className="text-sm text-ink-600">{plan.persons} orang · target {tanggal(plan.target_date)}{plan.package?.tenant_slug && <> · <Link className="font-semibold text-primary-700 underline" href={`/paket/${plan.package.tenant_slug}/${plan.package.slug}`}>lihat paket</Link></>}</p>
        </div>
        <div className="text-right">
          <p className="text-sm text-ink-600">{reached ? 'Target tercapai' : `Sisihkan per bulan (${plan.months_left} bulan lagi)`}</p>
          <p className="num text-2xl font-extrabold text-primary-800">{reached ? '🎉' : rupiah(plan.monthly_amount)}</p>
        </div>
      </div>
      <div className="px-5">
        <div className="flex justify-between text-sm"><span className="num font-bold">{rupiah(plan.saved_amount)}</span><span className="num text-ink-600">dari {rupiah(plan.target_amount)}</span></div>
        <div className="mt-1 h-3 overflow-hidden rounded-full bg-surface" role="progressbar" aria-valuenow={plan.progress} aria-valuemin={0} aria-valuemax={100} aria-label={`Tabungan ${plan.label}`}>
          <div className={`h-full rounded-full ${reached ? 'bg-success' : 'bg-gradient-to-r from-primary-700 to-gold-500'}`} style={{ width: `${plan.progress}%` }} />
        </div>
        <p className="mt-1 text-sm text-ink-600">{plan.progress}% · kurang {rupiah(plan.remaining_amount)}{plan.reminder_day && <> · <IconBell className="inline h-4 w-4" /> diingatkan tiap tanggal {plan.reminder_day}</>}</p>
      </div>

      <form className="m-5 grid gap-2 rounded-[12px] bg-surface p-3 sm:grid-cols-[140px_1fr_1fr_auto] sm:items-end" noValidate onSubmit={(e) => {
        e.preventDefault();
        const form = e.currentTarget;
        const fd = new FormData(form);
        const amount = digits(fd.get('amount')) * (fd.get('kind') === 'out' ? -1 : 1);
        if (!amount) { setError('Isi nominal.'); return; }
        call('entry', async () => { await api(`/me/savings-plans/${plan.id}/entries`, { json: { amount, note: String(fd.get('note') ?? '').trim() || null } }); form.reset(); });
      }}>
        <div>
          <label htmlFor={`k-${plan.id}`} className="mb-1 block text-sm font-semibold">Jenis</label>
          <select id={`k-${plan.id}`} name="kind" className="input"><option value="in">Setoran</option><option value="out">Penarikan</option></select>
        </div>
        <div>
          <label htmlFor={`a-${plan.id}`} className="mb-1 block text-sm font-semibold">Nominal</label>
          <input id={`a-${plan.id}`} name="amount" inputMode="numeric" className="input num" placeholder={String(plan.monthly_amount || '')} />
        </div>
        <div>
          <label htmlFor={`n-${plan.id}`} className="mb-1 block text-sm font-semibold">Catatan <span className="font-normal text-ink-600">(opsional)</span></label>
          <input id={`n-${plan.id}`} name="note" className="input" maxLength={200} placeholder="Mis. gaji Desember" />
        </div>
        <button className="btn btn-primary" disabled={!!busy}>{busy === 'entry' ? <Spinner /> : <IconPlus className="h-5 w-5" />}Catat</button>
      </form>
      {error && <p role="alert" className="-mt-3 mb-3 px-5 text-sm font-semibold text-danger">{error}</p>}

      {plan.entries.length > 0 && (
        <div className="border-t border-border-subtle px-5 py-3">
          <p className="text-sm font-semibold">Riwayat</p>
          <ul className="mt-1 divide-y divide-border-subtle text-sm">
            {(showAll ? plan.entries : plan.entries.slice(0, 5)).map((e) => (
              <li key={e.id} className="flex justify-between gap-2 py-1.5">
                <span>{tanggal(e.saved_on)}{e.note && <span className="text-ink-600"> · {e.note}</span>}</span>
                <span className={`num font-semibold ${e.amount < 0 ? 'text-danger' : 'text-success'}`}>{e.amount < 0 ? '−' : '+'}{rupiah(Math.abs(e.amount))}</span>
              </li>
            ))}
          </ul>
          {plan.entries.length > 5 && <button type="button" className="mt-1 text-sm font-semibold text-primary-700 underline" onClick={() => setShowAll((v) => !v)}>{showAll ? 'Ringkas' : `Lihat semua (${plan.entries.length})`}</button>}
        </div>
      )}
      <div className="flex flex-wrap items-center gap-3 border-t border-border-subtle px-5 py-3 text-sm">
        <label htmlFor={`r-${plan.id}`} className="font-semibold">Pengingat</label>
        <select id={`r-${plan.id}`} className="input min-h-10 w-auto py-1" defaultValue={plan.reminder_day ?? ''} disabled={!!busy}
          onChange={(e) => call('rem', () => api(`/me/savings-plans/${plan.id}`, { method: 'PATCH', json: { reminder_day: e.target.value ? Number(e.target.value) : null } }))}>
          <option value="">Tidak ada</option>
          {Array.from({ length: 28 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>Tanggal {d}</option>)}
        </select>
        <button type="button" className="ml-auto inline-flex min-h-10 items-center gap-1 font-semibold text-danger hover:underline" disabled={!!busy}
          onClick={() => { if (confirm(`Hapus rencana "${plan.label}" beserta riwayat setorannya?`)) call('del', () => api(`/me/savings-plans/${plan.id}`, { method: 'DELETE' })); }}>
          <IconTrash className="h-4 w-4" />Hapus rencana
        </button>
      </div>
    </article>
  );
}
