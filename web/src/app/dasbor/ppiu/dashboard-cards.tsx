import Link from 'next/link';
import { StatusPill } from '@/components/form';
import { IconCalendarClock, IconCash, IconChart, IconCommission, IconPayout, IconRefresh, IconTicket, IconTimer, IconUndo, IconWarning, IconMoney } from '@/components/icons';
import { IconHeading, StatCard } from '@/components/stat-card';
import type { TenantDashboard } from '@/lib/finance-types';
import { rupiah, tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';

/** PPIU-04 Dasbor ringkas (FR-PPIU-15): yang perlu ditindaklanjuti hari ini + kursi keberangkatan terdekat. */
export async function DashboardCards({ tenantId }: { tenantId: string }) {
  const d = (await serverApi<{ data: TenantDashboard }>(`/tenants/${tenantId}/dashboard`)).data;
  const base = `/dasbor/ppiu/${tenantId}`;
  const todo = [
    { n: d.pending_payments.count, label: 'Bukti bayar menunggu verifikasi', sub: rupiah(d.pending_payments.amount), href: `${base}/pembayaran`, Icon: IconMoney },
    { n: d.cancel_requests, label: 'Pengajuan pembatalan', href: `${base}/booking`, Icon: IconUndo },
    { n: d.commissions_to_approve.count, label: 'Komisi menunggu persetujuan', sub: rupiah(d.commissions_to_approve.amount), href: `${base}/komisi`, Icon: IconCommission },
    { n: d.payout_requests, label: 'Pencairan komisi diajukan agen', href: `${base}/komisi`, Icon: IconPayout },
    { n: d.refunds_pending.count, label: 'Refund belum diproses', sub: rupiah(d.refunds_pending.amount), href: `${base}/booking?status=cancelled`, Icon: IconRefresh },
  ];
  const active = (d.bookings_by_status.awaiting_dp ?? 0) + (d.bookings_by_status.dp_verified ?? 0) + (d.bookings_by_status.fully_paid ?? 0);

  return (
    <section className="space-y-4" aria-labelledby="dash-h">
      <h2 id="dash-h" className="sr-only">Ringkasan</h2>
      <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {([['Booking aktif', String(active), IconTicket, 'primary'], ['Booking bulan ini', String(d.bookings_this_month), IconTimer, 'primary'], ['Penjualan bulan ini', rupiah(d.sales_this_month), IconChart, 'gold'], ['Diterima bulan ini', rupiah(d.received_this_month), IconCash, 'gold']] as const).map(([l, v, I, t]) => (
          <li key={l} className="card p-4"><StatCard icon={I} label={l} value={v} tone={t} /></li>
        ))}
      </ul>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="card p-5">
          <IconHeading icon={IconWarning} as="h3" className="text-lg font-extrabold">Perlu tindakan</IconHeading>
          <ul className="mt-2 divide-y divide-border-subtle">
            {todo.map((t) => (
              <li key={t.label}>
                <Link href={t.href} className="flex min-h-12 items-center justify-between gap-3 py-2 hover:text-primary-700">
                  <span className="flex items-center gap-2"><t.Icon className="h-5 w-5 text-primary-600" /><span>{t.label}{t.sub && t.n > 0 && <span className="num text-sm text-ink-600"> · {t.sub}</span>}</span></span>
                  <span className={`num min-w-8 rounded-full px-2.5 py-0.5 text-center text-sm font-bold ${t.n > 0 ? 'bg-warning-bg text-warning' : 'bg-surface text-ink-600'}`}>{t.n}</span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
        <div className="card p-5">
          <IconHeading icon={IconCalendarClock} as="h3" className="text-lg font-extrabold">Keberangkatan terdekat</IconHeading>
          {d.upcoming_departures.length === 0 ? <p className="mt-2 text-ink-600">Belum ada keberangkatan terjadwal.</p> : (
            <ul className="mt-2 space-y-3">
              {d.upcoming_departures.map((x) => {
                const pct = Math.round(((x.seats_held + x.seats_confirmed) / x.quota) * 100);
                return (
                  <li key={x.id}>
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                      <Link href={`${base}/keberangkatan/${x.id}`} className="font-semibold hover:text-primary-700 hover:underline">{x.package_name} · {tanggal(x.departure_date)}</Link>
                      <StatusPill status={x.sales_status} />
                    </div>
                    <div className="mt-1 h-2.5 overflow-hidden rounded-full bg-surface" role="img" aria-label={`${x.seats_confirmed} terkonfirmasi, ${x.seats_held} ditahan dari ${x.quota} kursi`}>
                      <div className="flex h-full" style={{ width: `${Math.min(100, pct)}%` }}>
                        <div className="h-full bg-primary-700" style={{ width: `${(x.seats_confirmed / Math.max(1, x.seats_held + x.seats_confirmed)) * 100}%` }} />
                        <div className="h-full flex-1 bg-gold-500" />
                      </div>
                    </div>
                    <p className="num text-xs text-ink-600">{x.seats_confirmed} terkonfirmasi · {x.seats_held} ditahan · {x.seats_remaining} sisa dari {x.quota}</p>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </div>
    </section>
  );
}
