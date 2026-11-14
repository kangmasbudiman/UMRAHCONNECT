import Link from 'next/link';
import { Suspense } from 'react';
import { getSchedule, type ScheduleItem } from '@/lib/api';
import { bulanTahun, rupiah, tanggal } from '@/lib/format';
import { PageBanner, SeatsPill } from '@/components/ui';

export const metadata = { title: 'Jadwal keberangkatan' };

/** MKT-04 Jadwal keberangkatan lintas PPIU (FR-MKT-07). */
export default function SchedulePage() {
  return (
    <>
      <PageBanner eyebrow="Jadwal" title="Jadwal keberangkatan">
        Semua keberangkatan dari PPIU terverifikasi, diurutkan dari yang terdekat.
      </PageBanner>
      <div className="mx-auto max-w-6xl px-4 py-2">
        <Suspense fallback={<div className="card mt-6 h-60 animate-pulse" />}>
          <Table />
        </Suspense>
      </div>
    </>
  );
}

async function Table() {
  const { data } = await getSchedule({});
  const byMonth = new Map<string, ScheduleItem[]>();
  for (const d of data) {
    const m = d.departure_date.slice(0, 7);
    byMonth.set(m, [...(byMonth.get(m) ?? []), d]);
  }
  return (
    <div className="mt-8 space-y-10">
      {[...byMonth.entries()].map(([month, items]) => (
        <section key={month} aria-labelledby={`m-${month}`}>
          <h2 id={`m-${month}`} className="mb-3 flex items-center gap-2 text-xl font-extrabold capitalize"><span aria-hidden="true" className="h-6 w-1.5 rounded-full bg-gold-500" />{bulanTahun(month)}</h2>
          <div className="card overflow-x-auto">
            <table className="num w-full min-w-[640px] text-sm">
              <thead className="bg-primary-800 text-left text-white">
                <tr>
                  <th scope="col" className="p-3 font-semibold">Tanggal</th>
                  <th scope="col" className="p-3 font-semibold">Paket</th>
                  <th scope="col" className="p-3 font-semibold">PPIU</th>
                  <th scope="col" className="p-3 font-semibold">Dari</th>
                  <th scope="col" className="p-3 text-right font-semibold">Mulai</th>
                  <th scope="col" className="p-3 font-semibold">Kursi</th>
                </tr>
              </thead>
              <tbody>
                {items.map((d) => (
                  <tr key={d.id} className="border-b border-border-subtle last:border-0 hover:bg-gold-50/60">
                    <td className="p-3 whitespace-nowrap">{tanggal(d.departure_date)}</td>
                    <td className="p-3"><Link href={`/paket/${d.tenant.slug}/${d.package.slug}`} className="font-semibold text-primary-700 underline">{d.package.name}</Link><span className="block text-ink-600">{d.package.airline} · {d.package.duration_days} hari</span></td>
                    <td className="p-3">{d.tenant.brand_name}</td>
                    <td className="p-3">{d.departure_city}</td>
                    <td className="p-3 text-right font-extrabold whitespace-nowrap text-primary-800">{rupiah(Math.min(...d.prices.map((p) => p.price_amount)))}</td>
                    <td className="p-3"><SeatsPill seats={d.seats_remaining} status={d.sales_status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ))}
    </div>
  );
}
