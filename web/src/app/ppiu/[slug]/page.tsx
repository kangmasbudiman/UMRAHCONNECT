import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { getTenant } from '@/lib/api';
import { waLink } from '@/lib/format';
import { PackageCard, SectionHeading, VerifiedBadge, WhatsAppButton } from '@/components/ui';
import { RatingBadge } from '@/components/trust';
import { IconBuilding, IconMapPin, IconPin, IconRoute, IconShield } from '@/components/icons';
import { MapView } from '@/components/map/map-view';
import { directionsUrl } from '@/components/map/map-config';

export async function generateMetadata({ params }: PageProps<'/ppiu/[slug]'>): Promise<Metadata> {
  const t = await getTenant((await params).slug);
  return t ? { title: `${t.brand_name} — PPIU terverifikasi`, description: `Profil dan paket umrah ${t.brand_name} (${t.legal_name}), izin PPIU ${t.ppiu_license_no}.` } : { title: 'PPIU tidak ditemukan' };
}

/** MKT-03 Profil PPIU (FR-MKT-06). */
export default function TenantPage({ params }: PageProps<'/ppiu/[slug]'>) {
  return (
    <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-8"><div className="card h-60 animate-pulse" /></div>}>
      <Body params={params} />
    </Suspense>
  );
}

async function Body({ params }: { params: PageProps<'/ppiu/[slug]'>['params'] }) {
  const { slug } = await params;
  const t = await getTenant(slug);
  if (!t) notFound();
  const initials = t.brand_name.split(' ').map((w) => w[0]).join('').slice(0, 3).toUpperCase();
  return (
    <>
      <section className="bg-hero h-36 md:h-44" aria-hidden="true" />
      <div className="mx-auto max-w-6xl px-4">
        <div className="card -mt-16 p-6">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
            <span aria-hidden="true" className="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border-4 border-white bg-gold-100 text-2xl font-extrabold text-gold-700 shadow-card">{initials}</span>
            <div className="min-w-0 flex-1">
              <h1 className="text-2xl font-extrabold md:text-3xl">{t.brand_name}</h1>
              <p className="text-ink-600">{t.legal_name}</p>
              <div className="mt-2 flex flex-wrap items-center gap-2"><VerifiedBadge brand={t.brand_name} license={t.ppiu_license_no} /><RatingBadge rating={t.rating} /></div>
            </div>
            <WhatsAppButton href={waLink(t.whatsapp, `Assalamualaikum ${t.brand_name}, saya ingin bertanya tentang paket umrah.`)} label="Chat WhatsApp resmi" />
          </div>
          <dl className="mt-6 grid gap-4 border-t border-border-subtle pt-5 sm:grid-cols-3">
            <Info icon={<IconShield />} label="Nomor izin PPIU" value={t.ppiu_license_no} />
            <Info icon={<IconPin />} label="Alamat kantor" value={[t.address, t.city, t.province].filter(Boolean).join(', ')} />
            <Info icon={<IconBuilding />} label="Kontak resmi" value={[t.phone, t.email].filter(Boolean).join(' · ')} />
          </dl>
          {t.latitude != null && t.longitude != null && (
            <div className="mt-6 border-t border-border-subtle pt-5">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-lg font-extrabold"><IconMapPin className="h-5 w-5 text-primary-700" />Lokasi kantor</h2>
                <a href={directionsUrl({ lat: t.latitude, lng: t.longitude })} target="_blank" rel="noopener noreferrer" className="btn btn-outline min-h-10 px-4">
                  <IconRoute className="h-5 w-5" />Petunjuk arah
                </a>
              </div>
              <MapView point={{ lat: t.latitude, lng: t.longitude }} label={t.brand_name} />
            </div>
          )}
        </div>
        <div className="mt-10">
          <SectionHeading title={`Paket aktif (${t.packages.length})`} sub="Harga resmi per orang dari PPIU ini." />
          {t.packages.length === 0 ? (
            <p className="card p-6 text-center text-ink-600">PPIU ini belum menerbitkan paket.</p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {t.packages.map((p) => <PackageCard key={p.id} p={p} />)}
            </div>
          )}
        </div>
      </div>
    </>
  );
}

function Info({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="flex gap-3">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700">{icon}</span>
      <div className="min-w-0"><dt className="text-sm font-bold">{label}</dt><dd className="break-words text-ink-600">{value || '—'}</dd></div>
    </div>
  );
}
