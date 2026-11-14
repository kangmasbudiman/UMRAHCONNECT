import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { BookingDetail } from '@/components/booking/booking-detail';
import type { Booking } from '@/lib/booking-types';
import { requireContexts, serverApi, ServerApiError } from '@/lib/session';

export const metadata = { title: 'Detail booking' };

async function Detail({ params, searchParams }: PageProps<'/akun/booking/[id]'>) {
  const { id } = await params;
  const sp = await searchParams;
  await requireContexts(`/akun/booking/${id}`);
  let b: Booking;
  try {
    b = (await serverApi<{ data: Booking }>(`/me/bookings/${id}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  return (
    <>
      <Link href="/akun/booking" className="mb-3 inline-block font-semibold text-primary-700 hover:underline">← Booking saya</Link>
      <BookingDetail b={b} view="jamaah" isNew={sp.baru === '1'} />
    </>
  );
}

export default function MyBookingPage(props: PageProps<'/akun/booking/[id]'>) {
  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><Detail {...props} /></Suspense>
    </div>
  );
}
