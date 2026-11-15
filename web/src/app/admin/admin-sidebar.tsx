'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useState, type ReactNode } from 'react';
import { LogoutButton } from '@/app/akun/account-actions';
import {
  IconDashboard, IconExternal, IconLog, IconMenu, IconPackageX, IconSettings, IconShieldCheck, IconStar, IconTeam, IconTrend, IconUser, IconUserCog, IconX,
} from '@/components/icons';

type Item = { href: string; label: string; Icon: typeof IconDashboard };

const OPERASIONAL: Item[] = [
  { href: '/admin', label: 'Dasbor', Icon: IconDashboard },
  { href: '/admin/ppiu', label: 'Verifikasi PPIU', Icon: IconShieldCheck },
  { href: '/admin/paket', label: 'Moderasi paket', Icon: IconPackageX },
  { href: '/admin/ulasan', label: 'Ulasan', Icon: IconStar },
  { href: '/admin/pengguna', label: 'Pengguna', Icon: IconTeam },
  { href: '/admin/audit', label: 'Audit log', Icon: IconLog },
];
/** Hanya Super Admin (API juga menolak peran lain). */
const SUPER: Item[] = [
  { href: '/admin/pendapatan', label: 'Pendapatan', Icon: IconTrend },
  { href: '/admin/staf', label: 'Staf platform', Icon: IconUserCog },
  { href: '/admin/pengaturan', label: 'Pengaturan', Icon: IconSettings },
];

export type SidebarUser = { name: string; superAdmin: boolean } | null;

function NavGroup({ title, items, pathname, onNavigate }: { title: string; items: Item[]; pathname: string; onNavigate: () => void }) {
  return (
    <div>
      <p className="px-3 text-[11px] font-bold uppercase tracking-[0.14em] text-gold-500/90">{title}</p>
      <ul className="mt-2 space-y-0.5">
        {items.map((i) => {
          const active = i.href === '/admin' ? pathname === '/admin' : pathname === i.href || pathname.startsWith(`${i.href}/`);
          return (
            <li key={i.href}>
              <Link
                href={i.href}
                onClick={onNavigate}
                aria-current={active ? 'page' : undefined}
                className={`relative flex min-h-11 items-center gap-3 rounded-[10px] px-3 font-semibold transition ${active ? 'bg-white/12 text-white' : 'text-white/75 hover:bg-white/6 hover:text-white'}`}
              >
                {active && <span aria-hidden="true" className="absolute inset-y-2 left-0 w-1 rounded-r-full bg-gold-500" />}
                <i.Icon className={`h-5 w-5 ${active ? 'text-gold-500' : 'text-gold-500/70'}`} />
                {i.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function Panel({ brand, user, pathname, onNavigate }: { brand: ReactNode; user: SidebarUser; pathname: string; onNavigate: () => void }) {
  return (
    <div className="flex h-full flex-col">
      <div className="flex h-16 shrink-0 items-center px-5">{brand}</div>
      <nav aria-label="Menu platform" className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
        <NavGroup title="Operasional" items={OPERASIONAL} pathname={pathname} onNavigate={onNavigate} />
        {user?.superAdmin && <NavGroup title="Super Admin" items={SUPER} pathname={pathname} onNavigate={onNavigate} />}
      </nav>
      <div className="shrink-0 border-t border-white/10 p-3">
        {user && (
          <div className="flex items-center gap-3 px-2 py-2">
            <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gold-500 font-bold text-primary-900">{user.name.trim()[0]?.toUpperCase()}</span>
            <div className="min-w-0">
              <p className="truncate font-bold text-white">{user.name}</p>
              <p className="text-xs text-white/65">{user.superAdmin ? 'Super Admin' : 'Admin Platform'}</p>
            </div>
          </div>
        )}
        <div className="mt-1 grid gap-0.5">
          <Link href="/" className="flex min-h-10 items-center gap-3 rounded-[10px] px-3 text-sm font-semibold text-white/75 hover:bg-white/6 hover:text-white">
            <IconExternal className="h-[18px] w-[18px] text-gold-500/70" />Lihat situs
          </Link>
          <Link href="/akun" className="flex min-h-10 items-center gap-3 rounded-[10px] px-3 text-sm font-semibold text-white/75 hover:bg-white/6 hover:text-white">
            <IconUser className="h-[18px] w-[18px] text-gold-500/70" />Akun saya
          </Link>
          <LogoutButton className="flex min-h-10 w-full items-center gap-3 rounded-[10px] px-3 text-left text-sm font-semibold text-white/75 hover:bg-white/6 hover:text-white [&_svg]:h-[18px] [&_svg]:w-[18px] [&_svg]:text-gold-500/70" />
        </div>
      </div>
    </div>
  );
}

/** Sidebar panel platform: tetap di kiri pada layar lebar, laci (drawer) di ponsel. */
export function AdminSidebar({ brand, user }: { brand: ReactNode; user: SidebarUser }) {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const close = () => setOpen(false);
  return (
    <>
      <aside className="sticky top-0 hidden h-screen w-[264px] shrink-0 bg-primary-900 lg:block">
        <Panel brand={brand} user={user} pathname={pathname} onNavigate={close} />
      </aside>

      <div className="sticky top-0 z-40 flex h-14 items-center justify-between bg-primary-900 px-4 lg:hidden">
        {brand}
        <button type="button" onClick={() => setOpen(true)} aria-label="Buka menu platform" aria-expanded={open} className="flex h-11 w-11 items-center justify-center rounded-lg text-white hover:bg-white/10">
          <IconMenu className="h-6 w-6" />
        </button>
      </div>
      {open && (
        <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu platform">
          <button type="button" aria-label="Tutup menu" onClick={close} className="absolute inset-0 bg-ink-900/50" />
          <div className="absolute inset-y-0 left-0 w-[280px] max-w-[85vw] bg-primary-900 shadow-raised">
            <button type="button" onClick={close} aria-label="Tutup menu" className="absolute right-2 top-2.5 flex h-11 w-11 items-center justify-center rounded-lg text-white hover:bg-white/10">
              <IconX className="h-6 w-6" />
            </button>
            <Panel brand={brand} user={user} pathname={pathname} onNavigate={close} />
          </div>
        </div>
      )}
    </>
  );
}
