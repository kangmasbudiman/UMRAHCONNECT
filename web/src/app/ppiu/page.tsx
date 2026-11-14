import { Suspense } from 'react';
import { getTenants } from '@/lib/api';
import { PageBanner, TenantCard } from '@/components/ui';

export const metadata = { title: 'Direktori PPIU' };

/** MKT-03 Direktori PPIU terverifikasi (FR-MKT-06). */
export default function TenantsPage() {
  return (
    <>
      <PageBanner eyebrow="Direktori" title="PPIU terverifikasi">
        Setiap PPIU di bawah sudah diperiksa dokumen badan usaha dan izin PPIU-nya oleh tim UMRAH CONNECT.
      </PageBanner>
      <div className="mx-auto max-w-6xl px-4 py-8">
        <Suspense fallback={<div className="card h-40 animate-pulse" />}>
          <List />
        </Suspense>
      </div>
    </>
  );
}

async function List() {
  const { data } = await getTenants();
  return (
    <>
      <p className="mb-4 font-bold">{data.length} PPIU terverifikasi</p>
      <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {data.map((t) => <li key={t.id}><TenantCard t={t} /></li>)}
      </ul>
    </>
  );
}
