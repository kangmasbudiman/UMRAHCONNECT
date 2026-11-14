import Link from 'next/link';
import { Suspense } from 'react';
import { BookingList } from '@/components/booking/booking-list';
import { BOOKING_STATUS, type BookingStatus, type BookingSummary } from '@/lib/booking-types';
import { serverApi } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';

export const metadata = { title: 'Booking PPIU' };

const TABS: { s: '' | BookingStatus; label: string }[] = [
  { s: '', label: 'Semua' }, { s: 'awaiting_dp', label: BOOKING_STATUS.awaiting_dp }, { s: 'dp_verified', label: BOOKING_STATUS.dp_verified },
  { s: 'fully_paid', label: BOOKING_STATUS.fully_paid }, { s: 'expired', label: BOOKING_STATUS.expired }, { s: 'cancelled', label: BOOKING_STATUS.cancelled },
];

async function List({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/booking'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/booking`, 'booking.view');
  if (!isTenantPage(page)) return page;
  const status = typeof sp.status === 'string' && TABS.some((t) => t.s === sp.status) ? sp.status : '';
  const q = typeof sp.q === 'string' ? sp.q.slice(0, 100) : '';
  const qs = new URLSearchParams({ limit: '100', ...(status ? { 'filter[status]': status } : {}), ...(q ? { q } : {}) });
  const res = await serverApi<{ data: BookingSummary[]; meta: { counts: Record<string, number> } }>(`/tenants/${id}/bookings?${qs}`);
  const total = Object.values(res.meta.counts).reduce((a, b) => a + b, 0);

  return (
    <>
      <TenantHeader page={page} active="booking" sub="Semua booking dari marketplace, agen, dan walk-in." />
      <div className="mx-auto max-w-6xl space-y-4 px-4 py-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <nav aria-label="Status booking" className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
            {TABS.map((t) => {
              const n = t.s ? (res.meta.counts[t.s] ?? 0) : total;
              const on = t.s === status;
              return (
                <Link key={t.s || 'all'} href={`/dasbor/ppiu/${id}/booking${t.s ? `?status=${t.s}` : ''}`} aria-current={on ? 'page' : undefined}
                  className={`chip shrink-0 ${on ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
                  {t.label} <span className={`num rounded-full px-2 text-xs ${on ? 'bg-white/20' : 'bg-surface'}`}>{n}</span>
                </Link>
              );
            })}
          </nav>
          {page.membership.permissions.includes('booking.create') && <Link href={`/dasbor/ppiu/${id}/booking/baru`} className="btn btn-gold">+ Booking walk-in</Link>}
        </div>
        <form role="search" className="flex gap-2">
          {status && <input type="hidden" name="status" value={status} />}
          <label htmlFor="q" className="sr-only">Cari booking</label>
          <input id="q" name="q" defaultValue={q} className="input max-w-md" placeholder="Kode booking atau nama jamaah" />
          <button className="btn btn-outline">Cari</button>
        </form>
        <BookingList rows={res.data} hrefFor={(b) => `/dasbor/ppiu/${id}/booking/${b}`} showTenant={false} showBooker empty="Belum ada booking." />
      </div>
    </>
  );
}

export default function TenantBookingsPage(props: PageProps<'/dasbor/ppiu/[tenant]/booking'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><List {...props} /></Suspense>;
}
