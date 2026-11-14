import Link from 'next/link';
import { Suspense } from 'react';
import { IconCalendarDays, IconPiggy, IconShieldCheck } from '@/components/icons';
import { PageBanner } from '@/components/ui';
import { getPackage } from '@/lib/api';
import { getContexts } from '@/lib/session';
import { SavingsCalculator } from './calculator';

export const metadata = { title: 'Rencana Tabungan Umrah', description: 'Hitung berapa yang perlu ditabung per bulan untuk berangkat umrah, lalu dapatkan pengingat setiap bulan.' };

async function Body({ searchParams }: PageProps<'/tabungan'>) {
  const sp = await searchParams;
  const key = typeof sp.paket === 'string' && /^[a-z0-9-]+\/[a-z0-9-]+$/.test(sp.paket) ? sp.paket : null;
  const [ctx, p] = await Promise.all([getContexts().catch(() => null), key ? getPackage(...(key.split('/') as [string, string])) : Promise.resolve(null)]);
  const prices = p?.departures.flatMap((d) => d.prices.map((x) => x.price_amount)) ?? [];
  const pkg = p && prices.length ? { id: p.id, name: p.name, key: key!, priceFrom: Math.min(...prices) } : null;
  return <SavingsCalculator pkg={pkg} signedIn={!!ctx} />;
}

/** Rencana Tabungan Umrah (fitur pembeda): kalkulator publik + simpan rencana dengan pengingat bulanan. */
export default function SavingsPage(props: PageProps<'/tabungan'>) {
  return (
    <>
      <PageBanner eyebrow="Rencana Tabungan Umrah" title="Berapa yang perlu ditabung per bulan?">
        Masukkan target biaya dan bulan keberangkatan. Simpan rencananya, dan kami ingatkan setiap bulan sampai target tercapai.
      </PageBanner>
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        <Suspense fallback={<div className="card h-96 animate-pulse" />}><Body {...props} /></Suspense>
        <ul className="grid gap-3 text-sm sm:grid-cols-3">
          <li className="card flex gap-3 p-4"><IconPiggy className="h-6 w-6 text-primary-700" />Pilih paket di halaman paket, lalu tekan &quot;Rencanakan tabungan&quot; agar target terisi otomatis.</li>
          <li className="card flex gap-3 p-4"><IconCalendarDays className="h-6 w-6 text-primary-700" />Catat setiap setoran; setoran per bulan dihitung ulang otomatis.</li>
          <li className="card flex gap-3 p-4"><IconShieldCheck className="h-6 w-6 text-primary-700" />Uang tidak pernah dititipkan ke UMRAH CONNECT.</li>
        </ul>
        <p className="text-center"><Link href="/paket" className="font-semibold text-primary-700 underline">Lihat paket umrah</Link></p>
      </div>
    </>
  );
}
