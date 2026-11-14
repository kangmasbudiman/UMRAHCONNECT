import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { requireContexts, serverApi } from '@/lib/session';
import { AdminHeader } from '../admin-nav';
import { ConfirmAction, ReasonAction } from '../platform-actions';

export const metadata = { title: 'Pengguna' };

type Row = { id: string; name: string; email: string | null; phone: string | null; status: string; platform_role: string | null; two_factor_enabled: boolean;
  last_login_at: string | null; created_at: string | null; agent: { code: string; status: string } | null;
  memberships: { tenant_id: string; brand_name: string | null; role: string; status: string }[] };
const ROLE: Record<string, string> = { owner: 'Owner', admin: 'Admin', finance: 'Finance', super_admin: 'Super Admin', platform_admin: 'Admin Platform' };
const wib = (iso: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso));

/** ADM-07 Kelola akun (FR-ADM-07): cari, nonaktifkan, aktifkan kembali. Admin Platform hanya akun agen dan jamaah. */
async function Users({ searchParams }: PageProps<'/admin/pengguna'>) {
  const sp = await searchParams;
  const ctx = await requireContexts('/admin/pengguna');
  if (!ctx.platform_role) notFound();
  const isSuper = ctx.platform_role === 'super_admin';
  const q = typeof sp.q === 'string' ? sp.q.slice(0, 100) : '';
  const status = sp.status === 'deactivated' ? 'deactivated' : '';
  const res = await serverApi<{ data: Row[]; meta: { next_cursor: string | null } }>(`/platform/users?${new URLSearchParams({ limit: '30', ...(q ? { q } : {}), ...(status ? { 'filter[status]': status } : {}) })}`);
  return (
    <>
      <form className="flex flex-wrap gap-2" role="search">
        <label htmlFor="u-q" className="sr-only">Cari akun</label>
        <input id="u-q" name="q" defaultValue={q} className="input max-w-md" placeholder="Nama, email, atau nomor HP" />
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="status" value="deactivated" defaultChecked={!!status} className="h-5 w-5" /> Hanya yang nonaktif</label>
        <button className="btn btn-outline">Cari</button>
      </form>
      {!isSuper && <p className="text-sm text-ink-600">Anda bisa menonaktifkan akun agen dan jamaah. Akun staf PPIU dan staf platform hanya bisa diubah Super Admin.</p>}
      {res.data.length === 0 ? <p className="card p-8 text-center text-ink-600">Akun tidak ditemukan.</p> : (
        <ul className="space-y-3">
          {res.data.map((u) => {
            const staff = !!u.platform_role || u.memberships.length > 0;
            const self = u.id === ctx.user.id;
            const manageable = !self && u.platform_role !== 'super_admin' && (isSuper || !staff);
            return (
              <li key={u.id} className="card p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-bold">{u.name}{self && <span className="font-normal text-ink-600"> (Anda)</span>} <StatusPill status={u.status} /></p>
                    <p className="text-sm text-ink-600">{[u.email, u.phone].filter(Boolean).join(' · ')}</p>
                    <p className="mt-1 flex flex-wrap gap-1.5 text-xs">
                      {u.platform_role && <span className="chip min-h-0 py-0.5">{ROLE[u.platform_role]}</span>}
                      {u.agent && <span className="chip min-h-0 py-0.5">Agen {u.agent.code}</span>}
                      {u.memberships.map((m) => <span key={m.tenant_id} className="chip min-h-0 py-0.5">{ROLE[m.role]} · {m.brand_name}</span>)}
                      {!staff && !u.agent && <span className="chip min-h-0 py-0.5">Jamaah</span>}
                    </p>
                    <p className="mt-1 text-xs text-ink-600">{u.last_login_at ? `Terakhir masuk ${wib(u.last_login_at)}` : 'Belum pernah masuk'}{u.two_factor_enabled && ' · 2FA aktif'}</p>
                  </div>
                </div>
                {manageable && (
                  <div className="mt-3">
                    {u.status === 'deactivated' || u.status === 'locked'
                      ? <ConfirmAction label={u.status === 'locked' ? 'Buka kunci' : 'Aktifkan kembali'} tone="primary" path={`/platform/users/${u.id}/reactivate`} confirmText={`Aktifkan kembali akun ${u.name}?`} />
                      : <ReasonAction label="Nonaktifkan akun" path={`/platform/users/${u.id}/deactivate`} hint="Sesi aktif langsung dicabut. Pemilik akun diberi tahu lewat email."
                          confirmText={`Nonaktifkan akun ${u.name}? Ia tidak bisa masuk sampai diaktifkan kembali.`} />}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
      {res.meta.next_cursor && <Link href={`/admin/pengguna?${new URLSearchParams({ ...(q ? { q } : {}), ...(status ? { status } : {}), cursor: res.meta.next_cursor })}`} className="btn btn-outline">Muat berikutnya</Link>}
    </>
  );
}

export default function UsersPage(props: PageProps<'/admin/pengguna'>) {
  return (
    <>
      <AdminHeader active="pengguna" title="Pengguna" sub="Cari akun, nonaktifkan akun bermasalah, atau aktifkan kembali." />
      <div className="mx-auto max-w-6xl space-y-4 px-4 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><Users {...props} /></Suspense></div>
    </>
  );
}
