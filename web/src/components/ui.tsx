import Image from 'next/image';
import Link from 'next/link';
import { rupiah, tanggal } from '@/lib/format';
import type { PackageSummary, ScheduleItem, TenantPublic } from '@/lib/api';
import { IconArrowRight, IconCalendar, IconClock, IconPin, IconPlane, IconShield } from './icons';

/** Lencana PPIU terverifikasi (UI/UX Design §2: VerifiedBadge). */
export function VerifiedBadge({ brand, license }: { brand: string; license?: string }) {
  return (
    <span title={license ? `Izin PPIU: ${license}` : undefined}
      className="inline-flex items-center gap-1.5 rounded-full border border-primary-700 bg-primary-50 px-3 py-0.5 text-[13px] font-semibold leading-[22px] text-primary-700">
      <svg aria-hidden="true" viewBox="0 0 16 16" className="h-3.5 w-3.5 fill-current"><path d="M6.6 11.2 3.4 8l1.1-1.1 2.1 2.1 4.9-4.9L12.6 5z" /></svg>
      {brand} · Terverifikasi
    </span>
  );
}

/** Sisa kursi dengan teks, bukan warna saja (UI/UX Design §2). */
export function SeatsPill({ seats, status }: { seats: number; status?: string }) {
  if (status === 'full' || seats <= 0) {
    return <span className="rounded-full border border-danger bg-danger-bg px-2.5 py-0.5 text-xs font-semibold text-danger">Penuh</span>;
  }
  if (status === 'closed') {
    return <span className="rounded-full border border-ink-600 px-2.5 py-0.5 text-xs font-semibold text-ink-600">Penjualan ditutup</span>;
  }
  const low = seats <= 5;
  return (
    <span className={`whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold ${low ? 'border-warning bg-warning-bg text-warning' : 'border-success bg-success-bg text-success'}`}>
      Sisa {seats} kursi
    </span>
  );
}

export function Stars({ n }: { n: number | null }) {
  if (!n) return null;
  return <span aria-label={`Hotel bintang ${n}`} className="text-gold-700">{'★'.repeat(n)}</span>;
}

/** Sampul sementara sampai unggah foto paket tersedia: gradien hijau-emas + nama pendek paket. */
export function CoverPlaceholder({ title, className = '', label }: { title: string; className?: string; label?: string }) {
  const short = title.replace(/^Umrah\s+/i, '').replace(/\s*\d+\s*Hari$/i, '').trim() || title;
  return (
    <div aria-hidden="true" className={`bg-cover-art relative flex items-center justify-center overflow-hidden px-4 ${className}`}>
      <span className="text-center text-2xl font-extrabold leading-tight tracking-wide text-white/90 drop-shadow">{short}</span>
      {label && <span className="absolute bottom-2 left-2 rounded-md bg-primary-900/75 px-2 py-0.5 text-xs font-semibold text-white">{label}</span>}
    </div>
  );
}

/** Foto sampul dari API (foto pertama paket) atau placeholder bila belum ada foto. */
export function Cover({ url, title, className = '', label, priority = false }: { url: string | null | undefined; title: string; className?: string; label?: string; priority?: boolean }) {
  if (!url) return <CoverPlaceholder title={title} className={className} label={label} />;
  return (
    <div className={`relative overflow-hidden bg-primary-50 ${className}`}>
      <Image src={url} alt={`Foto ${title}`} fill unoptimized priority={priority} sizes="(min-width: 1024px) 400px, 100vw" className="object-cover" />
      {label && <span className="absolute bottom-2 left-2 rounded-md bg-primary-900/75 px-2 py-0.5 text-xs font-semibold text-white">{label}</span>}
    </div>
  );
}

const pkgHref = (p: PackageSummary) => `/paket/${p.tenant.slug}/${p.slug}`;

