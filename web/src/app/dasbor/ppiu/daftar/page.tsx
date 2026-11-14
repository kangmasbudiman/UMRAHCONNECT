import Link from 'next/link';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { requireContexts } from '@/lib/session';
import { TenantProfileForm } from '../tenant-forms';
import { IconHeading } from '@/components/stat-card';
import { IconOffice } from '@/components/icons';

export const metadata = { title: 'Daftarkan PPIU' };

const STEPS = ['Profil PPIU', 'Dokumen legalitas', 'Rekening resmi', 'Ajukan verifikasi'];

async function Content() {
  const ctx = await requireContexts('/dasbor/ppiu/daftar');
  if (!ctx.contact_verified) {
    return (
      <Alert tone="warning" title="Verifikasi kontak Anda dulu">
        Pendaftaran PPIU memerlukan email atau nomor HP yang sudah diverifikasi.{' '}
        <Link href="/verifikasi?next=/dasbor/ppiu/daftar" className="font-bold text-primary-700 underline">Verifikasi sekarang</Link>
      </Alert>
    );
  }
  return <TenantProfileForm tenant={null} />;
}

export default function RegisterTenantPage() {
  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto max-w-4xl px-4 py-10">
          <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Untuk travel berizin</p>
          <h1 className="mt-1 text-3xl font-extrabold">Daftarkan PPIU Anda</h1>
          <p className="mt-2 max-w-2xl text-white/85">Paket Anda tampil di marketplace setelah tim UMRAH CONNECT memverifikasi izin dan legalitas. Jamaah tetap membayar langsung ke rekening resmi PPIU.</p>
          <ol className="mt-6 grid gap-2 sm:grid-cols-4">
            {STEPS.map((s, i) => (
              <li key={s} className={`rounded-[10px] px-3 py-2 text-sm font-semibold ${i === 0 ? 'bg-gold-500 text-ink-900' : 'bg-white/10 text-white/85'}`}>{i + 1}. {s}</li>
            ))}
          </ol>
        </div>
      </section>
      <div className="mx-auto max-w-4xl px-4 py-8">
        <div className="card p-5 md:p-6">
          <IconHeading icon={IconOffice} className="text-xl font-extrabold">1. Profil PPIU</IconHeading>
          <p className="mt-1 text-ink-600">Isi sesuai dokumen resmi. Dokumen dan rekening diisi di langkah berikutnya.</p>
          <div className="mt-5">
            <Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-surface" />}><Content /></Suspense>
          </div>
        </div>
      </div>
    </>
  );
}
