import { Suspense } from 'react';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../../tenant-shell';
import { NewPackageForm } from '../package-forms';
import { IconHeading } from '@/components/stat-card';
import { IconFile } from '@/components/icons';

export const metadata = { title: 'Buat paket' };

async function Content({ params }: PageProps<'/dasbor/ppiu/[tenant]/paket/baru'>) {
  const { tenant: id } = await params;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/paket/baru`, 'package.manage');
  if (!isTenantPage(page)) return page;
  return (
    <>
      <TenantHeader page={page} active="paket" title="Buat paket baru" back={{ href: `/dasbor/ppiu/${id}/paket`, label: 'Daftar paket' }} />
      <div className="mx-auto max-w-3xl px-4 py-6">
        <div className="card p-5 md:p-6">
          <IconHeading icon={IconFile} className="text-xl font-extrabold">Data dasar</IconHeading>
          <p className="mt-1 text-ink-600">Paket disimpan sebagai draf. Hotel, rencana perjalanan, foto, keberangkatan, dan harga dilengkapi di langkah berikutnya.</p>
          <div className="mt-5"><NewPackageForm tenantId={id} /></div>
        </div>
      </div>
    </>
  );
}

export default function NewPackagePage(props: PageProps<'/dasbor/ppiu/[tenant]/paket/baru'>) {
  return <Suspense fallback={<div className="mx-auto h-96 max-w-3xl px-4 py-10" />}><Content {...props} /></Suspense>;
}
