import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { tanggal } from '@/lib/format';
import { serverApi, ServerApiError } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../../tenant-shell';
import { MilestoneForm, type Milestone } from './milestone-form';

export const metadata = { title: 'Lacak Umrah · keberangkatan' };

type Resp = { departure: { id: string; departure_date: string; return_date: string; departure_city: string; status: string; package: { id: string; name: string } }; active_bookings: number; milestones: Milestone[] };

/** Lacak Umrah sisi PPIU: perbarui tahap persiapan satu keberangkatan; semua pemesan aktif melihatnya di booking mereka. */
async function Milestones({ params }: PageProps<'/dasbor/ppiu/[tenant]/keberangkatan/[departure]'>) {
  const { tenant: id, departure } = await params;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/keberangkatan/${departure}`, 'package.view');
  if (!isTenantPage(page)) return page;
  let d: Resp;
  try {
    d = (await serverApi<{ data: Resp }>(`/tenants/${id}/departures/${departure}/milestones`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  const canEdit = page.membership.permissions.includes('departure.manage') && d.departure.status !== 'cancelled';
  const done = d.milestones.filter((m) => m.status === 'done').length;
  return (
    <>
      <TenantHeader page={page} active="paket" title={`Lacak Umrah · ${tanggal(d.departure.departure_date)}`}
        back={{ href: `/dasbor/ppiu/${id}/paket/${d.departure.package.id}`, label: d.departure.package.name }}
        sub={`${d.departure.package.name} · dari ${d.departure.departure_city} · ${d.active_bookings} booking aktif`} />
      <div className="mx-auto max-w-4xl space-y-4 px-4 py-6">
        <Alert tone="info" title={`${done} dari ${d.milestones.length} tahap selesai`}>
          Jamaah melihat tahap ini di halaman booking dan di <Link href="/lacak" className="font-bold underline">halaman Lacak Umrah</Link>. Saat sebuah tahap ditandai selesai, setiap pemesan aktif diberi tahu lewat notifikasi dan email.
          Tahap DP, dokumen, dan lunas dihitung otomatis per booking.
        </Alert>
        <div className="space-y-3">
          {d.milestones.map((m) => <MilestoneForm key={m.type} tenantId={id} departureId={d.departure.id} m={m} canEdit={canEdit} activeBookings={d.active_bookings} />)}
        </div>
      </div>
    </>
  );
}

export default function MilestonesPage(props: PageProps<'/dasbor/ppiu/[tenant]/keberangkatan/[departure]'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Milestones {...props} /></Suspense>;
}
