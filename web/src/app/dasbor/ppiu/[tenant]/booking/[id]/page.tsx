import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { BookingDetail, type Note } from '@/components/booking/booking-detail';
import type { Booking } from '@/lib/booking-types';
import { serverApi, ServerApiError } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../../tenant-shell';

export const metadata = { title: 'Detail booking' };

async function Detail({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/booking/[id]'>) {
  const { tenant: id, id: bookingId } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/booking/${bookingId}`, 'booking.view');
  if (!isTenantPage(page)) return page;
  let b: Booking;
  try {
    b = (await serverApi<{ data: Booking }>(`/tenants/${id}/bookings/${bookingId}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  const perms = page.membership.permissions;
  const [notes, pkg] = await Promise.all([
    perms.includes('booking.note') ? serverApi<{ data: Note[] }>(`/tenants/${id}/bookings/${bookingId}/notes`).then((r) => r.data) : Promise.resolve(undefined),
    perms.includes('package.view') ? serverApi<{ data: { cancellation_terms: string | null } }>(`/tenants/${id}/packages/${b.package.id}`).then((r) => r.data).catch(() => null) : Promise.resolve(null),
  ]);
  return (
    <>
      <TenantHeader page={page} active="booking" title={`Booking ${b.code}`} back={{ href: `/dasbor/ppiu/${id}/booking`, label: 'Daftar booking' }} />
      <div className="mx-auto max-w-6xl px-4 py-6">
        <BookingDetail b={b} view="tenant" isNew={sp.baru === '1'} tenant={{ tenantId: id, perms, notes, cancellationTerms: pkg?.cancellation_terms }} />
      </div>
    </>
  );
}

export default function TenantBookingPage(props: PageProps<'/dasbor/ppiu/[tenant]/booking/[id]'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Detail {...props} /></Suspense>;
}
