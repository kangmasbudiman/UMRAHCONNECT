import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { serverApi, ServerApiError } from '@/lib/session';
import type { PackageManaged } from '@/lib/tenant-types';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../../tenant-shell';
import { DeparturesPanel, PackageActions, PackageDetailsForm, PhotosPanel } from '../package-forms';

export const metadata = { title: 'Kelola paket' };

async function Editor({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/paket/[paket]'>) {
  const { tenant: id, paket } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/paket/${paket}`, 'package.view');
  if (!isTenantPage(page)) return page;

  let pkg: PackageManaged;
  try {
    pkg = (await serverApi<{ data: PackageManaged }>(`/tenants/${id}/packages/${paket}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  const perms = page.membership.permissions;
  const canManage = perms.includes('package.manage');
  const editable = ['draft', 'published'].includes(pkg.status);

  return (
    <>
      <TenantHeader page={page} active="paket" title={pkg.name} back={{ href: `/dasbor/ppiu/${id}/paket`, label: 'Daftar paket' }}
        sub={<>{pkg.duration_days} hari · {pkg.airline}</>} />
      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-6 lg:grid-cols-[1fr_320px]">
        <div className="space-y-6">
          {sp.baru === '1' && pkg.status === 'draft' && (
            <Alert tone="success" title="Draf paket dibuat">Lengkapi hotel, rencana perjalanan, dan syarat; tambahkan foto dan keberangkatan; lalu terbitkan.</Alert>
          )}
          {pkg.status === 'published' && editable && (
            <Alert tone="info">Paket sedang terbit. Perubahan langsung tampil di marketplace setelah disimpan.</Alert>
          )}
          <section id="keberangkatan" className="card scroll-mt-24 p-5 md:p-6">
            <h2 className="text-xl font-extrabold">Keberangkatan dan harga</h2>
            <p className="mt-1 text-ink-600">Setiap keberangkatan punya kuota dan harga resminya sendiri. Sisa kursi = kuota − ditahan − terkonfirmasi.</p>
            <div className="mt-4"><DeparturesPanel tenantId={id} pkg={pkg} canManage={perms.includes('departure.manage')} canPrice={perms.includes('departure.price.update')} /></div>
          </section>
          <section id="foto" className="card scroll-mt-24 p-5 md:p-6">
            <h2 className="text-xl font-extrabold">Foto</h2>
            <div className="mt-4"><PhotosPanel tenantId={id} pkg={pkg} editable={canManage && editable} /></div>
          </section>
          <section id="detail" className="card scroll-mt-24 p-5 md:p-6">
            <h2 className="mb-4 text-xl font-extrabold">Detail paket</h2>
            <PackageDetailsForm tenantId={id} pkg={pkg} readOnly={!canManage || !editable} />
          </section>
        </div>
        <aside className="space-y-4 lg:sticky lg:top-24 lg:self-start">
          <PackageActions tenantId={id} pkg={pkg} tenantSlug={page.tenant.slug} canManage={canManage}
            canPublish={perms.includes('package.publish')} tenantVerified={page.tenant.verification_status === 'verified'} />
          <nav aria-label="Bagian paket" className="card p-2">
            {[['#keberangkatan', 'Keberangkatan dan harga'], ['#foto', `Foto (${pkg.images.length})`], ['#detail', 'Detail paket']].map(([h, l]) => (
              <a key={h} href={h} className="flex min-h-12 items-center rounded-[10px] px-3 font-semibold hover:bg-primary-50">{l}</a>
            ))}
          </nav>
        </aside>
      </div>
    </>
  );
}

export default function PackageEditorPage(props: PageProps<'/dasbor/ppiu/[tenant]/paket/[paket]'>) {
  return (
    <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}>
      <Editor {...props} />
    </Suspense>
  );
}
