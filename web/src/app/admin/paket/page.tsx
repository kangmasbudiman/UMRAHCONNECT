import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { requireContexts, serverApi } from '@/lib/session';
import { AdminHeader } from '../admin-nav';
import { ReasonAction } from '../platform-actions';
import { IconInbox, IconSearch } from '@/components/icons';

export const metadata = { title: 'Moderasi paket' };

type Row = { id: string; name: string; slug: string; status: string; taken_down_reason: string | null; published_at: string | null; updated_at: string | null;
  tenant: { id: string; slug: string; brand_name: string; verification_status: string } };
const TABS = [{ s: 'published', label: 'Terbit' }, { s: 'taken_down', label: 'Diturunkan' }];

/** ADM-06 Moderasi paket (FR-ADM-06): turunkan paket yang melanggar; izinkan terbit lagi setelah diperbaiki. */
async function Moderation({ searchParams }: PageProps<'/admin/paket'>) {
  const sp = await searchParams;
  const ctx = await requireContexts('/admin/paket');
  if (!ctx.platform_role) notFound();
  const status = typeof sp.status === 'string' && TABS.some((t) => t.s === sp.status) ? sp.status : 'published';
  const q = typeof sp.q === 'string' ? sp.q.slice(0, 100) : '';
  const res = await serverApi<{ data: Row[]; meta: { counts: Record<string, number> } }>(`/platform/packages?${new URLSearchParams({ 'filter[status]': status, limit: '50', ...(q ? { q } : {}) })}`);
  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <nav aria-label="Status paket" className="flex gap-2">
          {TABS.map((t) => (
            <Link key={t.s} href={`/admin/paket?status=${t.s}`} aria-current={t.s === status ? 'page' : undefined}
              className={`chip ${t.s === status ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
              {t.label} <span className={`num rounded-full px-2 text-xs ${t.s === status ? 'bg-white/20' : 'bg-surface'}`}>{res.meta.counts[t.s] ?? 0}</span>
            </Link>
          ))}
        </nav>
        <form className="flex gap-2" role="search">
          <input type="hidden" name="status" value={status} />
          <label htmlFor="pk-q" className="sr-only">Cari paket</label>
          <input id="pk-q" name="q" defaultValue={q} className="input max-w-xs" placeholder="Cari nama paket" />
          <button className="btn btn-outline"><IconSearch className="h-5 w-5" />Cari</button>
        </form>
      </div>
      {res.data.length === 0 ? <p className="card p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />Tidak ada paket.</p> : (
        <ul className="space-y-3">
          {res.data.map((p) => (
            <li key={p.id} className="card p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-bold">{p.name}</p>
                  <p className="text-sm text-ink-600">{p.tenant.brand_name} · <StatusPill status={p.status} /></p>
                  {p.status === 'published' && p.tenant.verification_status !== 'verified' && (
                    <p className="mt-1 text-sm text-ink-600">Tidak tampil di marketplace: PPIU {p.tenant.verification_status === 'suspended' ? 'sedang ditangguhkan' : 'belum terverifikasi'}.</p>
                  )}
                  {p.taken_down_reason && p.status === 'taken_down' && <p className="mt-1 text-sm"><span className="font-semibold">Alasan:</span> {p.taken_down_reason}</p>}
                </div>
                {p.status === 'published' && p.tenant.verification_status === 'verified' && <Link href={`/paket/${p.tenant.slug}/${p.slug}`} target="_blank" className="font-semibold text-primary-700 underline">Lihat di marketplace</Link>}
              </div>
              <div className="mt-3">
                {p.status === 'published' && <ReasonAction label="Turunkan paket" path={`/platform/packages/${p.id}/take-down`} minLength={10}
                  hint="Dikirim ke Owner dan Admin PPIU. Booking yang sudah ada tetap berjalan." confirmText={`Turunkan "${p.name}" dari marketplace?`} />}
                {p.status === 'taken_down' && <ReasonAction label="Izinkan terbit lagi" tone="primary" path={`/platform/packages/${p.id}/restore`}
                  hint="Paket kembali ke arsip; PPIU memeriksa lalu menerbitkannya sendiri." confirmText={`Izinkan "${p.name}" diterbitkan lagi?`} />}
              </div>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

export default function ModerationPage(props: PageProps<'/admin/paket'>) {
  return (
    <>
      <AdminHeader active="paket" title="Moderasi paket" sub="Turunkan paket yang menyesatkan atau melanggar aturan. PPIU diberi tahu beserta alasannya." />
      <div className="space-y-4 px-4 sm:px-6 lg:px-8 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><Moderation {...props} /></Suspense></div>
    </>
  );
}
