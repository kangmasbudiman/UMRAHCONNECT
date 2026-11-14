import Link from 'next/link';
import { Suspense } from 'react';
import { getFacets, getPackages, getSchedule, getTenants } from '@/lib/api';
import { bulanTahun, budgetSteps } from '@/lib/format';
import { DepartureCard, PackageCard, SectionHeading, TenantCard } from '@/components/ui';
import { HeroSearch } from '@/components/hero-search';
import { IconBank, IconBriefcase, IconBuilding, IconShield, IconTag, IconUsers, IconArrowRight } from '@/components/icons';

/** MKT-01 Beranda (FR-MKT-01): pencarian ala OTA + sorotan katalog. Hasil pencarian ada di /paket. */
export default function Home() {
  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto max-w-6xl px-4 pb-28 pt-10 md:pb-32 md:pt-14">
          <p className="inline-flex items-center gap-2 rounded-full border border-gold-500/50 bg-white/10 px-3 py-1 text-sm font-semibold text-gold-100">
            <span aria-hidden="true" className="h-2 w-2 rounded-full bg-gold-500" />Marketplace umrah Jambi
          </p>
          <h1 className="mt-4 max-w-3xl text-[30px] font-extrabold leading-[38px] md:text-5xl md:leading-[58px]">
            Cari &amp; bandingkan paket umrah dari <span className="text-gold-500">PPIU terverifikasi</span>
          </h1>
          <p className="mt-3 max-w-2xl text-lg text-white/85">Harga resmi travel, jadwal dan sisa kursi terkini, bayar langsung ke rekening PPIU.</p>
        </div>
      </section>

      <div className="mx-auto -mt-20 max-w-6xl px-4 md:-mt-24">
        <Suspense fallback={<div className="card h-56 animate-pulse" aria-busy="true" aria-label="Memuat pencarian" />}>
          <SearchCard />
        </Suspense>
      </div>

      <section id="cara-kerja" aria-label="Keunggulan" className="mx-auto mt-8 max-w-6xl scroll-mt-20 px-4">
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Usp icon={<IconShield className="h-6 w-6" />} title="PPIU terverifikasi" text="Izin dan legalitas diperiksa tim kami." />
          <Usp icon={<IconTag className="h-6 w-6" />} title="Harga resmi" text="Sama lewat agen maupun langsung." />
          <Usp icon={<IconBank className="h-6 w-6" />} title="Bayar ke rekening PPIU" text="Platform tidak memegang dana jamaah." />
          <Usp icon={<IconUsers className="h-6 w-6" />} title="Didampingi agen" text="Agen lokal membantu sampai berangkat." />
        </ul>
      </section>

      <section className="mx-auto mt-14 max-w-6xl px-4" aria-labelledby="h-terdekat">
        <SectionHeading title="Keberangkatan terdekat" sub="Kursi terbatas, cek sisa kursinya." href="/jadwal" linkLabel="Semua jadwal" />
        <Suspense fallback={<RowSkeleton />}>
          <NearestDepartures />
        </Suspense>
      </section>

      <section className="mx-auto mt-14 max-w-6xl px-4">
        <SectionHeading title="Paket termurah" sub="Diurutkan dari harga mulai per orang." href="/paket?sort=price" />
        <Suspense fallback={<RowSkeleton />}>
          <Cheapest />
        </Suspense>
      </section>

      <section className="mx-auto mt-14 max-w-6xl px-4">
        <SectionHeading title="PPIU terverifikasi" sub="Travel berizin yang sudah lolos pemeriksaan dokumen." href="/ppiu" />
        <Suspense fallback={<RowSkeleton />}>
          <Tenants />
        </Suspense>
      </section>

      <section className="mx-auto mt-14 grid max-w-6xl gap-4 px-4 md:grid-cols-2">
        <Cta tone="green" icon={<IconBriefcase className="h-7 w-7" />} title="Jadi agen umrah"
          text="Bagikan link paket dari PPIU mitra dan dapatkan komisi resmi untuk setiap jamaah." href="/daftar?peran=agen" cta="Daftar sebagai agen" />
        <Cta tone="gold" icon={<IconBuilding className="h-7 w-7" />} title="Daftarkan PPIU Anda"
          text="Tampilkan paket ke jamaah Jambi, kelola booking, pembayaran, dan agen dalam satu dasbor." href="/daftar?peran=ppiu" cta="Daftarkan PPIU" />
      </section>
    </>
  );
}

async function SearchCard() {
  const f = await getFacets();
  return (
    <HeroSearch
      months={f.months.map((m) => ({ value: m, label: bulanTahun(m) }))}
      cities={f.departure_cities}
      budgets={budgetSteps(f.price_min_amount)}
    />
  );
}

async function NearestDepartures() {
  const { data } = await getSchedule({});
  const items = data.slice(0, 4);
  if (!items.length) return <Empty text="Belum ada keberangkatan yang dibuka." />;
  return (
    <div className="no-scrollbar -mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 lg:grid-cols-4">
      {items.map((d) => <DepartureCard key={d.id} d={d} />)}
    </div>
  );
}

async function Cheapest() {
  const { data } = await getPackages({ sort: 'price', limit: '4' });
  if (!data.length) return <Empty text="Belum ada paket terbit." />;
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {data.map((p) => <PackageCard key={p.id} p={p} />)}
    </div>
  );
}

async function Tenants() {
  const { data } = await getTenants();
  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      {data.slice(0, 6).map((t) => <TenantCard key={t.id} t={t} />)}
    </div>
  );
}

function Usp({ icon, title, text }: { icon: React.ReactNode; title: string; text: string }) {
  return (
    <li className="card flex items-start gap-3 p-4">
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">{icon}</span>
      <div>
        <p className="font-bold">{title}</p>
        <p className="text-sm text-ink-600">{text}</p>
      </div>
    </li>
  );
}

function Cta({ tone, icon, title, text, href, cta }: { tone: 'green' | 'gold'; icon: React.ReactNode; title: string; text: string; href: string; cta: string }) {
  const green = tone === 'green';
  return (
    <div className={`flex flex-col gap-4 rounded-2xl p-6 md:p-8 ${green ? 'bg-hero text-white' : 'border border-gold-500 bg-gold-50'}`}>
      <span className={`flex h-12 w-12 items-center justify-center rounded-xl ${green ? 'bg-white/10 text-gold-500' : 'bg-gold-500 text-ink-900'}`}>{icon}</span>
      <div>
        <h2 className="text-2xl font-extrabold">{title}</h2>
        <p className={`mt-1 ${green ? 'text-white/85' : 'text-ink-600'}`}>{text}</p>
      </div>
      <Link href={href} className={`btn self-start ${green ? 'btn-gold' : 'btn-primary'}`}>{cta}<IconArrowRight className="h-4 w-4" /></Link>
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return <p className="card p-6 text-center text-ink-600">{text}</p>;
}

function RowSkeleton() {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-busy="true" aria-label="Memuat">
      {Array.from({ length: 4 }, (_, i) => <div key={i} className="card h-56 animate-pulse" />)}
    </div>
  );
}
