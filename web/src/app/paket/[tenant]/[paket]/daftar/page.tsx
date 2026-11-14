import Link from 'next/link';
import { Suspense } from 'react';
import { notFound } from 'next/navigation';
import { getPackage } from '@/lib/api';
import { waLink } from '@/lib/format';
import { WhatsAppButton } from '@/components/ui';

export const metadata = { title: 'Daftar paket' };

/** JMH-03 belum dibangun: booking online dijadwalkan Sprint 4 (Development Roadmap §3). */
export default function DaftarPage({ params }: PageProps<'/paket/[tenant]/[paket]/daftar'>) {
  return (
    <Suspense fallback={null}>
      <Body params={params} />
    </Suspense>
  );
}

async function Body({ params }: { params: PageProps<'/paket/[tenant]/[paket]/daftar'>['params'] }) {
  const { tenant, paket } = await params;
  const p = await getPackage(tenant, paket);
  if (!p) notFound();
  return (
    <div className="mx-auto max-w-xl px-4 py-12">
      <h1 className="text-2xl font-bold">Pendaftaran online segera hadir</h1>
      <p className="mt-3 text-ink-600">
        Booking online untuk <strong className="text-ink-900">{p.name}</strong> sedang dibangun (Sprint 4, Februari 2027).
        Sementara ini, hubungi {p.tenant.brand_name} langsung untuk mendaftar.
      </p>
      <WhatsAppButton className="mt-6 w-full" href={waLink(p.tenant.whatsapp, `Assalamualaikum, saya ingin mendaftar paket ${p.name}.`)} label={`Hubungi ${p.tenant.brand_name}`} />
      <Link href={`/paket/${tenant}/${paket}`} className="mt-4 block text-center font-semibold text-primary-700 underline">Kembali ke detail paket</Link>
    </div>
  );
}
