import Link from 'next/link';
import { connection } from 'next/server';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import type { Report } from '@/lib/finance-types';
import { rupiah } from '@/lib/format';
import { serverApi, ServerApiError } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';
import { IconChart, IconInbox, IconMoney, IconSpreadsheet } from '@/components/icons';
import { IconHeading } from '@/components/stat-card';

export const metadata = { title: 'Laporan' };

const SALES = { package: 'Per paket', departure: 'Per keberangkatan', agent: 'Per agen' } as const;
const FINANCE = { receipts: 'Penerimaan (kwitansi)', receivables: 'Piutang jamaah', commissions_due: 'Komisi terutang', refunds: 'Refund' } as const;
const wibDate = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(d);

/** PPIU-18 Laporan penjualan & keuangan + ekspor CSV (FR-PPIU-16, FR-FIN-10). Tanpa NIK/paspor/rekening utuh. */
async function Reports({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]/laporan'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/laporan`);
  if (!isTenantPage(page)) return page;
  const perms = page.membership.permissions;
  const canSales = perms.includes('report.sales.view');
  const canFinance = perms.includes('report.finance.view');
  await connection(); // tanggal hari ini dihitung saat request
  const now = new Date();
  const from = typeof sp.from === 'string' ? sp.from : wibDate(new Date(now.getFullYear(), now.getMonth(), 1));
  const to = typeof sp.to === 'string' ? sp.to : wibDate(now);
  const jenis = typeof sp.jenis === 'string' && (sp.jenis in SALES || sp.jenis in FINANCE) ? sp.jenis : canSales ? 'package' : 'receipts';
  const isSales = jenis in SALES;
  if ((isSales && !canSales) || (!isSales && !canFinance)) {
    return <><TenantHeader page={page} active="laporan" /><div className="mx-auto max-w-6xl px-4 py-6"><Alert tone="info">Peran Anda tidak memiliki akses ke laporan ini.</Alert></div></>;
  }
  const qs = isSales ? `group_by=${jenis}&from=${from}&to=${to}` : `type=${jenis}&from=${from}&to=${to}`;
  let report: Report | null = null;
  let error: string | null = null;
  try {
    report = (await serverApi<{ data: Report }>(`/tenants/${id}/reports/${isSales ? 'sales' : 'finance'}?${qs}`)).data;
  } catch (e) {
    error = e instanceof ServerApiError ? e.message : 'Laporan gagal dimuat.';
  }
  const canExport = perms.includes(isSales ? 'report.export:sales' : 'report.export:finance');
  const noRange = jenis === 'receivables' || jenis === 'commissions_due';

  return (
    <>
      <TenantHeader page={page} active="laporan" sub="Angka dihitung langsung dari transaksi; ekspor CSV bisa dibuka di Excel atau Google Sheets." />
      <div className="mx-auto max-w-6xl space-y-4 px-4 py-6">
        <form className="card grid gap-3 p-4 md:grid-cols-[1fr_auto_auto_auto] md:items-end">
          <div>
            <label htmlFor="jenis" className="mb-1.5 block font-semibold">Laporan</label>
            <select id="jenis" name="jenis" className="input" defaultValue={jenis}>
              {canSales && <optgroup label="Penjualan">{Object.entries(SALES).map(([k, l]) => <option key={k} value={k}>Penjualan · {l}</option>)}</optgroup>}
              {canFinance && <optgroup label="Keuangan">{Object.entries(FINANCE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</optgroup>}
            </select>
          </div>
          <div><label htmlFor="from" className="mb-1.5 block font-semibold">Dari</label><input id="from" name="from" type="date" className="input" defaultValue={from} /></div>
          <div><label htmlFor="to" className="mb-1.5 block font-semibold">Sampai</label><input id="to" name="to" type="date" className="input" defaultValue={to} /></div>
          <button className="btn btn-primary"><IconChart className="h-5 w-5" />Tampilkan</button>
        </form>
        {noRange && <p className="text-sm text-ink-600">Laporan ini menampilkan posisi saat ini (tidak dibatasi tanggal).</p>}
        {error && <Alert tone="danger">{error}</Alert>}
        {report && (
          <section className="card overflow-hidden" aria-label="Hasil laporan">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border-subtle p-4">
              <IconHeading icon={isSales ? IconChart : IconMoney} className="text-lg font-extrabold">{isSales ? `Penjualan ${SALES[jenis as keyof typeof SALES].toLowerCase()}` : FINANCE[jenis as keyof typeof FINANCE]}</IconHeading>
              {canExport && <a href={`/api/v1/tenants/${id}/reports/${isSales ? 'sales' : 'finance'}/export?format=csv&${qs}`} className="btn btn-outline min-h-10 px-4"><IconSpreadsheet className="h-5 w-5" />Ekspor CSV</a>}
            </div>
            {report.rows.length === 0 ? <p className="p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />Tidak ada data pada rentang ini.</p> : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-left text-sm">
                  <thead className="bg-surface text-ink-600"><tr>{report.columns.map((c) => <th key={c.key} className={`px-3 py-2 ${c.money ? 'text-right' : ''}`}>{c.label}</th>)}</tr></thead>
                  <tbody className="divide-y divide-border-subtle">
                    {report.rows.map((r, i) => (
                      <tr key={i}>{report!.columns.map((c) => <td key={c.key} className={`px-3 py-2 ${c.money ? 'num text-right' : ''}`}>{c.money ? rupiah(Number(r[c.key] ?? 0)) : (r[c.key] ?? '—')}</td>)}</tr>
                    ))}
                  </tbody>
                  {Object.keys(report.totals).length > 0 && (
                    <tfoot className="bg-primary-50 font-bold"><tr>{report.columns.map((c, i) => (
                      <td key={c.key} className={`px-3 py-2 ${c.money ? 'num text-right' : ''}`}>{i === 0 ? 'Total' : c.key in report!.totals ? (c.money ? rupiah(report!.totals[c.key]) : report!.totals[c.key]) : ''}</td>
                    ))}</tr></tfoot>
                  )}
                </table>
              </div>
            )}
          </section>
        )}
        <p className="text-sm text-ink-600">Butuh rincian per booking? Buka <Link href={`/dasbor/ppiu/${id}/booking`} className="font-semibold text-primary-700 underline">daftar booking</Link>.</p>
      </div>
    </>
  );
}

export default function ReportsPage(props: PageProps<'/dasbor/ppiu/[tenant]/laporan'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Reports {...props} /></Suspense>;
}
