import Link from 'next/link';
import { Suspense } from 'react';
import type { ReactNode } from 'react';
import { CompareToggle } from '@/components/compare';
import { IconCheck, IconScale, IconStar, IconX } from '@/components/icons';
import { ElderlyBadge, RatingBadge, TransparencyBadge } from '@/components/trust';
import { PageBanner, Stars, VerifiedBadge } from '@/components/ui';
import { getPackage, type PackageDetail } from '@/lib/api';
import { ROOM_LABEL, rupiah, tanggal } from '@/lib/format';

export const metadata = { title: 'Bandingkan paket' };

type Row = { label: string; render: (p: PackageDetail) => ReactNode; best?: (ps: PackageDetail[]) => string | null };
const minPrice = (p: PackageDetail) => Math.min(...p.departures.flatMap((d) => d.prices.map((x) => x.price_amount)), Number.POSITIVE_INFINITY);
const pick = (ps: PackageDetail[], score: (p: PackageDetail) => number | null, lower = true) => {
  const vals = ps.map((p) => ({ id: p.id, v: score(p) })).filter((x) => x.v !== null && Number.isFinite(x.v)) as { id: string; v: number }[];
  if (vals.length < 2) return null;
  const best = lower ? Math.min(...vals.map((x) => x.v)) : Math.max(...vals.map((x) => x.v));
  const winners = vals.filter((x) => x.v === best);
  return winners.length === 1 ? winners[0].id : null;
};
const dist = (m: number | null) => (m === null ? '—' : m >= 1000 ? `${(m / 1000).toLocaleString('id-ID')} km` : `${m} m`);

const ROWS: Row[] = [
  { label: 'Harga mulai', render: (p) => <span className="num text-lg font-extrabold text-primary-800">{Number.isFinite(minPrice(p)) ? rupiah(minPrice(p)) : '—'}</span>, best: (ps) => pick(ps, minPrice) },
  { label: 'Harga per kamar (terdekat)', render: (p) => (
    <ul className="space-y-0.5 text-sm">{p.departures[0]?.prices.map((x) => <li key={x.room_type}>{ROOM_LABEL[x.room_type]}: <span className="num font-semibold">{rupiah(x.price_amount)}</span></li>) ?? '—'}</ul>
  ) },
  { label: 'Keberangkatan terdekat', render: (p) => p.departures[0] ? <span>{tanggal(p.departures[0].departure_date)} · {p.departures[0].departure_city}<br /><span className="text-sm text-ink-600">sisa {p.departures[0].seats_remaining} kursi · {p.departures.length} jadwal</span></span> : '—' },
  { label: 'Durasi', render: (p) => `${p.duration_days} hari` },
  { label: 'Maskapai', render: (p) => p.airline },
  { label: 'Hotel Makkah', render: (p) => <span>{p.hotel_makkah ?? '—'}<br /><Stars n={p.hotel_makkah_stars} /> · <span className="num">{dist(p.hotel_makkah_distance_m)}</span> ke Masjidil Haram</span>, best: (ps) => pick(ps, (p) => p.hotel_makkah_distance_m) },
  { label: 'Hotel Madinah', render: (p) => <span>{p.hotel_madinah ?? '—'}<br /><Stars n={p.hotel_madinah_stars} /> · <span className="num">{dist(p.hotel_madinah_distance_m)}</span> ke Masjid Nabawi</span>, best: (ps) => pick(ps, (p) => p.hotel_madinah_distance_m) },
  { label: 'Ramah lansia', render: (p) => p.elderly_friendly ? <ElderlyBadge show /> : <span className="text-ink-600">Tidak</span> },
  { label: 'DP & pelunasan', render: (p) => p.departures[0] ? <span>DP <span className="num font-semibold">{rupiah(p.departures[0].dp_min_per_pax_amount)}</span>/orang<br /><span className="text-sm text-ink-600">lunas sebelum {tanggal(p.departures[0].final_payment_due_on)}</span></span> : '—', best: (ps) => pick(ps, (p) => p.departures[0]?.dp_min_per_pax_amount ?? null) },
  { label: 'Termasuk', render: (p) => <ul className="space-y-0.5 text-sm">{p.includes.slice(0, 6).map((i) => <li key={i} className="flex gap-1"><IconCheck className="h-4 w-4 text-success" />{i}</li>)}</ul> },
  { label: 'Tidak termasuk', render: (p) => <ul className="space-y-0.5 text-sm text-ink-600">{p.excludes.slice(0, 6).map((i) => <li key={i} className="flex gap-1"><IconX className="h-4 w-4 text-danger" />{i}</li>)}</ul> },
  { label: 'Kebijakan pembatalan', render: (p) => <p className="text-sm">{p.cancellation_terms ?? '—'}</p> },
  { label: 'Ulasan jamaah', render: (p) => p.rating?.count ? <RatingBadge rating={p.rating} /> : <span className="text-sm text-ink-600">Belum ada</span>, best: (ps) => pick(ps, (p) => (p.rating?.count ? p.rating.avg : null), false) },
  { label: 'Skor transparansi', render: (p) => <TransparencyBadge score={p.transparency.score} />, best: (ps) => pick(ps, (p) => p.transparency.score, false) },
];

