import Link from 'next/link';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { Cover } from '@/components/ui';
import { rupiah, tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';
import type { PackageManaged } from '@/lib/tenant-types';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';

export const metadata = { title: 'Paket PPIU' };

const TABS = [
  { s: '', label: 'Semua' }, { s: 'published', label: 'Terbit' }, { s: 'draft', label: 'Draf' },
  { s: 'archived', label: 'Arsip' }, { s: 'taken_down', label: 'Diturunkan' },
];

async function List({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/paket'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/paket`, 'package.view');
  if (!isTenantPage(page)) return page;
  const status = typeof sp.status === 'string' && TABS.some((t) => t.s === sp.status) ? sp.status : '';
  const res = await serverApi<{ data: PackageManaged[]; meta: { counts: Record<string, number> } }>(
    `/tenants/${id}/packages?limit=100${status ? `&filter[status]=${status}` : ''}`);
  const total = Object.values(res.meta.counts).reduce((a, b) => a + b, 0);
  const canManage = page.membership.permissions.includes('package.manage');

  return (
    <>
      <TenantHeader page={page} active="paket" sub="Kelola paket, foto, keberangkatan, dan harga resmi." />
      <div className="mx-auto max-w-6xl space-y-5 px-4 py-6">
        {page.tenant.verification_status !== 'verified' && (
          <Alert tone="info" title="Paket bisa disiapkan sekarang">Anda bisa membuat draf paket. Paket baru bisa diterbitkan ke marketplace setelah PPIU terverifikasi.</Alert>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <nav aria-label="Status paket" className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
            {TABS.map((t) => {
              const n = t.s ? (res.meta.counts[t.s] ?? 0) : total;
              const on = t.s === status;
              return (
                <Link key={t.s || 'all'} href={`/dasbor/ppiu/${id}/paket${t.s ? `?status=${t.s}` : ''}`} aria-current={on ? 'page' : undefined}
                  className={`chip shrink-0 ${on ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
                  {t.label} <span className={`num rounded-full px-2 text-xs ${on ? 'bg-white/20' : 'bg-surface'}`}>{n}</span>
                </Link>
              );
            })}
          </nav>
          {canManage && <Link href={`/dasbor/ppiu/${id}/paket/baru`} className="btn btn-gold">+ Buat paket</Link>}
        </div>

        {res.data.length === 0 ? (
          <div className="card p-10 text-center">
            <p className="text-lg font-bold">{total === 0 ? 'Belum ada paket' : 'Tidak ada paket dengan status ini'}</p>
            {total === 0 && <p className="mt-1 text-ink-600">Mulai dengan membuat paket pertama Anda: nama, durasi, dan maskapai. Detail lain bisa dilengkapi kemudian.</p>}
            {total === 0 && canManage && <Link href={`/dasbor/ppiu/${id}/paket/baru`} className="btn btn-primary mt-4">Buat paket pertama</Link>}
          </div>
        ) : (
          <ul className="space-y-3">
            {res.data.map((p) => (
              <li key={p.id}>
                <Link href={`/dasbor/ppiu/${id}/paket/${p.id}`} className="card group grid overflow-hidden transition hover:border-primary-600 sm:grid-cols-[160px_1fr_auto]">
                  <Cover url={p.images[0]?.url} title={p.name} className="h-28 sm:h-full sm:min-h-28" />
                  <div className="min-w-0 p-4">
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="text-lg font-bold group-hover:text-primary-700">{p.name}</h2>
                      <StatusPill status={p.status} />
                    </div>
                    <p className="mt-1 text-sm text-ink-600">{p.duration_days} hari · {p.airline} · {p.images.length} foto</p>
                    <p className="mt-1 text-sm text-ink-600">
                      {p.departures_count > 0
                        ? <>{p.departures_count} keberangkatan mendatang{p.next_departure_date && <> · terdekat {tanggal(p.next_departure_date)}</>}</>
                        : 'Belum ada keberangkatan mendatang'}
                    </p>
                  </div>
                  <div className="flex items-center justify-between gap-4 border-t border-border-subtle p-4 sm:flex-col sm:items-end sm:justify-center sm:border-l sm:border-t-0">
                    <div className="sm:text-right">
                      <p className="text-xs text-ink-600">Mulai dari</p>
                      <p className="num font-extrabold text-primary-800">{p.price_from_amount ? rupiah(p.price_from_amount) : '—'}</p>
                    </div>
                    <span className="font-bold text-primary-700">Kelola →</span>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}

export default function PackagesPage(props: PageProps<'/dasbor/ppiu/[tenant]/paket'>) {
  return (
    <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}>
      <List {...props} />
    </Suspense>
  );
}
