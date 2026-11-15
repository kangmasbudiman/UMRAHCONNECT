import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { FeaturedPanel, InvoiceList, PlanCards } from '@/components/billing';
import { IconReceipt, IconSparkles, IconTeam, IconWork } from '@/components/icons';
import { IconHeading, StatCard } from '@/components/stat-card';
import type { TenantBilling } from '@/lib/billing-types';
import { rupiah } from '@/lib/format';
import { serverApi } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';

export const metadata = { title: 'Langganan' };

/** Langganan PPIU: paket, Paket pilihan, dan tagihan platform. Tagihan hanya ke PPIU, tidak pernah ke jamaah. */
async function Billing({ params }: PageProps<'/dasbor/ppiu/[tenant]/langganan'>) {
  const { tenant: id } = await params;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/langganan`, 'billing.view');
  if (!isTenantPage(page)) return page;
  const perms = page.membership.permissions;
  const canManage = perms.includes('billing.manage');
  const [b, pkgs] = await Promise.all([
    serverApi<{ data: TenantBilling }>(`/tenants/${id}/billing`).then((r) => r.data),
    canManage ? serverApi<{ data: { id: string; name: string; status: string }[] }>(`/tenants/${id}/packages?limit=100`).then((r) => r.data.filter((p) => p.status === 'published')).catch(() => []) : Promise.resolve([]),
  ]);
  const pastDue = b.subscription?.status === 'past_due';
  const outstanding = b.invoices.filter((i) => i.status === 'unpaid' || i.status === 'awaiting_confirmation').reduce((a, i) => a + i.total, 0);
  return (
    <>
      <TenantHeader page={page} active="langganan" sub="Fitur dasar gratis selamanya. Bayar hanya untuk fitur tambahan dan promosi yang Anda pakai." />
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        {pastDue && <Alert tone="danger" title="Ada tagihan lewat jatuh tempo">Fitur berbayar dinonaktifkan sementara dan promosi dihentikan. Data Anda tetap aman; lunasi tagihan di bawah agar fitur aktif kembali.</Alert>}
        <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <li className="card p-4"><StatCard icon={IconWork} label="Paket saat ini" value={b.plan.name} sub={b.subscription?.price_override != null ? `Harga kontrak ${rupiah(b.subscription.price_override)}/bulan` : b.plan.price_monthly ? `${rupiah(b.plan.price_monthly)}/bulan` : 'Gratis'} /></li>
          <li className="card p-4"><StatCard icon={IconTeam} label="Anggota tim" value={`${b.usage.members} / ${b.usage.max_members}`} /></li>
          <li className="card p-4"><StatCard icon={IconSparkles} tone="gold" label="Paket dipromosikan" value={String(b.featured.placements.filter((p) => p.status === 'active').length)} /></li>
          <li className="card p-4"><StatCard icon={IconReceipt} tone={outstanding ? 'warning' : 'primary'} label="Tagihan belum lunas" value={rupiah(outstanding)} /></li>
        </ul>

        <section aria-labelledby="pl-h" className="space-y-3">
          <IconHeading icon={IconWork} id="pl-h">Paket langganan</IconHeading>
          <PlanCards plans={b.plans} current={b.plan.code} endpoint={`/tenants/${id}/billing/plan`} canManage={canManage} pastDue={pastDue} />
          <p className="text-sm text-ink-600">
            Biaya teknologi per booking lunas: <strong className="num">{b.transaction_fee_amount ? rupiah(b.transaction_fee_amount) : 'gratis (masa pilot)'}</strong>
            {b.accrued_fees.count > 0 && <> · terkumpul bulan ini {b.accrued_fees.count} booking ({rupiah(b.accrued_fees.amount)}), ditagih tanggal 1</>}. Jamaah tidak dikenai biaya ini.
          </p>
        </section>

        <section className="card space-y-3 p-5" aria-labelledby="ft-h">
          <IconHeading icon={IconSparkles} id="ft-h">Paket pilihan (promosi)</IconHeading>
          <FeaturedPanel tenantId={id} placements={b.featured.placements} packages={pkgs} price={b.featured.price_monthly} slotsLeft={b.featured.slots_left} canManage={canManage} pastDue={pastDue} />
        </section>

        <section className="card space-y-3 p-5" aria-labelledby="inv-h">
          <IconHeading icon={IconReceipt} id="inv-h">Tagihan platform</IconHeading>
          <InvoiceList invoices={b.invoices} confirmBase={`/tenants/${id}/platform-invoices`} canPay={perms.includes('billing.pay')} bankAccount={b.bank_account} />
        </section>
      </div>
    </>
  );
}

export default function TenantBillingPage(props: PageProps<'/dasbor/ppiu/[tenant]/langganan'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Billing {...props} /></Suspense>;
}
