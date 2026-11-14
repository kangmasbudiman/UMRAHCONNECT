import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { BookingWizard } from '@/components/booking/booking-wizard';
import { Cover } from '@/components/ui';
import type { PackageDetail } from '@/lib/api';
import type { AgentPackage } from '@/lib/agent-types';
import { rupiah } from '@/lib/format';
import { serverApi, ServerApiError } from '@/lib/session';
import { AgentHeader, requireAgent } from '../../agent-shell';
import { IconInbox } from '@/components/icons';

export const metadata = { title: 'Booking untuk jamaah' };

/** AGT-08 Booking oleh agen (FR-AGT-08): pilih paket mitra, lalu wizard dengan data pemesan. */
async function Content({ searchParams }: PageProps<'/agen/booking/baru'>) {
  const sp = await searchParams;
  const ctx = await requireAgent('/agen/booking/baru');
  const tenant = typeof sp.tenant === 'string' ? sp.tenant : null;
  const paket = typeof sp.paket === 'string' ? sp.paket : null;

  if (!tenant || !paket) {
    const pkgs = (await serverApi<{ data: AgentPackage[] }>('/agent/packages?limit=100')).data;
    return (
      <>
        <AgentHeader ctx={ctx} active="booking" title="Booking untuk jamaah" sub="Pilih paket dari PPIU mitra Anda." />
        <div className="mx-auto max-w-5xl px-4 py-6">
          {pkgs.length === 0 ? <div className="card p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />Belum ada paket dari PPIU mitra aktif. <Link href="/agen/mitra" className="font-bold text-primary-700 underline">Ajukan kemitraan</Link></div> : (
            <ul className="grid gap-4 md:grid-cols-2">
              {pkgs.map((p) => (
                <li key={p.id}>
                  <Link href={`/agen/booking/baru?tenant=${p.tenant.slug}&paket=${p.slug}`} className="card group grid overflow-hidden hover:border-primary-600 sm:grid-cols-[120px_1fr]">
                    <Cover url={p.cover_image_url} title={p.name} className="h-24 sm:h-full" />
                    <div className="p-4">
                      <p className="text-sm font-semibold text-gold-700">{p.tenant.brand_name}</p>
                      <p className="font-bold group-hover:text-primary-700">{p.name}</p>
                      <p className="text-sm text-ink-600">Mulai {p.price_from_amount ? rupiah(p.price_from_amount) : '—'} · komisi {p.commission_scheme?.label ?? '—'}</p>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </>
    );
  }

  let p: PackageDetail;
  try {
    p = (await serverApi<{ data: PackageDetail }>(`/public/packages/${tenant}/${paket}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  return (
    <>
      <AgentHeader ctx={ctx} active="booking" title={`Booking: ${p.name}`} sub={<Link href="/agen/booking/baru" className="underline">Ganti paket</Link>} />
      <div className="mx-auto max-w-6xl px-4 py-6">
        <BookingWizard mode="agent" endpoint="/agent/bookings" successHref="/agen/booking/{id}?baru=1"
          packageName={p.name} tenantName={p.tenant.brand_name} cancellationTerms={p.cancellation_terms} departures={p.departures}
          agentNote={`Booking ini tercatat atas nama Anda (kode ${ctx.agent.code}). Jamaah membayar langsung ke rekening resmi ${p.tenant.brand_name}.`} />
      </div>
    </>
  );
}

export default function AgentNewBookingPage(props: PageProps<'/agen/booking/baru'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Content {...props} /></Suspense>;
}
