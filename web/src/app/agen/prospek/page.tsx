import Link from 'next/link';
import { connection } from 'next/server';
import { Suspense } from 'react';
import { WhatsAppButton } from '@/components/ui';
import { FOLLOW_UP, type AgentPackage, type AgentTenant, type FollowUpStatus, type Prospect } from '@/lib/agent-types';
import { tanggal, waLink } from '@/lib/format';
import { serverApi } from '@/lib/session';
import { AgentHeader, requireAgent } from '../agent-shell';
import { ProspectForm, ProspectRowActions } from './prospect-actions';
import { IconHeading } from '@/components/stat-card';
import { IconClipboard, IconInbox, IconSearch } from '@/components/icons';

export const metadata = { title: 'Prospek' };

const TABS: (FollowUpStatus | 'semua')[] = ['semua', 'new', 'contacted', 'interested', 'booked', 'lost'];
const todayWib = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());

/** AGT-07 Daftar prospek (FR-AGT-07): catat calon jamaah, jadwal follow-up, hubungi lewat WhatsApp. Hanya terlihat oleh Anda. */
async function Prospects({ searchParams }: PageProps<'/agen/prospek'>) {
  const ctx = await requireAgent('/agen/prospek');
  const sp = await searchParams;
  await connection(); // tanggal hari ini dihitung saat request
  const status = typeof sp.status === 'string' && (TABS as string[]).includes(sp.status) ? sp.status : 'semua';
  const q = typeof sp.q === 'string' ? sp.q.slice(0, 100) : '';
  const qs = new URLSearchParams({ limit: '100', ...(status !== 'semua' ? { 'filter[follow_up_status]': status } : {}), ...(q ? { q } : {}) });
  const [list, tenants, pkgs] = await Promise.all([
    serverApi<{ data: Prospect[]; meta: { counts: Partial<Record<FollowUpStatus, number>>; due: number } }>(`/agent/prospects?${qs}`),
    serverApi<{ data: AgentTenant[] }>('/agent/tenants?limit=100').then((r) => r.data.filter((t) => t.partnership_status === 'active')),
    serverApi<{ data: AgentPackage[] }>('/agent/packages?limit=100').then((r) => r.data),
  ]);
  const tenantOpts = tenants.map((t) => ({ id: t.id, name: t.brand_name }));
  const pkgOpts = pkgs.map((p) => ({ id: p.id, name: p.name, tenant_id: p.tenant.id }));
  const total = Object.values(list.meta.counts).reduce((a, b) => a + (b ?? 0), 0);
  const today = todayWib();

  return (
    <>
      <AgentHeader ctx={ctx} active="prospek" title="Prospek" sub="Catatan calon jamaah Anda. Hanya Anda yang bisa melihatnya; PPIU tidak." />
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        {list.meta.due > 0 && (
          <p role="status" className="rounded-[12px] border border-gold-500 bg-gold-50 p-4 font-semibold text-primary-800">
            {list.meta.due} prospek perlu di-follow-up hari ini atau sudah lewat jadwal.
          </p>
        )}
        <details className="card p-5" open={total === 0}>
          <summary className="cursor-pointer text-lg font-extrabold">+ Tambah prospek</summary>
          <div className="mt-4"><ProspectForm tenants={tenantOpts} packages={pkgOpts} /></div>
        </details>

        <section aria-labelledby="pr-h" className="space-y-3">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <IconHeading icon={IconClipboard} id="pr-h" className="text-xl font-extrabold">Daftar prospek</IconHeading>
            <form role="search" className="flex gap-2">
              {status !== 'semua' && <input type="hidden" name="status" value={status} />}
              <label htmlFor="pr-q" className="sr-only">Cari nama atau nomor</label>
              <input id="pr-q" name="q" defaultValue={q} className="input min-h-10 w-56 py-1.5" placeholder="Cari nama atau nomor" />
              <button className="btn btn-outline min-h-10 px-4"><IconSearch className="h-5 w-5" />Cari</button>
            </form>
          </div>
          <nav aria-label="Status prospek" className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
            {TABS.map((s) => {
              const n = s === 'semua' ? total : list.meta.counts[s] ?? 0;
              return (
                <Link key={s} href={s === 'semua' ? '/agen/prospek' : `/agen/prospek?status=${s}`} aria-current={s === status ? 'page' : undefined}
                  className={`chip shrink-0 ${s === status ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
                  {s === 'semua' ? 'Semua' : FOLLOW_UP[s]} <span className={`num rounded-full px-2 text-xs ${s === status ? 'bg-white/20' : 'bg-surface'}`}>{n}</span>
                </Link>
              );
            })}
          </nav>
          {list.data.length === 0 ? (
            <p className="card p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />{q || status !== 'semua' ? 'Tidak ada prospek yang cocok.' : 'Belum ada prospek. Catat calon jamaah pertama Anda di atas.'}</p>
          ) : (
            <ul className="space-y-3">
              {list.data.map((p) => {
                const overdue = p.next_follow_up_on && p.next_follow_up_on <= today && ['new', 'contacted', 'interested'].includes(p.follow_up_status);
                const wa = waLink(p.phone, `Assalamu'alaikum ${p.name}, saya ${ctx.user.name}, agen umrah${p.tenant ? ` mitra ${p.tenant.brand_name}` : ''}. ${p.package ? `Ingin melanjutkan info paket ${p.package.name}.` : ''}`);
                return (
                  <li key={p.id} className={`card p-4 ${overdue ? 'border-gold-500' : ''}`}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="text-lg font-bold">{p.name} <span className="num text-sm font-normal text-ink-600">{p.phone}</span></p>
                        <p className="text-sm text-ink-600">
                          {p.tenant ? p.tenant.brand_name : 'PPIU belum ditentukan'}{p.package && ` · ${p.package.name}`}
                          {p.next_follow_up_on && <> · <span className={overdue ? 'font-bold text-danger' : ''}>follow-up {tanggal(p.next_follow_up_on)}</span></>}
                        </p>
                        {p.notes && <p className="mt-1 whitespace-pre-line text-sm">{p.notes}</p>}
                      </div>
                      {wa && <WhatsAppButton href={wa} label="Chat WhatsApp" />}
                    </div>
                    <div className="mt-3"><ProspectRowActions prospect={p} tenants={tenantOpts} packages={pkgOpts} /></div>
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      </div>
    </>
  );
}

export default function ProspectsPage(props: PageProps<'/agen/prospek'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Prospects {...props} /></Suspense>;
}
