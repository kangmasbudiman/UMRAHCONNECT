import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { IconCash, IconChart, IconCommission, IconReceipt, IconSparkles, IconTrend, IconWork } from '@/components/icons';
import { IconHeading, StatCard } from '@/components/stat-card';
import { planBullets, type Plan, type PlatformInvoice } from '@/lib/billing-types';
import { rupiah, tanggal } from '@/lib/format';
import { requireContexts, serverApi } from '@/lib/session';
import { AdminHeader } from '../admin-nav';
import { InvoiceActions, PlanPriceForm, RunBilling } from './revenue-actions';

export const metadata = { title: 'Pendapatan' };

type Revenue = {
  mrr: number; past_due: number; subscriptions: { plan_code: string; name: string; count: number; monthly: number }[];
  featured: { active: number; monthly: number }; fees_accrued: { count: number; amount: number };
  invoices: { outstanding: number; awaiting_confirmation: number; overdue: number; paid_last_12m: number }; last_period: string; last_period_billed: boolean;
};
const TABS = [{ s: '', label: 'Perlu tindakan' }, { s: 'paid', label: 'Lunas' }, { s: 'void', label: 'Dibatalkan' }];

/** Dasbor pendapatan platform (Super Admin): MRR, tagihan, konfirmasi pembayaran, harga paket. */
async function Revenue({ searchParams }: PageProps<'/admin/pendapatan'>) {
  const ctx = await requireContexts('/admin/pendapatan');
  if (ctx.platform_role !== 'super_admin') notFound();
  const sp = await searchParams;
  const tab = typeof sp.status === 'string' && TABS.some((t) => t.s === sp.status) ? sp.status : '';
  const [r, inv, plans] = await Promise.all([
    serverApi<{ data: Revenue }>('/platform/revenue').then((x) => x.data),
    serverApi<{ data: PlatformInvoice[] }>(`/platform/invoices?limit=50${tab ? `&status=${tab}` : ''}`).then((x) => x.data),
    serverApi<{ data: (Plan & { audience: string; is_public: boolean })[] }>('/platform/plans').then((x) => x.data),
  ]);
  const list = tab ? inv : inv.filter((i) => i.status === 'unpaid' || i.status === 'awaiting_confirmation');
  return (
    <>
      <ul className="grid grid-cols-2 gap-3 min-[1400px]:grid-cols-4">
        <li className="card p-4"><StatCard icon={IconTrend} tone="gold" label="Pendapatan berulang/bulan" value={rupiah(r.mrr)} sub="Langganan + Paket pilihan aktif" /></li>
        <li className="card p-4"><StatCard icon={IconReceipt} tone={r.invoices.outstanding ? 'warning' : 'primary'} label="Belum dibayar" value={rupiah(r.invoices.outstanding)} sub={`${r.invoices.awaiting_confirmation} menunggu konfirmasi · ${r.invoices.overdue} lewat jatuh tempo`} /></li>
        <li className="card p-4"><StatCard icon={IconCommission} label="Biaya transaksi terkumpul" value={rupiah(r.fees_accrued.amount)} sub={`${r.fees_accrued.count} booking, ditagih tgl 1`} /></li>
        <li className="card p-4"><StatCard icon={IconCash} tone="gold" label="Diterima 12 bulan" value={rupiah(r.invoices.paid_last_12m)} /></li>
      </ul>

      <section className="card space-y-3 p-5" aria-labelledby="sub-h">
        <IconHeading icon={IconWork} id="sub-h" className="text-lg font-extrabold">Pelanggan aktif</IconHeading>
        {r.subscriptions.length === 0 && r.featured.active === 0 ? <p className="text-ink-600">Belum ada langganan atau promosi berbayar.</p> : (
          <ul className="divide-y divide-border-subtle">
            {r.subscriptions.map((s) => <li key={s.plan_code} className="flex justify-between py-2"><span>{s.name} <span className="text-ink-600">× {s.count}</span></span><span className="num font-semibold">{rupiah(s.monthly)}/bulan</span></li>)}
            {r.featured.active > 0 && <li className="flex justify-between py-2"><span className="flex items-center gap-1"><IconSparkles className="h-4 w-4 text-gold-700" />Paket pilihan × {r.featured.active}</span><span className="num font-semibold">{rupiah(r.featured.monthly)}/bulan</span></li>}
          </ul>
        )}
        {r.past_due > 0 && <p className="text-sm font-semibold text-danger">{r.past_due} langganan dinonaktifkan karena telat bayar.</p>}
        <RunBilling period={r.last_period} billed={r.last_period_billed} />
      </section>

      <section className="space-y-3" aria-labelledby="inv-h">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <IconHeading icon={IconReceipt} id="inv-h" className="text-lg font-extrabold">Tagihan</IconHeading>
          <nav aria-label="Status tagihan" className="flex gap-2">
            {TABS.map((t) => <Link key={t.s} href={t.s ? `/admin/pendapatan?status=${t.s}` : '/admin/pendapatan'} aria-current={t.s === tab ? 'page' : undefined} className={`chip ${t.s === tab ? 'border-primary-700 bg-primary-700 text-white' : ''}`}>{t.label}</Link>)}
          </nav>
        </div>
        {list.length === 0 ? <p className="card p-6 text-center text-ink-600">Tidak ada tagihan di sini.</p> : (
          <ul className="space-y-3">
            {list.map((i) => (
              <li key={i.id} className={`card p-4 ${i.status === 'awaiting_confirmation' ? 'border-gold-500' : ''}`}>
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="font-bold">{i.billable_name} <span className="text-sm font-normal text-ink-600">· {i.billable_type === 'tenant' ? 'PPIU' : 'Agen'} · {i.number} · {i.period}</span></p>
                    <p className="text-sm text-ink-600">{i.items.map((it) => it.description + (it.quantity > 1 ? ` × ${it.quantity}` : '')).join(' · ')}</p>
                    <p className="text-sm text-ink-600">Jatuh tempo {tanggal(i.due_on)}{i.payer_note && <> · catatan: {i.payer_note}</>}</p>
                  </div>
                  <div className="text-right"><p className="num text-lg font-extrabold text-primary-800">{rupiah(i.total)}</p><StatusPill status={i.status === 'paid' ? 'paid' : i.status === 'void' ? 'cancelled' : i.status === 'awaiting_confirmation' ? 'pending' : 'unpaid'} /></div>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-3">
                  {i.proof_url && <a href={i.proof_url} target="_blank" rel="noopener" className="text-sm font-semibold text-primary-700 underline">Lihat bukti transfer</a>}
                  <InvoiceActions id={i.id} status={i.status} />
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="card space-y-3 p-5" aria-labelledby="plan-h">
        <IconHeading icon={IconChart} id="plan-h" className="text-lg font-extrabold">Harga paket</IconHeading>
        <ul className="divide-y divide-border-subtle">
          {plans.map((p) => (
            <li key={p.code} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div><p className="font-semibold">{p.name}{!p.is_public && <span className="text-sm font-normal text-ink-600"> · kontrak, tidak tampil publik</span>}</p><p className="text-sm text-ink-600">{planBullets(p).slice(0, 2).join(' · ')}</p></div>
              <PlanPriceForm code={p.code} price={p.price_monthly} />
            </li>
          ))}
        </ul>
        <p className="text-sm text-ink-600">Biaya per booking lunas, harga dan slot Paket pilihan, serta rekening penerimaan diatur di <Link href="/admin/pengaturan" className="font-semibold text-primary-700 underline">Pengaturan</Link>.</p>
      </section>
    </>
  );
}

export default function RevenuePage(props: PageProps<'/admin/pendapatan'>) {
  return (
    <>
      <AdminHeader active="pendapatan" title="Pendapatan" sub="Langganan, Paket pilihan, dan biaya per transaksi. Semua ditagih ke PPIU/agen, tidak pernah ke jamaah." />
      <div className="space-y-6 px-4 sm:px-6 lg:px-8 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><Revenue {...props} /></Suspense></div>
    </>
  );
}
