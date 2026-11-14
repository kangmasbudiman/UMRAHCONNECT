import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { requireContexts, serverApi } from '@/lib/session';
import { AdminHeader } from '../admin-nav';
import { AddStaffForm, ConfirmAction } from '../platform-actions';
import { IconHeading } from '@/components/stat-card';
import { IconTeam, IconUserPlus } from '@/components/icons';

export const metadata = { title: 'Staf platform' };

type Staff = { id: string; name: string; email: string | null; platform_role: 'super_admin' | 'platform_admin'; two_factor_enabled: boolean; last_login_at: string | null };

/** Super Admin mengelola Admin Platform. Super Admin baru hanya lewat perintah server (platform:create-admin). */
async function StaffList() {
  const ctx = await requireContexts('/admin/staf');
  if (ctx.platform_role !== 'super_admin') notFound();
  const rows = (await serverApi<{ data: Staff[] }>('/platform/admins')).data;
  return (
    <>
      <section className="card overflow-hidden" aria-labelledby="st-h">
        <IconHeading icon={IconTeam} id="st-h" className="border-b border-border-subtle p-4 text-lg font-extrabold">Staf saat ini</IconHeading>
        <ul className="divide-y divide-border-subtle">
          {rows.map((u) => (
            <li key={u.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
              <div>
                <p className="font-bold">{u.name}{u.id === ctx.user.id && <span className="font-normal text-ink-600"> (Anda)</span>}</p>
                <p className="text-sm text-ink-600">{u.email} · {u.platform_role === 'super_admin' ? 'Super Admin' : 'Admin Platform'} · {u.two_factor_enabled ? '2FA aktif' : <span className="font-semibold text-danger">2FA belum aktif</span>}</p>
              </div>
              {u.platform_role === 'platform_admin' && (
                <ConfirmAction label="Cabut peran" tone="danger" method="DELETE" path={`/platform/admins/${u.id}`}
                  confirmText={`Cabut peran Admin Platform dari ${u.name}? Sesinya langsung berakhir; akunnya tetap ada sebagai pengguna biasa.`} />
              )}
            </li>
          ))}
        </ul>
      </section>
      <section className="card space-y-3 p-5" aria-labelledby="add-h">
        <IconHeading icon={IconUserPlus} id="add-h" className="text-lg font-extrabold">Tambah Admin Platform</IconHeading>
        <p className="text-sm text-ink-600">Akun harus sudah terdaftar dengan email terverifikasi. Admin Platform wajib mengaktifkan 2FA sebelum bisa membuka area platform. Super Admin baru hanya bisa dibuat dari server.</p>
        <AddStaffForm />
      </section>
    </>
  );
}

export default function StaffPage() {
  return (
    <>
      <AdminHeader active="staf" title="Staf platform" sub="Atur siapa saja yang menjadi Admin Platform UMRAH CONNECT." />
      <div className="mx-auto max-w-4xl space-y-4 px-4 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><StaffList /></Suspense></div>
    </>
  );
}
