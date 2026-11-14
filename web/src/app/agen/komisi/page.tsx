import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { CommissionTable } from '@/components/commission-actions';
import type { CommissionRow, Payout, Totals } from '@/lib/finance-types';
import { rupiah, tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';
import { AgentHeader, requireAgent } from '../agent-shell';

export const metadata = { title: 'Komisi agen' };

/** AGT-09/10 Komisi & pencairan (FR-AGT-10/11): komisi per PPIU dan status; ajukan pencairan komisi yang sudah disetujui. */
async function Commissions() {
  const ctx = await requireAgent('/agen/komisi');
  const [list, payouts] = await Promise.all([
    serverApi<{ data: CommissionRow[]; meta: { totals: Totals } }>('/agent/commissions?limit=100'),
    serverApi<{ data: Payout[] }>('/agent/payout-requests').then((r) => r.data),
  ]);
  const t = list.meta.totals;
  const cards = [
    ['Perkiraan', 'estimated', 'Booking belum memenuhi syarat komisi'],
    ['Diperoleh', 'earned', 'Menunggu persetujuan PPIU'],
    ['Disetujui', 'approved', 'Bisa diajukan pencairan'],
    ['Dibayar', 'paid', 'Sudah ditransfer PPIU'],
  ] as const;

  return (
    <>
      <AgentHeader ctx={ctx} active="komisi" title="Komisi" sub="Komisi dibayar langsung oleh PPIU ke rekening Anda. UMRAH CONNECT tidak memotong komisi." />
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {cards.map(([l, k, s]) => (
            <li key={k} className="card p-4">
              <p className="text-sm font-semibold">{l}</p>
              <p className="num text-2xl font-extrabold text-primary-800">{rupiah(t[k]?.amount ?? 0)}</p>
              <p className="text-sm text-ink-600">{t[k]?.count ?? 0} komisi · {s}</p>
            </li>
          ))}
        </ul>
        <section className="space-y-3" aria-labelledby="k-h">
          <h2 id="k-h" className="text-xl font-extrabold">Daftar komisi</h2>
          <p className="text-ink-600">Pilih komisi berstatus Disetujui dari satu PPIU, lalu ajukan pencairan.</p>
          <CommissionTable rows={list.data} mode="payout-agent" showAgent={false} showTenant />
        </section>
        <section className="card p-5" aria-labelledby="p-h">
          <h2 id="p-h" className="text-lg font-extrabold">Pencairan</h2>
          {payouts.length === 0 ? <p className="mt-2 text-ink-600">Belum ada pengajuan pencairan.</p> : (
            <ul className="mt-2 divide-y divide-border-subtle">
              {payouts.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                  <span>
                    <span className="font-bold">{p.tenant.brand_name}</span> · {p.commission_count} komisi
                    <br /><span className="text-sm text-ink-600">Diajukan {p.requested_at && tanggal(p.requested_at.slice(0, 10))}{p.paid_at && ` · ditransfer ${tanggal(p.paid_at.slice(0, 10))}`}{p.reject_reason && ` · ditolak: ${p.reject_reason}`}</span>
                  </span>
                  <span className="flex items-center gap-2"><span className="num font-extrabold">{rupiah(p.total_amount)}</span><StatusPill status={`po_${p.status}`} /></span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </>
  );
}

export default function AgentCommissionsPage() {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Commissions /></Suspense>;
}
