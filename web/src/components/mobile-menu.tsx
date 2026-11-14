'use client';

import Link from 'next/link';
import { useState } from 'react';
import { IconCalendarDays, IconMenu, IconOffice, IconPackage, IconPiggy, IconRoute, IconUser, IconUserPlus, IconX } from './icons';

const LINKS = [
  { href: '/paket', label: 'Paket Umrah', Icon: IconPackage },
  { href: '/jadwal', label: 'Jadwal', Icon: IconCalendarDays },
  { href: '/ppiu', label: 'PPIU', Icon: IconOffice },
  { href: '/lacak', label: 'Lacak Umrah', Icon: IconRoute },
  { href: '/tabungan', label: 'Rencana tabungan', Icon: IconPiggy },
  { href: '/daftar?peran=ppiu', label: 'Daftarkan PPIU', Icon: IconOffice },
] as const;

/** Menu ponsel: tombol 48px, panel di bawah header, tertutup setelah tautan dipilih. */
export function MobileMenu({ signedIn }: { signedIn: boolean }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="md:hidden">
      <button type="button" aria-expanded={open} aria-controls="menu-ponsel" onClick={() => setOpen((v) => !v)}
        className="inline-flex h-12 w-12 items-center justify-center rounded-lg text-primary-800 hover:bg-primary-50">
        {open ? <IconX className="h-6 w-6" /> : <IconMenu className="h-6 w-6" />}
        <span className="sr-only">{open ? 'Tutup menu' : 'Buka menu'}</span>
      </button>
      {open && (
        <nav id="menu-ponsel" aria-label="Menu ponsel" className="absolute inset-x-0 top-full border-b border-border-subtle bg-white px-4 pb-4 shadow-card">
          <ul className="divide-y divide-border-subtle">
            {LINKS.map((l) => (
              <li key={l.href}>
                <Link href={l.href} onClick={() => setOpen(false)} className="flex min-h-12 items-center gap-3 font-semibold"><l.Icon className="h-5 w-5 text-primary-600" />{l.label}</Link>
              </li>
            ))}
          </ul>
          {signedIn
            ? <Link href="/akun" onClick={() => setOpen(false)} className="btn btn-primary mt-3 w-full"><IconUser className="h-5 w-5" />Akun saya</Link>
            : <Link href="/daftar" onClick={() => setOpen(false)} className="btn btn-primary mt-3 w-full"><IconUserPlus className="h-5 w-5" />Daftar</Link>}
        </nav>
      )}
    </div>
  );
}
