import Link from 'next/link';
import { notFound } from 'next/navigation';
import { cookies } from 'next/headers';
import { connection } from 'next/server';
import { Suspense } from 'react';
import type { Metadata } from 'next';
import { getPackage, getReferral, type PackageDetail } from '@/lib/api';
import { ROOM_LABEL, rupiah, tanggal, waLink } from '@/lib/format';
import { Cover, SeatsPill, Stars, VerifiedBadge, WhatsAppButton } from '@/components/ui';
import { IconBank, IconCalendar, IconCheck, IconClock, IconHotel, IconPin, IconPlane, IconTag, IconX } from '@/components/icons';

type Props = PageProps<'/paket/[tenant]/[paket]'>;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { tenant, paket } = await params;
  const p = await getPackage(tenant, paket);
  if (!p) return { title: 'Paket tidak ditemukan' };
  const from = Math.min(...p.departures.flatMap((d) => d.prices.map((x) => x.price_amount)));
  return {
    title: `${p.name} — ${p.tenant.brand_name}`,
    description: `${p.name} oleh ${p.tenant.brand_name} (PPIU terverifikasi). ${p.airline}, ${p.duration_days} hari, mulai ${rupiah(from)}.`,
  };
}

/** MKT-02 Detail paket (FR-MKT-04, FR-MKT-05, FR-MKT-10). */
export default function PackagePage({ params }: Props) {
  return (
    <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-6"><div className="card h-96 animate-pulse" /></div>}>
      <PackageBody params={params} />
    </Suspense>
  );
}

