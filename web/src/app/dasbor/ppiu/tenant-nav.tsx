import Link from 'next/link';

export type TenantTab = 'ringkasan' | 'paket' | 'booking' | 'pembayaran' | 'komisi' | 'laporan' | 'agen';

/** Navigasi area PPIU; setiap tab hanya untuk peran yang punya permission-nya. */
export function TenantNav({ tenantId, active, permissions }: { tenantId: string; active: TenantTab; permissions: string[] }) {
  const base = `/dasbor/ppiu/${tenantId}`;
  const items: { key: TenantTab; href: string; label: string; show: boolean }[] = [
    { key: 'ringkasan', href: base, label: 'Ringkasan', show: true },
    { key: 'paket', href: `${base}/paket`, label: 'Paket', show: permissions.includes('package.view') },
    { key: 'booking', href: `${base}/booking`, label: 'Booking', show: permissions.includes('booking.view') },
    { key: 'pembayaran', href: `${base}/pembayaran`, label: 'Pembayaran', show: permissions.includes('payment.view') },
    { key: 'komisi', href: `${base}/komisi`, label: 'Komisi', show: permissions.includes('commission.view') },
    { key: 'laporan', href: `${base}/laporan`, label: 'Laporan', show: permissions.includes('report.sales.view') || permissions.includes('report.finance.view') },
    { key: 'agen', href: `${base}/agen`, label: 'Agen', show: permissions.includes('partnership.view') },
  ];
  return (
    <nav aria-label="Menu PPIU" className="no-scrollbar mt-5 -mb-px flex gap-1 overflow-x-auto">
      {items.filter((i) => i.show).map((i) => (
        <Link key={i.key} href={i.href} aria-current={i.key === active ? 'page' : undefined}
          className={`inline-flex min-h-12 shrink-0 items-center rounded-t-[10px] px-4 font-semibold ${i.key === active ? 'bg-surface text-primary-800' : 'text-white/85 hover:bg-white/10'}`}>
          {i.label}
        </Link>
      ))}
    </nav>
  );
}
