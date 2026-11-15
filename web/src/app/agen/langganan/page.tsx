import Link from 'next/link';
import { Suspense } from 'react';
import { InvoiceList, PlanCards } from '@/components/billing';
import { CopyButton } from '@/components/booking/countdown';
import { IconExternal, IconReceipt, IconWork } from '@/components/icons';
import { IconHeading } from '@/components/stat-card';
import type { AgentBilling } from '@/lib/billing-types';
import { serverApi } from '@/lib/session';
import { AgentHeader, requireAgent } from '../agent-shell';

export const metadata = { title: 'Langganan agen' };

async function Billing() {
  const ctx = await requireAgent('/agen/langganan');
  const b = (await serverApi<{ data: AgentBilling }>('/agent/billing')).data;
  return (
    <>
      <AgentHeader ctx={ctx} active="langganan" title="Langganan" sub="Agen Free sudah cukup untuk mulai. Premium memberi Anda halaman agen pribadi untuk dibagikan." />
      <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
        {b.public_page_url && (
          <div className="card flex flex-wrap items-center justify-between gap-3 border-gold-500 p-4">
            <div>
              <p className="font-bold">Halaman agen Anda</p>
              <p className="num text-sm text-ink-600">{b.public_page_url}</p>
            </div>
            <div className="flex gap-2">
              <CopyButton text={b.public_page_url} label="Salin tautan" />
              <Link href={`/a/${ctx.agent.code}`} target="_blank" className="btn btn-outline min-h-10 px-3 text-sm"><IconExternal className="h-4 w-4" />Buka</Link>
            </div>
          </div>
        )}
        <section aria-labelledby="pl-h" className="space-y-3">
          <IconHeading icon={IconWork} id="pl-h">Paket langganan</IconHeading>
          <PlanCards plans={b.plans} current={b.plan.code} endpoint="/agent/billing/plan" canManage pastDue={b.subscription?.status === 'past_due'} />
        </section>
        <section className="card space-y-3 p-5" aria-labelledby="inv-h">
          <IconHeading icon={IconReceipt} id="inv-h">Tagihan</IconHeading>
          <InvoiceList invoices={b.invoices} confirmBase="/agent/platform-invoices" canPay bankAccount={b.bank_account} />
        </section>
      </div>
    </>
  );
}

export default function AgentBillingPage() {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Billing /></Suspense>;
}