async function PackageBody({ params }: { params: Props['params'] }) {
  const { tenant, paket } = await params;
  const p = await getPackage(tenant, paket);
  if (!p) notFound();
  const allPrices = p.departures.flatMap((d) => d.prices.map((x) => x.price_amount));
  const from = allPrices.length ? Math.min(...allPrices) : null;
  const next = p.departures[0];
  const daftarHref = `/paket/${p.tenant.slug}/${p.slug}/daftar`;
  const anchors = [
    ['ringkasan', 'Ringkasan'], ['keberangkatan', 'Keberangkatan'], ['hotel', 'Hotel'],
    ['fasilitas', 'Fasilitas'], ['itinerary', 'Itinerary'], ['syarat', 'Syarat'],
  ] as const;

  return (
    <div>
      <div className="mx-auto max-w-6xl px-4 pt-4">
        <nav aria-label="Breadcrumb" className="text-sm text-ink-600">
          <Link href="/paket" className="underline hover:text-primary-700">Paket umrah</Link> <span aria-hidden="true">›</span>{' '}
          <Link href={`/ppiu/${p.tenant.slug}`} className="underline hover:text-primary-700">{p.tenant.brand_name}</Link> <span aria-hidden="true">›</span> {p.name}
        </nav>

        <div className="mt-3 grid h-56 grid-cols-4 grid-rows-2 gap-2 overflow-hidden rounded-2xl md:h-80">
          <Cover url={p.images[0]?.url} title={p.name} className="col-span-4 row-span-2 md:col-span-3" priority
            label={p.images.length ? (p.images.length > 1 ? `${p.images.length} foto` : undefined) : 'Foto paket segera tersedia'} />
          <div className="hidden md:block"><Cover url={p.images[1]?.url} title="Makkah" className="h-full" label={p.images[1] ? undefined : 'Makkah'} /></div>
          <div className="hidden md:block"><Cover url={p.images[2]?.url} title="Madinah" className="h-full" label={p.images[2] ? undefined : 'Madinah'} /></div>
        </div>
      </div>

      <nav aria-label="Bagian halaman" className="sticky top-16 z-30 mt-4 border-y border-border-subtle bg-white/95 backdrop-blur">
        <ul className="no-scrollbar mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4">
          {anchors.map(([id, label]) => (
            <li key={id}><a href={`#${id}`} className="flex min-h-12 items-center whitespace-nowrap border-b-2 border-transparent px-3 font-semibold text-ink-600 hover:border-gold-500 hover:text-primary-700">{label}</a></li>
          ))}
        </ul>
      </nav>

      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-6 lg:grid-cols-[1fr_360px]">
        <div className="min-w-0">
          <section id="ringkasan" className="scroll-mt-32">
            <h1 className="text-2xl font-extrabold leading-8 md:text-[32px] md:leading-10">{p.name}</h1>
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <VerifiedBadge brand={p.tenant.brand_name} license={p.tenant.ppiu_license_no} />
              <span className="text-sm text-ink-600">Izin PPIU: {p.tenant.ppiu_license_no}</span>
            </div>
            <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Fact icon={<IconClock />} label="Durasi" value={`${p.duration_days} hari`} />
              <Fact icon={<IconPlane />} label="Maskapai" value={p.airline} />
              <Fact icon={<IconHotel />} label="Hotel Makkah" value={p.hotel_makkah_stars ? `Bintang ${p.hotel_makkah_stars}` : '—'} />
              <Fact icon={<IconPin />} label="Berangkat dari" value={next ? next.departure_city : '—'} />
            </ul>
            {p.description && <p className="mt-4">{p.description}</p>}
          </section>

          <Section id="keberangkatan" title="Pilih keberangkatan">
            {p.departures.length === 0 && <p className="text-ink-600">Belum ada jadwal keberangkatan yang dibuka.</p>}
            <ul className="space-y-3">
              {p.departures.map((d) => (
                <li key={d.id} className="card overflow-hidden">
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border-subtle bg-primary-50/60 px-4 py-3">
                    <p className="flex items-center gap-2 font-bold"><IconCalendar className="h-5 w-5 text-primary-700" />{tanggal(d.departure_date)} – {tanggal(d.return_date)} · dari {d.departure_city}</p>
                    <SeatsPill seats={d.seats_remaining} status={d.sales_status} />
                  </div>
                  <ul className="grid gap-px bg-border-subtle sm:grid-cols-3">
                    {d.prices.map((x) => (
                      <li key={x.room_type} className="bg-white px-4 py-3">
                        <p className="text-sm text-ink-600">{ROOM_LABEL[x.room_type]}</p>
                        <p className="num text-lg font-extrabold text-primary-800">{rupiah(x.price_amount)}</p>
                      </li>
                    ))}
                  </ul>
                  <p className="border-t border-border-subtle px-4 py-2 text-sm text-ink-600">
                    DP {rupiah(d.dp_min_per_pax_amount)} per orang · pelunasan paling lambat {tanggal(d.final_payment_due_on)}
                  </p>
                </li>
              ))}
            </ul>
            <p className="mt-3 flex items-center gap-2 text-sm text-ink-600"><IconTag className="h-4 w-4 text-gold-700" />Harga resmi PPIU per orang, sama di semua kanal termasuk lewat agen.</p>
          </Section>

          <Section id="hotel" title="Hotel">
            <div className="grid gap-3 sm:grid-cols-2">
              <HotelCard city="Makkah" name={p.hotel_makkah} stars={p.hotel_makkah_stars} distance={p.hotel_makkah_distance_m} mosque="Masjidil Haram" />
              <HotelCard city="Madinah" name={p.hotel_madinah} stars={p.hotel_madinah_stars} distance={p.hotel_madinah_distance_m} mosque="Masjid Nabawi" />
            </div>
          </Section>

          <Section id="fasilitas" title="Fasilitas">
            <ul className="card grid gap-2 p-4 sm:grid-cols-2">{p.facilities.map((f) => <li key={f} className="flex gap-2"><IconCheck className="h-5 w-5 text-primary-700" />{f}</li>)}</ul>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <div className="card p-4">
                <h3 className="mb-2 font-bold">Termasuk</h3>
                <ul className="space-y-1.5">{p.includes.map((f) => <li key={f} className="flex gap-2"><IconCheck className="h-5 w-5 text-success" />{f}</li>)}</ul>
              </div>
              <div className="card p-4">
                <h3 className="mb-2 font-bold">Tidak termasuk</h3>
                <ul className="space-y-1.5">{p.excludes.map((f) => <li key={f} className="flex gap-2 text-ink-600"><IconX className="h-5 w-5 text-danger" />{f}</li>)}</ul>
              </div>
            </div>
          </Section>

          <Section id="itinerary" title="Rencana perjalanan">
            <ol className="card relative space-y-4 p-4">
              {p.itinerary.map((d) => (
                <li key={d.day} className="flex gap-3">
                  <span className="num flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-700 text-sm font-bold text-white ring-4 ring-primary-50">{d.day}</span>
                  <div>
                    <p className="font-bold">Hari {d.day} · {d.title}</p>
                    {d.activities?.length ? <p className="text-ink-600">{d.activities.join(' · ')}</p> : null}
                  </div>
                </li>
              ))}
            </ol>
          </Section>

          <Section id="syarat" title="Syarat dan ketentuan">
            <dl className="card space-y-4 p-4">
              <Term label="Dokumen yang diperlukan">{p.document_requirements}</Term>
              <Term label="Pembayaran">{p.payment_terms}</Term>
              <Term label="Kebijakan pembatalan">{p.cancellation_terms}</Term>
            </dl>
          </Section>
        </div>

        <aside className="lg:sticky lg:top-32 lg:self-start">
          <div className="card overflow-hidden">
            <div className="bg-hero p-5 text-white">
              <p className="text-sm text-white/85">Mulai dari</p>
              {from !== null ? <p className="num text-3xl font-extrabold">{rupiah(from)}</p> : <p className="font-bold">Belum ada harga</p>}
              <p className="text-sm text-white/85">per orang · harga resmi PPIU</p>
            </div>
            <div className="p-5">
              {next && <p className="flex items-center gap-2 text-sm text-ink-600"><IconCalendar className="h-4 w-4" />Terdekat: {tanggal(next.departure_date)} <SeatsPill seats={next.seats_remaining} status={next.sales_status} /></p>}
              <Link href={daftarHref} className="btn btn-gold mt-4 w-full text-lg">Daftar sekarang</Link>
              <Suspense fallback={null}>
                <ReferralBox pkg={p} />
              </Suspense>
              <div className="mt-4 flex gap-3 rounded-xl border border-gold-500 bg-gold-50 p-3 text-sm">
                <IconBank className="h-5 w-5 text-gold-700" />
                <div>
                  <p className="font-bold">Pembayaran langsung ke PPIU</p>
                  <p className="text-ink-600">Transfer hanya ke rekening atas nama {p.tenant.legal_name}. UMRAH CONNECT tidak menerima dana jamaah.</p>
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>

      <div className="sticky bottom-0 z-30 border-t border-border-subtle bg-white px-4 py-3 shadow-[0_-4px_16px_rgb(16_24_40/0.08)] lg:hidden">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3">
          <div>
            <p className="text-xs text-ink-600">Mulai dari</p>
            <p className="num text-lg font-extrabold text-primary-800">{from !== null ? rupiah(from) : '—'}</p>
          </div>
          <Link href={daftarHref} className="btn btn-gold">Daftar sekarang</Link>
        </div>
      </div>
    </div>
  );
}

