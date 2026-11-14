import Link from 'next/link';
import { notFound, redirect } from 'next/navigation';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { BookingWizard } from '@/components/booking/booking-wizard';
import type { PackageDetail } from '@/lib/api';
import type { SavedPilgrim } from '@/lib/booking-types';
import { activeReferral } from '@/lib/referral';
import { getContexts, serverApi, ServerApiError } from '@/lib/session';

export const metadata = { title: 'Daftar paket' };

/** JMH-03..05: booking online 3 langkah. Data keberangkatan diambil segar (tanpa cache) agar sisa kursi akurat. */
async function Body({ params, searchParams }: PageProps<'/paket/[tenant]/[paket]/daftar'>) {
  const { tenant, paket } = await params;
  const sp = await searchParams;
  const here = `/paket/${tenant}/${paket}/daftar${typeof sp.berangkat === 'string' ? `?berangkat=${sp.berangkat}` : ''}`;
  const ctx = await getContexts();
  if (!ctx) redirect(`/masuk?next=${encodeURIComponent(here)}`);

  let p: PackageDetail;
  try {
    p = (await serverApi<{ data: PackageDetail }>(`/public/packages/${tenant}/${paket}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  if (!ctx.contact_verified) {
    return (
      <Alert tone="warning" title="Verifikasi kontak Anda dulu">
        Booking memerlukan email atau nomor HP yang sudah diverifikasi agar kode booking dan invoice sampai ke Anda.{' '}
        <Link href={`/verifikasi?next=${encodeURIComponent(here)}`} className="font-bold text-primary-700 underline">Verifikasi sekarang</Link>
      </Alert>
    );
  }
  const [pilgrims, referral] = await Promise.all([
    serverApi<{ data: SavedPilgrim[] }>('/me/pilgrims?limit=100').then((r) => r.data),
    activeReferral(tenant),
  ]);

  return (
    <>
      <nav aria-label="Breadcrumb" className="mb-3 text-sm text-ink-600">
        <Link href={`/paket/${tenant}/${paket}`} className="underline hover:text-primary-700">{p.name}</Link> <span aria-hidden="true">›</span> Daftar
      </nav>
      <h1 className="mb-5 text-2xl font-extrabold md:text-3xl">Daftar {p.name}</h1>
      <BookingWizard
        mode="jamaah"
        endpoint="/me/bookings"
        successHref="/akun/booking/{id}?baru=1"
        packageName={p.name}
        tenantName={p.tenant.brand_name}
        cancellationTerms={p.cancellation_terms}
        departures={p.departures}
        initialDepartureId={typeof sp.berangkat === 'string' ? sp.berangkat : null}
        savedPilgrims={pilgrims}
        referral={referral}
      />
    </>
  );
}

export default function DaftarPage(props: PageProps<'/paket/[tenant]/[paket]/daftar'>) {
  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}>
        <Body {...props} />
      </Suspense>
    </div>
  );
}
