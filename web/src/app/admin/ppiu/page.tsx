import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { tanggal } from '@/lib/format';
import { requireContexts, serverApi, ServerApiError } from '@/lib/session';
import type { TenantFull } from '@/lib/tenant-types';

import { AdminHeader } from '../admin-nav';
import { IconInbox, IconSearch } from '@/components/icons';

export const metadata = { title: 'Verifikasi PPIU' };

type Row = TenantFull & { owner: { name: string; email: string | null; phone: string | null } | null };
type ListResp = { data: Row[]; meta: { next_cursor: string | null; counts: Record<string, number> } };

const TABS = [
  { s: 'pending_review', label: 'Menunggu tinjauan' },
  { s: 'needs_revision', label: 'Perlu revisi' },
  { s: 'draft', label: 'Draf' },
  { s: 'verified', label: 'Terverifikasi' },
  { s: 'suspended', label: 'Ditangguhkan' },
  { s: 'rejected', label: 'Ditolak' },
];

async function Queue({ searchParams }: PageProps<'/admin/ppiu'>) {
  const sp = await searchParams;
  const ctx = await requireContexts('/admin/ppiu');
  if (!ctx.platform_role) notFound();
  const status = typeof sp.status === 'string' && TABS.some((t) => t.s === sp.status) ? sp.status : 'pending_review';
  const q = typeof sp.q === 'string' ? sp.q.slice(0, 100) : '';
  const qs = new URLSearchParams({ 'filter[verification_status]': status, limit: '50', ...(q ? { q } : {}) });

  let res: ListResp;
  try {
    res = await serverApi<ListResp>(`/platform/tenants?${qs}`);
  } catch (e) {
    if (e instanceof ServerApiError && e.code === 'two_factor_required') {
      return <Alert tone="warning" title="Aktifkan 2FA">Staf platform wajib memakai 2FA. <Link href="/akun#keamanan" className="font-bold text-primary-700 underline">Atur di halaman Akun</Link></Alert>;
    }
    throw e;
  }

  return (
    <>
      <nav aria-label="Status PPIU" className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
        {TABS.map((t) => (
          <Link key={t.s} href={`/admin/ppiu?status=${t.s}`} aria-current={t.s === status ? 'page' : undefined}
            className={`chip shrink-0 ${t.s === status ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
            {t.label} <span className={`num rounded-full px-2 text-xs ${t.s === status ? 'bg-white/20' : 'bg-surface'}`}>{res.meta.counts[t.s] ?? 0}</span>
          </Link>
        ))}
      </nav>
      <form className="mt-4 flex gap-2" role="search">
        <input type="hidden" name="status" value={status} />
        <label htmlFor="q" className="sr-only">Cari PPIU</label>
        <input id="q" name="q" defaultValue={q} className="input max-w-md" placeholder="Cari nama, badan usaha, atau nomor izin" />
        <button className="btn btn-outline"><IconSearch className="h-5 w-5" />Cari</button>
      </form>
      <div className="card mt-4 overflow-hidden">
        {res.data.length === 0 ? (
          <p className="p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />Tidak ada PPIU dengan status ini.</p>
        ) : (
          <table className="w-full text-left">
            <thead className="bg-surface text-sm text-ink-600">
              <tr><th className="px-4 py-3">PPIU</th><th className="hidden px-4 py-3 md:table-cell">Owner</th><th className="hidden px-4 py-3 sm:table-cell">Izin berlaku s.d.</th><th className="px-4 py-3">Status</th></tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {res.data.map((t) => (
                <tr key={t.id} className="hover:bg-primary-50">
                  <td className="px-4 py-3">
                    <Link href={`/admin/ppiu/${t.id}`} className="font-bold text-primary-700 hover:underline">{t.brand_name}</Link>
                    <p className="text-sm text-ink-600">{t.legal_name} · {t.ppiu_license_no}</p>
                  </td>
                  <td className="hidden px-4 py-3 md:table-cell">{t.owner?.name}<p className="text-sm text-ink-600">{t.owner?.email ?? t.owner?.phone}</p></td>
                  <td className="num hidden px-4 py-3 sm:table-cell">{t.license_expires_on ? tanggal(t.license_expires_on) : '—'}</td>
                  <td className="px-4 py-3"><StatusPill status={t.verification_status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}

export default function AdminTenantsPage(props: PageProps<'/admin/ppiu'>) {
  return (
    <>
      <AdminHeader active="ppiu" title="Verifikasi PPIU" sub="Hanya PPIU terverifikasi yang tampil di marketplace (BR-01)." />
      <div className="px-4 sm:px-6 lg:px-8 py-6">
        <Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><Queue {...props} /></Suspense>
      </div>
    </>
  );
}