/** Kotak agen dari cookie uc_ref (CR-01): tampil bila link agen berasal dari PPIU paket ini. */
async function ReferralBox({ pkg }: { pkg: PackageDetail }) {
  const raw = (await cookies()).get('uc_ref')?.value;
  await connection(); // usia cookie dihitung saat request, bukan saat prerender
  let code: string | null = null;
  try {
    const parsed = raw ? JSON.parse(raw) : null;
    if (parsed?.code && Date.now() - Number(parsed.at) <= 30 * 864e5) code = String(parsed.code);
  } catch { /* cookie rusak diabaikan */ }
  const ref = code ? await getReferral(code) : null;
  const viaAgent = ref && ref.tenant.slug === pkg.tenant.slug ? ref : null;
  const text = `Assalamualaikum, saya tertarik dengan paket ${pkg.name} dari ${pkg.tenant.brand_name}.`;

  return (
    <>
      {viaAgent && (
        <div className="mt-4 rounded-lg border border-info bg-info-bg p-3 text-sm text-info">
          Dibantu agen <strong>{viaAgent.agent.display_name}</strong> ({viaAgent.agent.code})
        </div>
      )}
      <WhatsAppButton
        className="mt-4 w-full"
        href={waLink(viaAgent?.agent.whatsapp ?? pkg.tenant.whatsapp, text)}
        label={viaAgent?.agent.whatsapp ? `Tanya agen ${viaAgent.agent.display_name}` : `Tanya ${pkg.tenant.brand_name}`}
      />
    </>
  );
}

function Section({ id, title, children }: { id: string; title: string; children: React.ReactNode }) {
  return <section id={id} className="mt-10 scroll-mt-32"><h2 className="mb-3 text-xl font-extrabold">{title}</h2>{children}</section>;
}
function Fact({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <li className="card flex items-center gap-3 p-3">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gold-100 text-gold-700">{icon}</span>
      <div className="min-w-0"><p className="text-xs text-ink-600">{label}</p><p className="truncate font-bold">{value}</p></div>
    </li>
  );
}
function Term({ label, children }: { label: string; children: React.ReactNode }) {
  if (!children) return null;
  return <div><dt className="font-bold">{label}</dt><dd className="text-ink-600">{children}</dd></div>;
}
function HotelCard({ city, name, stars, distance, mosque }: { city: string; name: string | null; stars: number | null; distance: number | null; mosque: string }) {
  if (!name) return null;
  return (
    <div className="card flex gap-3 p-4">
      <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><IconHotel /></span>
      <div>
        <p className="text-sm font-semibold uppercase tracking-wide text-gold-700">{city}</p>
        <p className="font-bold">{name} <Stars n={stars} /></p>
        {distance !== null && <p className="text-sm text-ink-600">± {distance.toLocaleString('id-ID')} m ke {mosque}</p>}
      </div>
    </div>
  );
}
