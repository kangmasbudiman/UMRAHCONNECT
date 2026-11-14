import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { PageBanner } from '@/components/ui';
import { DOC_TYPE, type PilgrimDoc } from '@/lib/booking-types';
import { tanggal } from '@/lib/format';
import { requireContexts, serverApi, ServerApiError } from '@/lib/session';
import { DocUpload } from './doc-upload';

export const metadata = { title: 'Dokumen jamaah' };

type Pilgrim = { id: string; full_name: string; nik_masked: string; birth_date: string; gender: 'L' | 'P'; passport_no_masked: string | null };
const REQUIRED: { type: PilgrimDoc['type']; hint: string }[] = [
  { type: 'ktp', hint: 'KTP elektronik yang masih berlaku' },
  { type: 'passport', hint: 'Halaman biodata paspor, berlaku minimal 6 bulan setelah kepulangan' },
  { type: 'photo', hint: 'Pas foto latar putih, wajah 80%' },
  { type: 'vaccine', hint: 'Kartu/ICV vaksin meningitis' },
];

async function Docs({ params }: PageProps<'/akun/jamaah/[id]'>) {
  const { id } = await params;
  await requireContexts(`/akun/jamaah/${id}`);
  let p: Pilgrim;
  let docs: PilgrimDoc[];
  try {
    [p, docs] = await Promise.all([
      serverApi<{ data: Pilgrim }>(`/me/pilgrims/${id}`).then((r) => r.data),
      serverApi<{ data: PilgrimDoc[] }>(`/me/pilgrims/${id}/documents`).then((r) => r.data),
    ]);
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
  return (
    <>
      <PageBanner eyebrow="Dokumen jamaah" title={p.full_name}>NIK {p.nik_masked} · lahir {tanggal(p.birth_date)}</PageBanner>
      <div className="mx-auto max-w-3xl space-y-4 px-4 py-6">
        <Link href="/akun/booking" className="font-semibold text-primary-700 hover:underline">← Booking saya</Link>
        <p className="text-ink-600">Dokumen dipakai PPIU untuk mengurus visa dan perjalanan. Hanya PPIU tempat jamaah ini terdaftar yang dapat melihatnya. JPG, PNG, atau PDF, maksimal 5 MB.</p>
        <ul className="card divide-y divide-border-subtle">
          {REQUIRED.map((r) => {
            const d = docs.find((x) => x.type === r.type);
            return (
              <li key={r.type} className="flex flex-wrap items-center gap-3 p-4">
                <div className="min-w-0 flex-1">
                  <p className="font-bold">{DOC_TYPE[r.type]}</p>
                  <p className="text-sm text-ink-600">{r.hint}</p>
                  {d && <p className="mt-1 text-sm">Terunggah {d.created_at ? tanggal(d.created_at.slice(0, 10)) : ''}{d.file_name && <> · {d.file_name}</>}</p>}
                  {d?.reviews.map((rv) => (
                    <p key={rv.tenant_id} className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                      {rv.tenant_name}: <StatusPill status={rv.status} />{rv.note && <span className="font-semibold text-danger">{rv.note}</span>}
                    </p>
                  ))}
                  {d && d.reviews.length === 0 && <p className="mt-1 text-sm text-ink-600">Menunggu diperiksa PPIU.</p>}
                </div>
                <DocUpload pilgrimId={p.id} type={r.type} label={DOC_TYPE[r.type]} hasDoc={!!d} />
              </li>
            );
          })}
        </ul>
      </div>
    </>
  );
}

export default function PilgrimDocsPage(props: PageProps<'/akun/jamaah/[id]'>) {
  return <Suspense fallback={<div className="mx-auto h-96 max-w-3xl px-4 py-10" />}><Docs {...props} /></Suspense>;
}
