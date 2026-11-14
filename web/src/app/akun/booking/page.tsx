import Link from 'next/link';
import { Suspense } from 'react';
import { BookingList } from '@/components/booking/booking-list';
import { PageBanner } from '@/components/ui';
import type { BookingSummary } from '@/lib/booking-types';
import { requireContexts, serverApi } from '@/lib/session';

export const metadata = { title: 'Booking saya' };

async function List() {
  await requireContexts('/akun/booking');
  const rows = (await serverApi<{ data: BookingSummary[] }>('/me/bookings?limit=50')).data;
  return <BookingList rows={rows} hrefFor={(id) => `/akun/booking/${id}`} empty={<>Belum ada booking. <Link href="/paket" className="font-bold text-primary-700 underline">Cari paket umrah</Link></>} />;
}

export default function MyBookingsPage() {
  return (
    <>
      <PageBanner eyebrow="Akun saya" title="Booking saya" />
      <div className="mx-auto max-w-4xl px-4 py-6">
        <Suspense fallback={<div className="h-64 animate-pulse rounded-[12px] bg-white" />}><List /></Suspense>
      </div>
    </>
  );
}
