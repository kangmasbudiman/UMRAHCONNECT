import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { rupiah, tanggal } from '@/lib/format';
import { requireContexts, serverApi, ServerApiError } from '@/lib/session';
import { AdminHeader } from './admin-nav';

export const metadata = { title: 'Dasbor platform' };

type PlatformDash = {
  tenants_by_status: Record<string, number>; agents: number; users: number; bookings_by_status: Record<string, number>;
  gmv_active: number; payments_verified_amount: number; weekly: { week: string; bookings: number; gmv: number }[];
};

/** ADM-01 Dasbor platform (FR-ADM-01): angka global dan tren booking 8 minggu. */
async function Dash() {
  const ctx = await requireContexts('/admin');
  if (!ctx.platform_role) notFound();
  let d: PlatformDash;
  try {
    d = (await serverApi<{ data: PlatformDash }>('/platform/dashboard')).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.code === 'two_factor_required') return <Alert tone="warning" title="Aktifkan 2FA">Staf platform wajib memakai 2FA. <Link href="/akun#keamanan" className="font-bold text-primary-700 underline">Atur di halaman Akun</Link></Alert>;
    throw e;
  }
  const activeBookings = (d.bookings_by_status.awaiting_dp ?? 0) + (d.bookings_by_status.dp_verified ?? 0) + (d.bookings_by_status.fully_paid ?? 0);
  const maxW = Math.max(1, ...d.weekly.map((w) => w.bookings));
  const stats = [
    ['PPIU terverifikasi', String(d.tenants_by_status.verified ?? 0), `${d.tenants_by_status.pending_review ?? 0} menunggu tinjauan`, '/admin/ppiu'],
    ['Agen terdaftar', String(d.agents), `${d.users} akun pengguna`, null],
    ['Booking aktif', String(activeBookings), `${d.bookings_by_status.expired ?? 0} kedaluwarsa · ${d.bookings_by_status.cancelled ?? 0} batal`, null],
    ['Nilai booking aktif', rupiah(d.gmv_active), `${rupiah(d.payments_verified_amount)} dibayar ke PPIU`, null],
  ] as const;

  return (
    <div className="space-y-6">
      <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map(([l, v, s, href]) => {
          const body = <><p className="text-sm text-ink-600">{l}</p><p className="num text-2xl font-extrabold text-primary-800">{v}</p><p className="text-sm text-ink-600">{s}</p></>;
          return <li key={l} className="card p-4">{href ? <Link href={href} className="block hover:text-primary-700">{body}</Link> : body}</li>;
        })}
      </ul>
      <section className="card p-5" aria-labelledby="w-h">
        <h2 id="w-h" className="text-lg font-extrabold">Booking per minggu (8 minggu terakhir)</h2>
        {d.weekly.length === 0 ? <p className="mt-2 text-ink-600">Belum ada booking.</p> : (
          <ol className="mt-4 flex h-48 items-end gap-3">
            {d.weekly.map((w) => (
              <li key={w.week} className="flex flex-1 flex-col items-center gap-1">
                <span className="num text-sm font-bold">{w.bookings}</span>
                <div className="w-full rounded-t-md bg-primary-700" style={{ height: `${(w.bookings / maxW) * 140}px` }} title={`${w.bookings} booking · ${rupiah(w.gmv)}`} />
                <span className="text-xs text-ink-600">{tanggal(w.week)}</span>
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  );
}

export default function AdminDashboardPage() {
  return (
    <>
      <AdminHeader active="dasbor" title="Dasbor platform" />
      <div className="mx-auto max-w-6xl px-4 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><Dash /></Suspense></div>
    </>
  );
}
