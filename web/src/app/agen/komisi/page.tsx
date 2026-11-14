import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { CommissionTable } from '@/components/commission-actions';
import type { CommissionRow, Payout, Totals } from '@/lib/finance-types';
import { rupiah, tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';
import { AgentHeader, requireAgent } from '../agent-shell';
import { IconCircleCheck, IconCommission, IconPayout, IconTimer, IconTrend } from '@/components/icons';
import { IconHeading, StatCard } from '@/components/stat-card';

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
    ['Perkiraan', 'estimated', 'Booking belum memenuhi syarat komisi', IconTrend],
    ['Diperoleh', 'earned', 'Menunggu persetujuan PPIU', IconTimer],
    ['Disetujui', 'approved', 'Bisa diajukan pencairan', IconCircleCheck],
    ['Dibayar', 'paid', 'Sudah ditransfer PPIU', IconPayout],
  ] as const;

  return (
    <>
      <AgentHeader ctx={ctx} active="komisi" title="Komisi" sub="Komisi dibayar langsung oleh PPIU ke rekening Anda. UMRAH CONNECT tidak memotong komisi." />
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {cards.map(([l, k, s, I]) => (
            <li key={k} className="card p-4">
              <StatCard icon={I} label={l} value={rupiah(t[k]?.amount ?? 0)} sub={`${t[k]?.count ?? 0} komisi · ${s}`} tone={k === 'paid' ? 'gold' : k === 'approved' ? 'primary' : k === 'earned' ? 'warning' : 'primary'} />
            </li>
          ))}
        </ul>
        <section className="space-y-3" aria-labelledby="k-h">
          <IconHeading icon={IconCommission} id="k-h">Daftar komisi</IconHeading>
          <p className="text-ink-600">Pilih komisi berstatus Disetujui dari satu PPIU, lalu ajukan pencairan.</p>
          <CommissionTable rows={list.data} mode="payout-agent" showAgent={false} showTenant />
        </section>
        <section className="card p-5" aria-labelledby="p-h">
          <IconHeading icon={IconPayout} id="p-h" className="text-lg font-extrabold">Pencairan</IconHeading>
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
