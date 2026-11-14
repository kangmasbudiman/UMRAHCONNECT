import Link from 'next/link';
import { Suspense } from 'react';
import { IconInbox, IconPlus } from '@/components/icons';
import { PageBanner } from '@/components/ui';
import { requireContexts, serverApi } from '@/lib/session';
import { PlanCard, type Plan } from './plan-card';

export const metadata = { title: 'Tabungan umrah saya' };

async function Plans() {
  await requireContexts('/akun/tabungan');
  const plans = (await serverApi<{ data: Plan[] }>('/me/savings-plans')).data;
  if (plans.length === 0) {
    return (
      <div className="card p-8 text-center text-ink-600">
        <IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />
        <p>Belum ada rencana tabungan.</p>
        <Link href="/tabungan" className="btn btn-primary mt-4"><IconPlus className="h-5 w-5" />Buat rencana</Link>
      </div>
    );
  }
  return (
    <>
      <div className="space-y-4">{plans.map((p) => <PlanCard key={p.id} plan={p} />)}</div>
      <Link href="/tabungan" className="btn btn-outline"><IconPlus className="h-5 w-5" />Rencana baru</Link>
    </>
  );
}

export default function MySavingsPage() {
  return (
    <>
      <PageBanner eyebrow="Akun saya" title="Tabungan umrah">Catat setoran dan pantau target. Uang tetap Anda simpan sendiri; UMRAH CONNECT hanya mencatat dan mengingatkan.</PageBanner>
      <div className="mx-auto max-w-3xl space-y-4 px-4 py-6"><Suspense fallback={<div className="card h-64 animate-pulse" />}><Plans /></Suspense></div>
    </>
  );
}
