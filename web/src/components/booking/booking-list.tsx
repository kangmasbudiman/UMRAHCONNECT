import Link from 'next/link';
import { StatusPill } from '@/components/form';
import { rupiah, tanggal } from '@/lib/format';
import type { BookingSummary } from '@/lib/booking-types';

/** Daftar booking ringkas (jamaah, agen, PPIU). */
export function BookingList({ rows, hrefFor, showTenant = true, showBooker = false, empty }: {
  rows: BookingSummary[]; hrefFor: (id: string) => string; showTenant?: boolean; showBooker?: boolean; empty: React.ReactNode;
}) {
  if (rows.length === 0) return <div className="card p-8 text-center text-ink-600">{empty}</div>;
  return (
    <ul className="space-y-3">
      {rows.map((b) => (
        <li key={b.id}>
          <Link href={hrefFor(b.id)} className="card group grid gap-3 p-4 transition hover:border-primary-600 sm:grid-cols-[1fr_auto] sm:items-center">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="num font-bold text-primary-700">{b.code}</span>
                <StatusPill status={b.status} />
              </div>
              <p className="mt-1 font-bold group-hover:text-primary-700">{b.package.name}</p>
              <p className="text-sm text-ink-600">
                {showTenant && <>{b.tenant.brand_name} · </>}Berangkat {tanggal(b.departure.departure_date)} · {b.participant_count} jamaah
                {showBooker && <> · pemesan {b.booker.name}</>}{b.agent && <> · agen {b.agent.display_name}</>}
              </p>
            </div>
            <div className="sm:text-right">
              <p className="num text-lg font-extrabold">{rupiah(b.total_amount)}</p>
              <p className="num text-sm text-ink-600">dibayar {rupiah(b.paid_amount)}</p>
            </div>
          </Link>
        </li>
      ))}
    </ul>
  );
}