async function Compare({ searchParams }: PageProps<'/bandingkan'>) {
  const sp = await searchParams;
  const raw = typeof sp.p === 'string' ? sp.p : '';
  const keys = [...new Set(raw.split(',').map((k) => k.trim()).filter((k) => /^[a-z0-9-]+\/[a-z0-9-]+$/.test(k)))].slice(0, 3);
  const pkgs = (await Promise.all(keys.map((k) => { const [t, s] = k.split('/'); return getPackage(t, s); }))).filter((p): p is PackageDetail => p !== null);

  if (pkgs.length < 2) {
    return (
      <div className="card p-8 text-center">
        <IconScale className="mx-auto mb-2 h-10 w-10 text-primary-600/60" />
        <p className="text-lg font-bold">Pilih minimal 2 paket untuk dibandingkan</p>
        <p className="mt-1 text-ink-600">Tekan tombol <strong>Bandingkan</strong> di kartu paket, lalu buka kembali halaman ini dari baki di bawah layar.</p>
        <Link href="/paket" className="btn btn-primary mt-4">Cari paket</Link>
      </div>
    );
  }
  return (
    <div className="overflow-x-auto rounded-[12px] border border-border-subtle bg-white">
      <table className="w-full min-w-[720px] border-collapse text-left align-top">
        <thead>
          <tr>
            <th scope="col" className="w-44 bg-surface p-3 text-sm text-ink-600">Paket</th>
            {pkgs.map((p) => (
              <th key={p.id} scope="col" className="border-l border-border-subtle p-3 align-top">
                <Link href={`/paket/${p.tenant.slug}/${p.slug}`} className="text-lg font-bold leading-6 text-primary-800 hover:underline">{p.name}</Link>
                <div className="mt-1"><VerifiedBadge brand={p.tenant.brand_name} license={p.tenant.ppiu_license_no} /></div>
                <div className="mt-2 flex flex-wrap gap-2">
                  <Link href={`/paket/${p.tenant.slug}/${p.slug}/daftar`} className="btn btn-gold min-h-10 px-3 text-sm">Daftar</Link>
                  <CompareToggle itemKey={`${p.tenant.slug}/${p.slug}`} name={p.name} />
                </div>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ROWS.map((r) => {
            const winner = r.best?.(pkgs) ?? null;
            return (
              <tr key={r.label} className="border-t border-border-subtle">
                <th scope="row" className="bg-surface p-3 text-sm font-semibold text-ink-600">{r.label}</th>
                {pkgs.map((p) => (
                  <td key={p.id} className={`border-l border-border-subtle p-3 ${winner === p.id ? 'bg-gold-50' : ''}`}>
                    {r.render(p)}
                    {winner === p.id && <p className="mt-1 inline-flex items-center gap-1 text-xs font-bold text-gold-700"><IconStar className="h-3.5 w-3.5 fill-current" />Terbaik</p>}
                  </td>
                ))}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export default function ComparePage(props: PageProps<'/bandingkan'>) {
  return (
    <>
      <PageBanner eyebrow="Bandingkan paket" title="Bandingkan berdampingan">
        Harga per kamar, jarak hotel, DP, kebijakan batal, ulasan, dan skor transparansi dalam satu tabel. Sel berwarna emas = nilai terbaik di baris itu.
      </PageBanner>
      <div className="mx-auto max-w-6xl px-4 py-6 pb-32"><Suspense fallback={<div className="card h-96 animate-pulse" />}><Compare {...props} /></Suspense></div>
    </>
  );
}
