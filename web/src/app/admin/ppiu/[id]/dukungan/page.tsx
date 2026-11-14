import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { BookingList } from '@/components/booking/booking-list';
import type { BookingSummary } from '@/lib/booking-types';
import type { TenantDashboard } from '@/lib/finance-types';
import { rupiah } from '@/lib/format';
import { requireContexts, serverApi, ServerApiError } from '@/lib/session';
import type { TenantFull } from '@/lib/tenant-types';
import { AdminHeader } from '../../../admin-nav';
import { IconHeading } from '@/components/stat-card';
import { IconTicket } from '@/components/icons';

export const metadata = { title: 'Akses dukungan' };

/** Tampilan baca-saja data PPIU selama sesi dukungan aktif (setiap pemuatan tercatat sebagai support.viewed). */
async function SupportView({ params, searchParams }: PageProps<'/admin/ppiu/[id]/dukungan'>) {
  const { id } = await params;
  const sp = await searchParams;
  const ctx = await requireContexts(`/admin/ppiu/${id}`);
  if (!ctx.platform_role) notFound();
  const sid = typeof sp.sesi === 'string' ? sp.sesi : '';
  const H = { 'X-Support-Session': sid };
  let data: [TenantFull, TenantDashboard, BookingSummary[]];
  try {
    data = await Promise.all([
      serverApi<{ data: TenantFull }>(`/tenants/${id}`, H).then((r) => r.data),
      serverApi<{ data: TenantDashboard }>(`/tenants/${id}/dashboard`, H).then((r) => r.data),
      serverApi<{ data: BookingSummary[] }>(`/tenants/${id}/bookings?limit=30`, H).then((r) => r.data),
    ]);
  } catch (e) {
    if (e instanceof ServerApiError && ['support_session_expired', 'not_found'].includes(e.code)) {
      return <Alert tone="info" title="Sesi dukungan tidak aktif">{e.message} <Link href={`/admin/ppiu/${id}`} className="font-bold text-primary-700 underline">Kembali ke halaman PPIU</Link></Alert>;
    }
    throw e;
  }
  const [t, dash, bookings] = data;
  return (
      <div className="space-y-4">
        <Alert tone="warning" title={`Mode dukungan baca-saja · ${t.brand_name}`}>Anda tidak dapat mengubah data. Akhiri sesi dari halaman PPIU bila sudah selesai.</Alert>
        <div className="flex flex-wrap items-center gap-3"><StatusPill status={t.verification_status} /><span className="text-ink-600">{t.legal_name} · {t.ppiu_license_no}</span></div>
        <ul className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {[['Bukti bayar menunggu', String(dash.pending_payments.count)], ['Pengajuan batal', String(dash.cancel_requests)], ['Penjualan bulan ini', rupiah(dash.sales_this_month)], ['Diterima bulan ini', rupiah(dash.received_this_month)]].map(([l, v]) => (
            <li key={l} className="card p-4"><p className="text-sm text-ink-600">{l}</p><p className="num text-xl font-extrabold">{v}</p></li>
          ))}
        </ul>
        <IconHeading icon={IconTicket} className="text-xl font-extrabold">Booking terbaru</IconHeading>
        <BookingList rows={bookings} hrefFor={() => '#'} showTenant={false} showBooker empty="Belum ada booking." />
      </div>
  );
}

export default function SupportViewPage(props: PageProps<'/admin/ppiu/[id]/dukungan'>) {
  return (
    <>
      <AdminHeader active="ppiu" title="Akses dukungan" sub="Data PPIU dibuka atas tiket dukungan, baca-saja, maksimal 60 menit." />
      <div className="mx-auto max-w-6xl px-4 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><SupportView {...props} /></Suspense></div>
    </>
  );
}
