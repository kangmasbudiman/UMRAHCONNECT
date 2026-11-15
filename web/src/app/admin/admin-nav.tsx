import Link from 'next/link';
import { Suspense } from 'react';
import { getContexts } from '@/lib/session';
import { IconDashboard, IconLog, IconPackageX, IconSettings, IconShieldCheck, IconStar, IconTeam, IconTrend, IconUserCog } from '@/components/icons';

export type AdminTab = 'dasbor' | 'ppiu' | 'paket' | 'ulasan' | 'pengguna' | 'audit' | 'staf' | 'pengaturan' | 'pendapatan';

const BASE: { key: AdminTab; href: string; label: string; Icon: typeof IconDashboard }[] = [
  { key: 'dasbor', href: '/admin', label: 'Dasbor', Icon: IconDashboard },
  { key: 'ppiu', href: '/admin/ppiu', label: 'Verifikasi PPIU', Icon: IconShieldCheck },
  { key: 'paket', href: '/admin/paket', label: 'Moderasi paket', Icon: IconPackageX },
  { key: 'ulasan', href: '/admin/ulasan', label: 'Ulasan', Icon: IconStar },
  { key: 'pengguna', href: '/admin/pengguna', label: 'Pengguna', Icon: IconTeam },
  { key: 'audit', href: '/admin/audit', label: 'Audit log', Icon: IconLog },
];
const SUPER: typeof BASE = [
  { key: 'pendapatan', href: '/admin/pendapatan', label: 'Pendapatan', Icon: IconTrend },
  { key: 'staf', href: '/admin/staf', label: 'Staf platform', Icon: IconUserCog },
  { key: 'pengaturan', href: '/admin/pengaturan', label: 'Pengaturan', Icon: IconSettings },
];

/** Navigasi area platform. Tab Staf dan Pengaturan hanya untuk Super Admin. */
export function AdminNav({ active, superAdmin = false }: { active: AdminTab; superAdmin?: boolean }) {
  const items = superAdmin ? [...BASE, ...SUPER] : BASE;
  return (
    <nav aria-label="Menu platform" className="no-scrollbar mt-5 -mb-px flex gap-1 overflow-x-auto">
      {items.map((i) => (
        <Link key={i.key} href={i.href} aria-current={i.key === active ? 'page' : undefined}
          className={`inline-flex min-h-12 shrink-0 items-center gap-2 rounded-t-[10px] px-4 font-semibold ${i.key === active ? 'bg-surface text-primary-800' : 'text-white/85 hover:bg-white/10'}`}>
          <i.Icon className={`h-5 w-5 ${i.key === active ? 'text-gold-700' : 'text-gold-500'}`} />{i.label}
        </Link>
      ))}
    </nav>
  );
}

async function RoleNav({ active }: { active: AdminTab }) {
  const ctx = await getContexts();
  return <AdminNav active={active} superAdmin={ctx?.platform_role === 'super_admin'} />;
}

export function AdminHeader({ active, title, sub }: { active: AdminTab; title: string; sub?: string }) {
  return (
    <section className="bg-hero text-white">
      <div className="mx-auto max-w-6xl px-4 pt-8">
        <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Platform</p>
        <h1 className="mt-1 text-3xl font-extrabold">{title}</h1>
        {sub && <p className="mt-1 text-white/85">{sub}</p>}
        <Suspense fallback={<AdminNav active={active} />}><RoleNav active={active} /></Suspense>
      </div>
    </section>
  );
}
