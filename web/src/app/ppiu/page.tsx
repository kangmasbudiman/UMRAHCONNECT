import Form from 'next/form';
import Link from 'next/link';
import { Suspense } from 'react';
import { getFacets, getTenants, lokasiLabel } from '@/lib/api';
import { PageBanner, TenantCard } from '@/components/ui';
import { LokasiSelect } from '@/components/lokasi-select';
import { IconMapPin, IconX } from '@/components/icons';

export const metadata = { title: 'Direktori PPIU' };

/** MKT-03 Direktori PPIU terverifikasi (FR-MKT-06), bisa disaring per provinsi / kab-kota kantor PPIU. */
export default function TenantsPage({ searchParams }: PageProps<'/ppiu'>) {
  return (
    <>
      <PageBanner eyebrow="Direktori" title="PPIU terverifikasi">
        Setiap PPIU di bawah sudah diperiksa dokumen badan usaha dan izin PPIU-nya oleh tim UMRAH CONNECT.
      </PageBanner>
      <div className="mx-auto max-w-6xl px-4 py-8">
        <Suspense fallback={<div className="card h-40 animate-pulse" />}>
          <List searchParams={searchParams} />
        </Suspense>
      </div>
    </>
  );
}

async function List({ searchParams }: { searchParams: PageProps<'/ppiu'>['searchParams'] }) {
  const sp = await searchParams;
  const raw = Array.isArray(sp.lokasi) ? sp.lokasi[0] : sp.lokasi;
  const lokasi = raw && /^\d{2}(\.\d{2})?$/.test(raw) ? raw : undefined;
  const [{ data }, facets] = await Promise.all([getTenants(lokasi), getFacets()]);
  const label = lokasiLabel(lokasi, facets.ppiu_regions);
  return (
    <>
      <Form action="/ppiu" className="card mb-6 flex flex-wrap items-end gap-3 p-4">
        <div className="min-w-64 flex-1">
          <label htmlFor="ppiu-lokasi" className="mb-1.5 flex items-center gap-1.5 font-semibold"><IconMapPin className="h-4 w-4 text-primary-700" />Lokasi kantor PPIU</label>
          <LokasiSelect id="ppiu-lokasi" value={lokasi} regions={facets.ppiu_regions} />
        </div>
        <button type="submit" className="btn btn-primary">Tampilkan</button>
      </Form>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <p className="font-bold">{data.length} PPIU terverifikasi{label ? ` di ${label}` : ''}</p>
        {lokasi && (
          <Link href="/ppiu" className="inline-flex items-center gap-1 rounded-full bg-primary-50 px-3 py-1 text-sm font-semibold text-primary-700 hover:bg-primary-100">
            <IconX className="h-4 w-4" />Hapus filter
          </Link>
        )}
      </div>
      {data.length === 0 ? (
        <p className="card p-8 text-center text-ink-600">Belum ada PPIU terverifikasi di daerah ini. Coba provinsi lain atau lihat seluruh Indonesia.</p>
      ) : (
        <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {data.map((t) => <li key={t.id}><TenantCard t={t} /></li>)}
        </ul>
      )}
    </>
  );
}