/** Kartu grid (beranda, profil PPIU). */
export function PackageCard({ p }: { p: PackageSummary }) {
  return (
    <Link href={pkgHref(p)} className="card group flex h-full flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-raised">
      <div className="relative">
        <Cover url={p.cover_image_url} title={p.name} className="h-40" />
        <span className="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-1 text-xs font-bold text-primary-800">
          <IconClock className="h-3.5 w-3.5" />{p.duration_days} hari
        </span>
      </div>
      <div className="flex flex-1 flex-col gap-2 p-4">
        <h3 className="text-lg font-bold leading-6 group-hover:text-primary-700">{p.name}</h3>
        <div><VerifiedBadge brand={p.tenant.brand_name} license={p.tenant.ppiu_license_no} /></div>
        <p className="flex items-center gap-1.5 text-sm text-ink-600"><IconPlane className="h-4 w-4" />{p.airline} · Hotel <Stars n={p.hotel_makkah_stars} /></p>
        {p.next_departure && (
          <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-600">
            <IconCalendar className="h-4 w-4" />{tanggal(p.next_departure.departure_date)} · {p.next_departure.departure_city}
            <SeatsPill seats={p.next_departure.seats_remaining} />
          </p>
        )}
        <div className="mt-auto border-t border-dashed border-border-subtle pt-3">
          <p className="text-xs text-ink-600">Mulai dari</p>
          <p className="num text-xl font-extrabold text-primary-800">{rupiah(p.price_from_amount)}<span className="text-sm font-normal text-ink-600"> /orang</span></p>
        </div>
      </div>
    </Link>
  );
}

/** Kartu hasil pencarian horizontal ala OTA: gambar | info | harga + CTA. Vertikal di ponsel. */
export function PackageRow({ p }: { p: PackageSummary }) {
  return (
    <article className="card group relative grid overflow-hidden transition hover:shadow-raised sm:grid-cols-[200px_1fr] lg:grid-cols-[220px_1fr_220px]">
      <Cover url={p.cover_image_url} title={p.name} className="h-40 sm:h-full sm:min-h-44" label={`${p.duration_days} hari`} />
      <div className="flex min-w-0 flex-col gap-2 p-4">
        <h3 className="text-lg font-bold leading-6">
          <Link href={pkgHref(p)} className="after:absolute after:inset-0 hover:text-primary-700 focus-visible:outline-none">{p.name}</Link>
        </h3>
        <div><VerifiedBadge brand={p.tenant.brand_name} license={p.tenant.ppiu_license_no} /></div>
        <ul className="mt-1 grid gap-1.5 text-sm text-ink-600">
          <li className="flex items-center gap-2"><IconPlane className="h-4 w-4 text-primary-700" />{p.airline}</li>
          <li className="flex items-center gap-2"><IconShield className="h-4 w-4 text-primary-700" />Hotel Makkah <Stars n={p.hotel_makkah_stars} /></li>
          {p.next_departure ? (
            <li className="flex flex-wrap items-center gap-2">
              <IconCalendar className="h-4 w-4 text-primary-700" />Berangkat {tanggal(p.next_departure.departure_date)}
              <span className="inline-flex items-center gap-1"><IconPin className="h-4 w-4 text-primary-700" />{p.next_departure.departure_city}</span>
              <SeatsPill seats={p.next_departure.seats_remaining} />
            </li>
          ) : (
            <li className="flex items-center gap-2"><IconCalendar className="h-4 w-4" />Belum ada jadwal dibuka</li>
          )}
        </ul>
      </div>
      <div className="flex items-end justify-between gap-3 border-t border-dashed border-border-subtle p-4 sm:col-span-2 lg:col-span-1 lg:flex-col lg:items-end lg:justify-center lg:border-l lg:border-t-0 lg:bg-gold-50/60">
        <div className="lg:text-right">
          <p className="text-xs text-ink-600">Mulai dari</p>
          <p className="num text-xl font-extrabold text-primary-800">{rupiah(p.price_from_amount)}</p>
          <p className="text-xs text-ink-600">per orang · harga resmi PPIU</p>
        </div>
        <span className="btn btn-gold px-4">Lihat paket</span>
      </div>
    </article>
  );
}

