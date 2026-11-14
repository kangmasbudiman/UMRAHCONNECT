import Link from 'next/link';
import { redirect } from 'next/navigation';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { requireContexts } from '@/lib/session';
import { AgentProfileForm } from '../agent-forms';

export const metadata = { title: 'Daftar sebagai agen' };

async function Content() {
  const ctx = await requireContexts('/agen/daftar');
  if (ctx.agent) redirect('/agen');
  if (!ctx.contact_verified) {
    return (
      <Alert tone="warning" title="Verifikasi kontak Anda dulu">
        Pendaftaran agen memerlukan email atau nomor HP yang sudah diverifikasi.{' '}
        <Link href="/verifikasi?next=/agen/daftar" className="font-bold text-primary-700 underline">Verifikasi sekarang</Link>
      </Alert>
    );
  }
  return <AgentProfileForm />;
}

export default function AgentRegisterPage() {
  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto max-w-4xl px-4 py-10">
          <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Untuk agen pemasaran</p>
          <h1 className="mt-1 text-3xl font-extrabold">Jadi agen UMRAH CONNECT</h1>
          <p className="mt-2 max-w-2xl text-white/85">Bermitra dengan PPIU terverifikasi, bagikan link paket ke jamaah, dan terima komisi resmi dari PPIU. Harga untuk jamaah tetap harga resmi PPIU.</p>
          <ol className="mt-6 grid gap-2 sm:grid-cols-3">
            {['Lengkapi data agen', 'Ajukan kemitraan ke PPIU', 'Bagikan link & terima komisi'].map((s, i) => (
              <li key={s} className={`rounded-[10px] px-3 py-2 text-sm font-semibold ${i === 0 ? 'bg-gold-500 text-ink-900' : 'bg-white/10 text-white/85'}`}>{i + 1}. {s}</li>
            ))}
          </ol>
        </div>
      </section>
      <div className="mx-auto max-w-4xl px-4 py-8">
        <div className="card p-5 md:p-6">
          <h2 className="text-xl font-extrabold">Data agen</h2>
          <p className="mt-1 text-ink-600">Satu orang hanya bisa punya satu akun agen (dicek dari NIK). Data identitas disimpan terenkripsi.</p>
          <div className="mt-5"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-surface" />}><Content /></Suspense></div>
        </div>
      </div>
    </>
  );
}
