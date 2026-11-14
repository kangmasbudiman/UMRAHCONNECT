import Link from 'next/link';
import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { CommissionTable, PayoutActions } from '@/components/commission-actions';
import { COMMISSION_STATUS, type CommissionRow, type CommissionStatus, type Payout, type Totals } from '@/lib/finance-types';
import { rupiah, tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';

export const metadata = { title: 'Komisi agen' };

const TABS: CommissionStatus[] = ['earned', 'approved', 'paid', 'estimated', 'cancelled'];

/** PPIU-17 Komisi & pencairan (FR-FIN-07..09): setujui komisi Diperoleh, bayar pengajuan pencairan dengan bukti. */
async function Commissions({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/komisi'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/komisi`, 'commission.view');
  if (!isTenantPage(page)) return page;
  const perms = page.membership.permissions;
  const status = (typeof sp.status === 'string' && TABS.includes(sp.status as CommissionStatus) ? sp.status : 'earned') as CommissionStatus;
  const [list, payouts] = await Promise.all([
    serverApi<{ data: CommissionRow[]; meta: { totals: Totals } }>(`/tenants/${id}/commissions?filter[status]=${status}&limit=100`),
    serverApi<{ data: Payout[] }>(`/tenants/${id}/payouts`).then((r) => r.data),
  ]);
  const t = list.meta.totals;
  const requested = payouts.filter((p) => p.status === 'requested');
  const mode = status === 'earned' && perms.includes('commission.approve') ? 'approve' : status === 'approved' && perms.includes('payout.pay') ? 'payout-tenant' : 'view';

  return (
    <>
      <TenantHeader page={page} active="komisi" sub="Komisi dihitung otomatis dari skema saat booking; diperoleh saat DP terverifikasi atau lunas sesuai skema." />
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        <ul className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {(['earned', 'approved', 'paid', 'estimated'] as CommissionStatus[]).map((s) => (
            <li key={s} className="card p-4">
              <p className="text-sm text-ink-600">{s === 'earned' ? 'Menunggu persetujuan' : s === 'approved' ? 'Siap dibayar' : s === 'paid' ? 'Sudah dibayar' : 'Perkiraan (belum diperoleh)'}</p>
              <p className="num text-2xl font-extrabold text-primary-800">{rupiah(t[s]?.amount ?? 0)}</p>
              <p className="text-sm text-ink-600">{t[s]?.count ?? 0} komisi</p>
            </li>
          ))}
        </ul>

        {requested.length > 0 && (
          <section className="card space-y-3 border-gold-500 p-5" aria-labelledby="po-h">
            <h2 id="po-h" className="text-xl font-extrabold">Pengajuan pencairan menunggu dibayar</h2>
            <ul className="divide-y divide-border-subtle">
              {requested.map((p) => (
                <li key={p.id} className="grid gap-3 py-3 md:grid-cols-[1fr_auto] md:items-center">
                  <div>
                    <p className="font-bold">{p.agent.display_name} <span className="num font-normal text-ink-600">({p.agent.code})</span> · <span className="num">{rupiah(p.total_amount)}</span></p>
                    <p className="text-sm text-ink-600">{p.commission_count} komisi · diajukan {p.requested_at && tanggal(p.requested_at.slice(0, 10))}</p>
                    <p className="text-sm">Transfer ke <span className="font-semibold">{p.agent.payout_bank_name} {p.agent.payout_account_no_masked}</span> a.n. {p.agent.payout_account_holder}</p>
                  </div>
                  {perms.includes('payout.pay') && <PayoutActions tenantId={id} payoutId={p.id} />}
                </li>
              ))}
            </ul>
          </section>
        )}

        <section aria-labelledby="com-h" className="space-y-3">
          <h2 id="com-h" className="text-xl font-extrabold">Komisi</h2>
          <nav aria-label="Status komisi" className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
            {TABS.map((s) => (
              <Link key={s} href={`/dasbor/ppiu/${id}/komisi?status=${s}`} aria-current={s === status ? 'page' : undefined}
                className={`chip shrink-0 ${s === status ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
                {COMMISSION_STATUS[s]} <span className={`num rounded-full px-2 text-xs ${s === status ? 'bg-white/20' : 'bg-surface'}`}>{t[s]?.count ?? 0}</span>
              </Link>
            ))}
          </nav>
          <CommissionTable rows={list.data} mode={mode} tenantId={id} />
        </section>

        {payouts.filter((p) => p.status !== 'requested').length > 0 && (
          <section className="card p-5" aria-labelledby="poh-h">
            <h2 id="poh-h" className="text-lg font-extrabold">Riwayat pencairan</h2>
            <ul className="mt-2 divide-y divide-border-subtle">
              {payouts.filter((p) => p.status !== 'requested').map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span>{p.agent.display_name} · {p.commission_count} komisi{p.reject_reason && <span className="text-danger"> · {p.reject_reason}</span>}</span>
                  <span className="flex items-center gap-2"><span className="num font-bold">{rupiah(p.total_amount)}</span><StatusPill status={`po_${p.status}`} /></span>
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </>
  );
}

export default function TenantCommissionsPage(props: PageProps<'/dasbor/ppiu/[tenant]/komisi'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Commissions {...props} /></Suspense>;
}
