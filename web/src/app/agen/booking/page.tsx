import Link from 'next/link';
import { Suspense } from 'react';
import { BookingList } from '@/components/booking/booking-list';
import type { BookingSummary } from '@/lib/booking-types';
import { serverApi } from '@/lib/session';
import { AgentHeader, requireAgent } from '../agent-shell';

export const metadata = { title: 'Booking agen' };

async function List() {
  const ctx = await requireAgent('/agen/booking');
  const rows = (await serverApi<{ data: BookingSummary[] }>('/agent/bookings?limit=50')).data;
  return (
    <>
      <AgentHeader ctx={ctx} active="booking" title="Booking jamaah" sub="Booking yang tercatat atas nama Anda: dibuat sendiri, lewat link, atau kode agen." />
      <div className="mx-auto max-w-5xl space-y-4 px-4 py-6">
        <div className="flex justify-end"><Link href="/agen/booking/baru" className="btn btn-gold">+ Booking untuk jamaah</Link></div>
        <BookingList rows={rows} hrefFor={(id) => `/agen/booking/${id}`} showBooker empty="Belum ada booking. Bagikan link paket atau buat booking untuk jamaah Anda." />
      </div>
    </>
  );
}

export default function AgentBookingsPage() {
  return <Suspense fallback={<div className="mx-auto max-w-5xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><List /></Suspense>;
}
