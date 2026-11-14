import Link from 'next/link';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { serverApi } from '@/lib/session';
import type { Partnership, Scheme } from '@/lib/agent-types';
import type { PackageManaged } from '@/lib/tenant-types';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';
import { InviteAgentForm, PartnershipRowActions, SchemeManager } from './agent-admin';

export const metadata = { title: 'Agen & komisi' };

const TABS = [
  { s: '', label: 'Semua' }, { s: 'applied', label: 'Pengajuan' }, { s: 'active', label: 'Aktif' },
  { s: 'invited', label: 'Diundang' }, { s: 'suspended', label: 'Ditangguhkan' }, { s: 'ended', label: 'Berakhir' }, { s: 'rejected', label: 'Ditolak' },
];

async function Agents({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/agen'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/agen`, 'partnership.view');
  if (!isTenantPage(page)) return page;
  const perms = page.membership.permissions;
  const status = typeof sp.status === 'string' && TABS.some((t) => t.s === sp.status) ? sp.status : '';
  const [list, schemes, pkgs, allActive] = await Promise.all([
    serverApi<{ data: Partnership[]; meta: { counts: Record<string, number> } }>(`/tenants/${id}/partnerships?limit=100${status ? `&filter[status]=${status}` : ''}`),
    perms.includes('commission_scheme.view') ? serverApi<{ data: Scheme[] }>(`/tenants/${id}/commission-schemes`).then((r) => r.data) : Promise.resolve(null),
    perms.includes('package.view') ? serverApi<{ data: PackageManaged[] }>(`/tenants/${id}/packages?limit=100`).then((r) => r.data) : Promise.resolve([]),
    serverApi<{ data: Partnership[] }>(`/tenants/${id}/partnerships?limit=100`).then((r) => r.data.filter((p) => ['active', 'suspended', 'invited'].includes(p.status))),
  ]);
  const total = Object.values(list.meta.counts).reduce((a, b) => a + b, 0);
  const canManage = perms.includes('partnership.manage');
  const defaultLabel = schemes?.find((s) => s.scope === 'default' && !s.effective_to)?.label ?? null;
  const verified = page.tenant.verification_status === 'verified';

  return (
    <>
      <TenantHeader page={page} active="agen" sub="Setujui atau undang agen, atur komisi, dan pantau link referral mereka." />
      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-6 lg:grid-cols-[1fr_380px]">
        <div className="space-y-4">
          {!verified && <Alert tone="info">Kemitraan agen dibuka setelah PPIU terverifikasi.</Alert>}
          <nav aria-label="Status kemitraan" className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
            {TABS.map((t) => {
              const n = t.s ? (list.meta.counts[t.s] ?? 0) : total;
              const on = t.s === status;
              return (
                <Link key={t.s || 'all'} href={`/dasbor/ppiu/${id}/agen${t.s ? `?status=${t.s}` : ''}`} aria-current={on ? 'page' : undefined}
                  className={`chip shrink-0 ${on ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
                  {t.label} <span className={`num rounded-full px-2 text-xs ${on ? 'bg-white/20' : t.s === 'applied' && n > 0 ? 'bg-warning-bg text-warning' : 'bg-surface'}`}>{n}</span>
                </Link>
              );
            })}
          </nav>
          {list.data.length === 0 ? (
            <div className="card p-8 text-center text-ink-600">{total === 0 ? 'Belum ada agen. Undang agen yang Anda kenal, atau tunggu pengajuan dari agen.' : 'Tidak ada kemitraan dengan status ini.'}</div>
          ) : (
            <ul className="space-y-3">
              {list.data.map((p) => (
                <li key={p.id} className="card space-y-3 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p className="text-lg font-extrabold">{p.agent.display_name} <span className="num text-sm font-semibold text-ink-600">{p.agent.code}</span></p>
                      <p className="text-sm text-ink-600">{[p.agent.domicile_city, p.agent.phone, p.agent.email].filter(Boolean).join(' · ')}</p>
                    </div>
                    <StatusPill status={p.status} />
                  </div>
                  {p.message && <p className="rounded-[10px] bg-surface px-3 py-2 text-sm">“{p.message}”</p>}
                  <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <span>Komisi: <span className="font-bold">{p.commission_scheme?.label ?? '—'}</span>{p.commission_scheme?.scope === 'agent' && <span className="text-ink-600"> (khusus)</span>}</span>
                    <span>Klik link: <span className="num font-bold">{p.referral_clicks ?? 0}</span></span>
                    <span className="text-ink-600">{p.source === 'invited' ? 'Diundang PPIU' : 'Mengajukan sendiri'}</span>
                  </div>
                  {(p.reject_reason || p.status_note) && <p className="text-sm text-ink-600">Catatan: {p.reject_reason ?? p.status_note}</p>}
                  {canManage && verified && <PartnershipRowActions tenantId={id} p={p} defaultLabel={defaultLabel} />}
                </li>
              ))}
            </ul>
          )}
        </div>
        <aside className="space-y-6">
          {canManage && verified && <section className="card p-5"><InviteAgentForm tenantId={id} hasDefault={!!defaultLabel} /></section>}
          {schemes && (
            <section className="card p-5" aria-labelledby="skema-h">
              <h2 id="skema-h" className="text-lg font-extrabold">Skema komisi</h2>
              <div className="mt-2">
                <SchemeManager tenantId={id} schemes={schemes} canManage={perms.includes('commission_scheme.manage')}
                  packages={pkgs.filter((p) => ['draft', 'published'].includes(p.status)).map((p) => ({ id: p.id, name: p.name }))}
                  agents={allActive.map((p) => ({ id: p.agent.id, code: p.agent.code, display_name: p.agent.display_name }))} />
              </div>
            </section>
          )}
        </aside>
      </div>
    </>
  );
}

export default function TenantAgentsPage(props: PageProps<'/dasbor/ppiu/[tenant]/agen'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Agents {...props} /></Suspense>;
}
