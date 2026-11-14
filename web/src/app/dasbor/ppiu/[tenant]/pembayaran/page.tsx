import Link from 'next/link';
import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { INVOICE_TYPE, PAY_METHOD, type Payment } from '@/lib/booking-types';
import { rupiah, tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';
import { VerifyActions } from './verify-actions';

export const metadata = { title: 'Pembayaran' };

type Row = Payment & {
  booking: { id: string; code: string; status: string; booker: string; total_amount: number; paid_amount: number; hold_expires_at: string | null };
  invoice_amount: number; invoice_paid_amount: number;
};
const TABS = [{ s: 'pending', label: 'Menunggu verifikasi' }, { s: 'verified', label: 'Terverifikasi' }, { s: 'rejected', label: 'Ditolak' }];
const wib = (iso: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso)) + ' WIB';

/** PPIU-15 Antrean verifikasi pembayaran (FR-FIN-04): terlama di atas; cocokkan dengan mutasi rekening. */
async function Queue({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/pembayaran'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/pembayaran`, 'payment.view');
  if (!isTenantPage(page)) return page;
  const status = typeof sp.status === 'string' && TABS.some((t) => t.s === sp.status) ? sp.status : 'pending';
  const res = await serverApi<{ data: Row[]; meta: { counts: Record<string, number> } }>(`/tenants/${id}/payments?filter[status]=${status}&limit=100`);
  const canVerify = page.membership.permissions.includes('payment.verify');

  return (
    <>
      <TenantHeader page={page} active="pembayaran" sub="Cocokkan setiap bukti dengan mutasi rekening resmi sebelum memverifikasi." />
      <div className="mx-auto max-w-6xl space-y-4 px-4 py-6">
        <nav aria-label="Status pembayaran" className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
          {TABS.map((t) => {
            const on = t.s === status;
            const n = res.meta.counts[t.s] ?? 0;
            return (
              <Link key={t.s} href={`/dasbor/ppiu/${id}/pembayaran?status=${t.s}`} aria-current={on ? 'page' : undefined}
                className={`chip shrink-0 ${on ? 'border-primary-700 bg-primary-700 text-white' : 'hover:border-primary-600'}`}>
                {t.label} <span className={`num rounded-full px-2 text-xs ${on ? 'bg-white/20' : t.s === 'pending' && n > 0 ? 'bg-warning-bg text-warning' : 'bg-surface'}`}>{n}</span>
              </Link>
            );
          })}
        </nav>
        {res.data.length === 0 ? <div className="card p-8 text-center text-ink-600">{status === 'pending' ? 'Tidak ada bukti bayar yang menunggu. 👍' : 'Belum ada data.'}</div> : (
          <ul className="space-y-3">
            {res.data.map((p) => {
              const remaining = p.booking.total_amount - p.booking.paid_amount;
              return (
                <li key={p.id} className="card grid gap-4 p-4 md:grid-cols-[1fr_auto]">
                  <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link href={`/dasbor/ppiu/${id}/booking/${p.booking.id}`} className="num font-bold text-primary-700 hover:underline">{p.booking.code}</Link>
                      <StatusPill status={p.booking.status} />
                      <span className="text-sm text-ink-600">· {p.booking.booker}</span>
                    </div>
                    <p className="text-lg font-extrabold">{rupiah(p.claimed_amount)} <span className="text-sm font-normal text-ink-600">diklaim untuk {p.invoice_type ? INVOICE_TYPE[p.invoice_type] : ''} {p.invoice_number}</span></p>
                    <p className="text-sm text-ink-600">{PAY_METHOD[p.method]} {p.sender_bank && <>dari {p.sender_bank}</>} {p.sender_name && <>a.n. {p.sender_name}</>} · tanggal transfer {p.paid_on && tanggal(p.paid_on)} · dikirim {p.created_at && wib(p.created_at)}</p>
                    <p className="text-sm text-ink-600">Sisa tagihan booking {rupiah(remaining)}{p.booking.hold_expires_at && p.booking.status === 'awaiting_dp' && <> · tenggat DP {wib(p.booking.hold_expires_at)}</>}</p>
                    {p.proof_url && <a href={p.proof_url} target="_blank" rel="noopener" className="inline-flex min-h-10 items-center font-semibold text-primary-700 underline">Buka bukti transfer</a>}
                    {p.status === 'rejected' && <p className="text-sm font-semibold text-danger">Ditolak: {p.reject_reason}</p>}
                    {p.status === 'verified' && <p className="text-sm text-success">Terverifikasi {rupiah(p.verified_amount ?? 0)}{p.receipt && <> · kwitansi {p.receipt.number}</>}</p>}
                  </div>
                  {p.status === 'pending' && canVerify && <VerifyActions tenantId={id} paymentId={p.id} claimed={p.claimed_amount} remaining={remaining} />}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </>
  );
}

export default function PaymentsPage(props: PageProps<'/dasbor/ppiu/[tenant]/pembayaran'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Queue {...props} /></Suspense>;
}
