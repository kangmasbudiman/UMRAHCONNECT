import Link from 'next/link';
import { connection } from 'next/server';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { BookingWizard } from '@/components/booking/booking-wizard';
import type { Partnership } from '@/lib/agent-types';
import type { WizardDeparture } from '@/lib/booking-types';
import { tanggal } from '@/lib/format';
import type { PackageManaged } from '@/lib/tenant-types';
import { serverApi } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../../tenant-shell';
import { IconHeading } from '@/components/stat-card';
import { IconPackage } from '@/components/icons';

export const metadata = { title: 'Booking walk-in' };

/** PPIU-12 Booking walk-in (FR-PPIU-12): staf mendaftarkan jamaah yang datang ke kantor. */
async function Content({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/booking/baru'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/booking/baru`, 'booking.create');
  if (!isTenantPage(page)) return page;
  const pkgId = typeof sp.paket === 'string' ? sp.paket : null;
  await connection(); // tanggal hari ini dihitung saat request
  const today = new Date().toISOString().slice(0, 10);

  if (!pkgId) {
    const pkgs = (await serverApi<{ data: PackageManaged[] }>(`/tenants/${id}/packages?limit=100`)).data.filter((p) => ['draft', 'published'].includes(p.status) && p.departures_count > 0);
    return (
      <>
        <TenantHeader page={page} active="booking" title="Booking walk-in" back={{ href: `/dasbor/ppiu/${id}/booking`, label: 'Daftar booking' }} />
        <div className="mx-auto max-w-4xl px-4 py-6">
          <IconHeading icon={IconPackage} className="mb-3 text-xl font-extrabold">Pilih paket</IconHeading>
          {pkgs.length === 0 ? <Alert tone="info">Belum ada paket dengan keberangkatan mendatang.</Alert> : (
            <ul className="space-y-3">
              {pkgs.map((p) => (
                <li key={p.id}>
                  <Link href={`/dasbor/ppiu/${id}/booking/baru?paket=${p.id}`} className="card flex flex-wrap items-center justify-between gap-3 p-4 hover:border-primary-600">
                    <span><span className="font-bold">{p.name}</span> <span className="text-sm text-ink-600">· {p.status === 'draft' ? 'draf' : 'terbit'} · {p.departures_count} keberangkatan{p.next_departure_date && `, terdekat ${tanggal(p.next_departure_date)}`}</span></span>
                    <span className="font-bold text-primary-700">Pilih →</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </>
    );
  }

  const [pkg, partners] = await Promise.all([
    serverApi<{ data: PackageManaged }>(`/tenants/${id}/packages/${pkgId}`).then((r) => r.data),
    page.membership.permissions.includes('partnership.view')
      ? serverApi<{ data: Partnership[] }>(`/tenants/${id}/partnerships?filter[status]=active&limit=100`).then((r) => r.data)
      : Promise.resolve([] as Partnership[]),
  ]);
  const departures: WizardDeparture[] = (pkg.departures ?? []).filter((d) => d.status === 'scheduled' && d.departure_date > today);

  return (
    <>
      <TenantHeader page={page} active="booking" title={`Walk-in: ${pkg.name}`} back={{ href: `/dasbor/ppiu/${id}/booking/baru`, label: 'Ganti paket' }} />
      <div className="mx-auto max-w-6xl px-4 py-6">
        <BookingWizard mode="staff" endpoint={`/tenants/${id}/bookings`} successHref={`/dasbor/ppiu/${id}/booking/{id}?baru=1`}
          packageName={pkg.name} tenantName={page.tenant.brand_name} cancellationTerms={pkg.cancellation_terms} departures={departures}
          agents={partners.map((p) => ({ id: p.agent.id, code: p.agent.code, display_name: p.agent.display_name }))} />
      </div>
    </>
  );
}

export default function WalkInPage(props: PageProps<'/dasbor/ppiu/[tenant]/booking/baru'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Content {...props} /></Suspense>;
}
