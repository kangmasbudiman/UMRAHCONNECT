import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import type { Metadata } from 'next';
import { IconCalendar, IconMapPin, IconPlane, IconVerified } from '@/components/icons';
import { Cover, WhatsAppButton } from '@/components/ui';
import { getAgentPage } from '@/lib/api';
import { rupiah, tanggal, waLink } from '@/lib/format';

type Props = PageProps<'/a/[kode]'>;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { kode } = await params;
  const a = await getAgentPage(kode);
  return a ? { title: `${a.display_name} — agen umrah resmi`, description: `Paket umrah dari ${a.partners.map((p) => p.brand_name).join(', ')} bersama agen ${a.display_name}${a.domicile_city ? ` di ${a.domicile_city}` : ''}.` } : { title: 'Agen tidak ditemukan' };
}

/** Halaman agen publik (Agen Premium): semua paket mitra, tiap tautan membawa kode referral agen. */
async function AgentBody({ params }: Props) {
  const { kode } = await params;
  const a = await getAgentPage(kode);
  if (!a) notFound();
  const wa = waLink(a.whatsapp, `Assalamu'alaikum ${a.display_name}, saya ingin bertanya tentang paket umrah.`);
  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-end justify-between gap-4 px-4 py-10">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Agen umrah resmi · kode {a.code}</p>
            <h1 className="mt-1 text-3xl font-extrabold">{a.display_name}</h1>
            {a.domicile_city && <p className="mt-1 flex items-center gap-1 text-white/85"><IconMapPin className="h-4 w-4" />{a.domicile_city}</p>}
            <p className="mt-2 flex flex-wrap items-center gap-2 text-sm text-white/85"><IconVerified className="h-4 w-4 text-gold-500" />Mitra resmi: {a.partners.map((p) => p.brand_name).join(', ')}</p>
          </div>
          {wa && <WhatsAppButton href={wa} label={`Chat ${a.display_name}`} />}
        </div>
      </section>
      <div className="mx-auto max-w-5xl space-y-4 px-4 py-8">
        <p className="rounded-[10px] border border-gold-500 bg-gold-50 p-3 text-sm">Harga sama dengan harga resmi PPIU. Pembayaran selalu ke rekening resmi PPIU, bukan ke rekening pribadi agen.</p>
        {a.packages.length === 0 ? <p className="card p-8 text-center text-ink-600">Belum ada paket yang dibuka.</p> : (
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {a.packages.map((p) => (
              <li key={`${p.tenant.slug}/${p.slug}`}>
                <Link href={`/paket/${p.tenant.slug}/${p.slug}${p.referral_code ? `?ref=${p.referral_code}` : ''}`} className="card group flex h-full flex-col overflow-hidden hover:shadow-raised">
                  <Cover url={p.cover_image_url} title={p.name} className="h-36" label={`${p.duration_days} hari`} />
                  <div className="flex flex-1 flex-col gap-1.5 p-4">
                    <p className="font-bold group-hover:text-primary-700">{p.name}</p>
                    <p className="text-sm text-ink-600">{p.tenant.brand_name}</p>
                    <p className="flex items-center gap-1.5 text-sm text-ink-600"><IconPlane className="h-4 w-4" />{p.airline}</p>
                    {p.next_departure && <p className="flex items-center gap-1.5 text-sm text-ink-600"><IconCalendar className="h-4 w-4" />{tanggal(p.next_departure.departure_date)} · {p.next_departure.departure_city}</p>}
                    <p className="num mt-auto pt-2 text-lg font-extrabold text-primary-800">{rupiah(p.price_from_amount)}<span className="text-sm font-normal text-ink-600"> /orang</span></p>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}

export default function AgentPublicPage(props: Props) {
  return <Suspense fallback={<div className="mx-auto max-w-5xl px-4 py-10"><div className="card h-96 animate-pulse" /></div>}><AgentBody {...props} /></Suspense>;
}
