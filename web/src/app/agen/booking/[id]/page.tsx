import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { BookingDetail } from '@/components/booking/booking-detail';
import type { Booking } from '@/lib/booking-types';
import { serverApi, ServerApiError } from '@/lib/session';
import { AgentHeader, requireAgent } from '../../agent-shell';

export const metadata = { title: 'Detail booking' };

async function Detail({ params, searchParams }: PageProps<'/agen/booking/[id]'>) {
  const { id } = await params;
  const sp = await searchParams;
  const ctx = await requireAgent(`/agen/booking/${id}`);
  let b: Booking;
  try {
    b = (await serverApi<{ data: Booking }>(`/agent/bookings/${id}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  return (
    <>
      <AgentHeader ctx={ctx} active="booking" title={`Booking ${b.code}`} sub={<Link href="/agen/booking" className="underline">Kembali ke daftar booking</Link>} />
      <div className="mx-auto max-w-6xl px-4 py-6"><BookingDetail b={b} view="agent" isNew={sp.baru === '1'} /></div>
    </>
  );
}

export default function AgentBookingPage(props: PageProps<'/agen/booking/[id]'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Detail {...props} /></Suspense>;
}
