import Link from 'next/link';
import { Suspense } from 'react';
import { getContexts, serverApi } from '@/lib/session';
import { MobileMenu } from './mobile-menu';

/** Tombol kanan header: tamu → Masuk/Daftar; sudah masuk → Akun. Dibaca per permintaan (cookie sesi). */
async function SignedInActions() {
  const ctx = await getContexts().catch(() => null);
  const unread = ctx ? await serverApi<{ meta: { unread: number } }>('/me/notifications?unread=1&limit=1').then((r) => r.meta.unread).catch(() => 0) : 0;
  return <HeaderActions user={ctx ? { name: ctx.user.name } : null} unread={unread} />;
}

function HeaderActions({ user, unread = 0 }: { user: { name: string } | null; unread?: number }) {
  if (user) {
    return (
      <>
        <Link href="/akun/notifikasi" className="relative inline-flex h-12 w-12 items-center justify-center rounded-lg text-primary-800 hover:bg-primary-50"
          aria-label={unread > 0 ? `Notifikasi, ${unread} belum dibaca` : 'Notifikasi'}>
          <svg aria-hidden="true" viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" /></svg>
          {unread > 0 && <span className="num absolute right-1.5 top-1.5 min-w-5 rounded-full bg-gold-500 px-1 text-center text-xs font-bold text-ink-900">{unread > 9 ? '9+' : unread}</span>}
        </Link>
        <Link href="/akun" className="btn btn-outline px-3 sm:px-4">
          <span aria-hidden="true" className="flex h-7 w-7 items-center justify-center rounded-full bg-primary-700 text-sm font-bold text-white">{user.name.trim()[0]?.toUpperCase()}</span>
          <span className="max-w-32 truncate">Akun</span>
        </Link>
        <MobileMenu signedIn />
      </>
    );
  }
  return (
    <>
      <Link href="/daftar?peran=ppiu" className="hidden min-h-12 items-center rounded-lg px-3 font-semibold text-gold-700 hover:bg-gold-50 lg:inline-flex">
        Daftarkan PPIU
      </Link>
      <Link href="/masuk" className="btn btn-outline px-3 sm:px-4">Masuk</Link>
      <Link href="/daftar" className="btn btn-primary hidden px-4 md:inline-flex">Daftar</Link>
      <MobileMenu signedIn={false} />
    </>
  );
}

export function Logo({ onDark = false }: { onDark?: boolean }) {
  return (
    <Link href="/" className="inline-flex items-center gap-2" aria-label="UMRAH CONNECT, ke beranda">
      <span aria-hidden="true" className="relative flex h-9 w-9 items-center justify-center rounded-lg bg-primary-700">
        <svg viewBox="0 0 24 24" className="h-6 w-6">
          <g fill="none" stroke="#C8A24A" strokeWidth="1.6">
            <rect x="7" y="7" width="10" height="10" />
            <rect x="7" y="7" width="10" height="10" transform="rotate(45 12 12)" />
          </g>
        </svg>
      </span>
      <span className={`whitespace-nowrap text-base font-bold leading-none tracking-tight sm:text-lg ${onDark ? 'text-white' : 'text-primary-800'}`}>
        UMRAH <span className={onDark ? 'text-gold-500' : 'text-gold-700'}>CONNECT</span>
      </span>
    </Link>
  );
}

const NAV = [
  { href: '/paket', label: 'Paket Umrah' },
  { href: '/jadwal', label: 'Jadwal' },
  { href: '/ppiu', label: 'PPIU' },
] as const;

export function SiteHeader() {
  return (
    <header className="sticky top-0 z-40 border-b border-border-subtle bg-white/95 shadow-[0_1px_8px_rgb(16_24_40/0.06)] backdrop-blur">
      <div className="relative mx-auto flex h-16 max-w-6xl items-center gap-2 px-4">
        <Logo />
        <nav aria-label="Menu utama" className="ml-6 hidden items-center gap-1 md:flex">
          {NAV.map((n) => (
            <Link key={n.href} href={n.href} className="inline-flex min-h-12 items-center rounded-lg px-3 font-semibold text-ink-900 hover:bg-primary-50 hover:text-primary-700">
              {n.label}
            </Link>
          ))}
        </nav>
        <div className="ml-auto flex items-center gap-2">
          <Suspense fallback={<HeaderActions user={null} />}>
            <SignedInActions />
          </Suspense>
        </div>
      </div>
    </header>
  );
}

export function SiteFooter() {
  return (
    <footer className="mt-16 bg-primary-900 text-white">
      <div className="h-1 bg-gradient-to-r from-gold-500 via-gold-100 to-gold-500" aria-hidden="true" />
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
        <div>
          <Logo onDark />
          <p className="mt-4 max-w-sm text-white/80">
            Marketplace umrah yang mempertemukan jamaah, agen, dan PPIU berizin. Pilot di Kota Jambi.
          </p>
        </div>
        <FooterCol title="Tentang" links={[['Cara kerja', '/#cara-kerja'], ['Direktori PPIU', '/ppiu']]} />
        <FooterCol title="Layanan" links={[['Cari paket umrah', '/paket'], ['Jadwal keberangkatan', '/jadwal']]} />
        <FooterCol title="Untuk Mitra" links={[['Daftarkan PPIU', '/daftar?peran=ppiu'], ['Jadi agen', '/daftar?peran=agen'], ['Masuk', '/masuk']]} />
      </div>
      <div className="border-t border-white/15">
        <div className="mx-auto grid max-w-6xl gap-3 px-4 py-6 text-sm text-white/80 md:grid-cols-2">
          <p>
            UMRAH CONNECT adalah platform teknologi dan pemasaran. Penyelenggara perjalanan adalah PPIU berizin yang tercantum di setiap paket,
            dan pembayaran selalu ditransfer ke rekening resmi PPIU.
          </p>
          <p className="md:text-right">
            Versi development — semua PPIU, paket, dan nomor izin di situs ini adalah <strong className="text-gold-500">data contoh</strong>.
          </p>
        </div>
      </div>
    </footer>
  );
}

function FooterCol({ title, links }: { title: string; links: [string, string][] }) {
  return (
    <div>
      <h2 className="text-sm font-bold uppercase tracking-wider text-gold-500">{title}</h2>
      <ul className="mt-3">
        {links.map(([label, href]) => (
          <li key={href}><Link href={href} className="inline-flex min-h-10 items-center text-white/90 hover:text-white hover:underline">{label}</Link></li>
        ))}
      </ul>
    </div>
  );
}
