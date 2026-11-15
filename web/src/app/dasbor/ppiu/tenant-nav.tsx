import Link from 'next/link';
import { IconChart, IconCommission, IconDashboard, IconHandshake, IconMoney, IconPackage, IconStar, IconTicket, IconWork } from '@/components/icons';

export type TenantTab = 'ringkasan' | 'paket' | 'booking' | 'pembayaran' | 'komisi' | 'laporan' | 'agen' | 'ulasan' | 'langganan';

/** Navigasi area PPIU; setiap tab hanya untuk peran yang punya permission-nya. */
export function TenantNav({ tenantId, active, permissions }: { tenantId: string; active: TenantTab; permissions: string[] }) {
  const base = `/dasbor/ppiu/${tenantId}`;
  const items: { key: TenantTab; href: string; label: string; show: boolean; Icon: typeof IconDashboard }[] = [
    { key: 'ringkasan', href: base, label: 'Ringkasan', show: true, Icon: IconDashboard },
    { key: 'paket', href: `${base}/paket`, label: 'Paket', show: permissions.includes('package.view'), Icon: IconPackage },
    { key: 'booking', href: `${base}/booking`, label: 'Booking', show: permissions.includes('booking.view'), Icon: IconTicket },
    { key: 'pembayaran', href: `${base}/pembayaran`, label: 'Pembayaran', show: permissions.includes('payment.view'), Icon: IconMoney },
    { key: 'komisi', href: `${base}/komisi`, label: 'Komisi', show: permissions.includes('commission.view'), Icon: IconCommission },
    { key: 'laporan', href: `${base}/laporan`, label: 'Laporan', show: permissions.includes('report.sales.view') || permissions.includes('report.finance.view'), Icon: IconChart },
    { key: 'agen', href: `${base}/agen`, label: 'Agen', show: permissions.includes('partnership.view'), Icon: IconHandshake },
    { key: 'ulasan', href: `${base}/ulasan`, label: 'Ulasan', show: permissions.includes('package.view'), Icon: IconStar },
    { key: 'langganan', href: `${base}/langganan`, label: 'Langganan', show: permissions.includes('billing.view'), Icon: IconWork },
  ];
  return (
    <nav aria-label="Menu PPIU" className="no-scrollbar mt-5 -mb-px flex gap-1 overflow-x-auto">
      {items.filter((i) => i.show).map((i) => (
        <Link key={i.key} href={i.href} aria-current={i.key === active ? 'page' : undefined}
          className={`inline-flex min-h-12 shrink-0 items-center gap-2 rounded-t-[10px] px-4 font-semibold ${i.key === active ? 'bg-surface text-primary-800' : 'text-white/85 hover:bg-white/10'}`}>
          <i.Icon className={`h-5 w-5 ${i.key === active ? 'text-gold-700' : 'text-gold-500'}`} />{i.label}
        </Link>
      ))}
    </nav>
  );
}