/** Kartu keberangkatan (beranda: "Keberangkatan terdekat"). */
export function DepartureCard({ d }: { d: ScheduleItem }) {
  const from = Math.min(...d.prices.map((x) => x.price_amount));
  const [day, mon] = tanggal(d.departure_date).split(' ');
  return (
    <Link href={`/paket/${d.tenant.slug}/${d.package.slug}`} className="card group flex h-full w-72 shrink-0 snap-start flex-col p-4 hover:shadow-raised sm:w-auto">
      <div className="flex items-start gap-3">
        <div className="flex h-16 w-14 shrink-0 flex-col items-center justify-center rounded-lg bg-primary-800 text-white">
          <span className="num text-2xl font-extrabold leading-none">{day}</span>
          <span className="text-xs font-semibold uppercase text-gold-500">{mon}</span>
        </div>
        <div className="min-w-0">
          <h3 className="font-bold leading-6 group-hover:text-primary-700">{d.package.name}</h3>
          <p className="text-sm text-ink-600">{d.tenant.brand_name} · {d.package.duration_days} hari</p>
        </div>
      </div>
      <p className="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink-600">
        <IconPin className="h-4 w-4" />dari {d.departure_city} <SeatsPill seats={d.seats_remaining} status={d.sales_status} />
      </p>
      <p className="mt-auto pt-3 text-sm text-ink-600">mulai <span className="num text-lg font-extrabold text-primary-800">{rupiah(from)}</span></p>
    </Link>
  );
}

export function TenantCard({ t }: { t: TenantPublic }) {
  const initials = t.brand_name.split(' ').map((w) => w[0]).join('').slice(0, 3).toUpperCase();
  return (
    <Link href={`/ppiu/${t.slug}`} className="card group flex h-full items-start gap-4 p-5 hover:shadow-raised">
      <span aria-hidden="true" className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-gold-100 text-lg font-extrabold text-gold-700">{initials}</span>
      <div className="min-w-0">
        <p className="text-lg font-bold group-hover:text-primary-700">{t.brand_name}</p>
        <p className="text-sm text-ink-600">{t.legal_name}</p>
        <div className="mt-2"><VerifiedBadge brand={t.brand_name} license={t.ppiu_license_no} /></div>
        <p className="mt-2 text-sm text-ink-600">Izin {t.ppiu_license_no}{t.city ? ` · ${t.city}` : ''}</p>
      </div>
    </Link>
  );
}

/** Pita judul halaman dalam (hijau tua + pola emas). */
export function PageBanner({ eyebrow, title, children }: { eyebrow?: string; title: string; children?: React.ReactNode }) {
  return (
    <section className="bg-hero text-white">
      <div className="mx-auto max-w-6xl px-4 py-10">
        {eyebrow && <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">{eyebrow}</p>}
        <h1 className="mt-1 text-[28px] font-extrabold leading-9 md:text-4xl md:leading-[44px]">{title}</h1>
        {children && <div className="mt-2 max-w-2xl text-white/85">{children}</div>}
      </div>
    </section>
  );
}

export function SectionHeading({ title, sub, href, linkLabel = 'Lihat semua' }: { title: string; sub?: string; href?: string; linkLabel?: string }) {
  return (
    <div className="mb-5 flex flex-wrap items-end justify-between gap-2">
      <div>
        <h2 className="text-2xl font-extrabold leading-8">{title}</h2>
        {sub && <p className="mt-1 text-ink-600">{sub}</p>}
      </div>
      {href && (
        <Link href={href} className="inline-flex min-h-12 items-center gap-1 font-bold text-primary-700 hover:underline">
          {linkLabel}<IconArrowRight className="h-4 w-4" />
        </Link>
      )}
    </div>
  );
}

export function WhatsAppButton({ href, label, className = '' }: { href: string | null; label: string; className?: string }) {
  if (!href) return null;
  return (
    <a href={href} target="_blank" rel="noopener noreferrer"
      className={`inline-flex min-h-12 items-center justify-center gap-2 rounded-[10px] border border-border-input bg-white px-5 font-semibold hover:bg-surface ${className}`}>
      <svg aria-hidden="true" viewBox="0 0 24 24" className="h-5 w-5 fill-[#1FA855]"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7a11.4 11.4 0 0 1-4.4-3.9c-.3-.5-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.2-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.1 1.3Z" /></svg>
      {label}
    </a>
  );
}
