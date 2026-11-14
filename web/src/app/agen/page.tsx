import Link from 'next/link';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { serverApi } from '@/lib/session';
import type { Partnership, ReferralLink } from '@/lib/agent-types';
import { AgentHeader, requireAgent } from './agent-shell';
import { PartnershipActions } from './agent-forms';

export const metadata = { title: 'Dasbor agen' };

async function Overview() {
  const ctx = await requireAgent('/agen');
  const [parts, links] = await Promise.all([
    serverApi<{ data: Partnership[] }>('/agent/partnerships').then((r) => r.data),
    serverApi<{ data: ReferralLink[] }>('/agent/referral-links').then((r) => r.data),
  ]);
  const invites = parts.filter((p) => p.status === 'invited');
  const active = parts.filter((p) => p.status === 'active');
  const clicks = links.reduce((a, l) => a + l.click_count, 0);
  const stats = [
    ['PPIU mitra aktif', active.length], ['Menunggu persetujuan', parts.filter((p) => p.status === 'applied').length],
    ['Link aktif', links.filter((l) => l.is_active).length], ['Total klik link', clicks],
  ] as const;

  return (
    <>
      <AgentHeader ctx={ctx} active="ringkasan" />
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        {invites.map((p) => (
          <Alert key={p.id} tone="info" title={`${p.tenant.brand_name} mengundang Anda menjadi agen mitra`}>
            <p className="mb-2">Komisi: {p.commission_scheme?.label ?? 'mengikuti skema PPIU'}.</p>
            <PartnershipActions id={p.id} status={p.status} brand={p.tenant.brand_name} />
          </Alert>
        ))}
        <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {stats.map(([l, v]) => (
            <li key={l} className="card p-4"><p className="text-sm text-ink-600">{l}</p><p className="num text-3xl font-extrabold text-primary-800">{v}</p></li>
          ))}
        </ul>
        {active.length === 0 ? (
          <div className="card p-8 text-center">
            <p className="text-lg font-bold">Belum ada PPIU mitra aktif</p>
            <p className="mt-1 text-ink-600">Ajukan kemitraan ke PPIU terverifikasi. Setelah disetujui, Anda bisa membuat link paket dan membagikannya.</p>
            <Link href="/agen/mitra" className="btn btn-primary mt-4">Cari PPIU mitra</Link>
          </div>
        ) : (
          <section className="card p-5" aria-labelledby="mitra-h">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 id="mitra-h" className="text-xl font-extrabold">PPIU mitra</h2>
              <Link href="/agen/paket" className="btn btn-gold min-h-10 px-4">Bagikan paket</Link>
            </div>
            <ul className="mt-3 divide-y divide-border-subtle">
              {active.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div>
                    <p className="font-bold">{p.tenant.brand_name}</p>
                    <p className="text-sm text-ink-600">Komisi: {p.commission_scheme?.label ?? '—'}</p>
                  </div>
                  <StatusPill status={p.status} />
                </li>
              ))}
            </ul>
          </section>
        )}
        <section className="card p-5" aria-labelledby="link-h">
          <h2 id="link-h" className="text-xl font-extrabold">Link teratas</h2>
          {links.length === 0 ? <p className="mt-2 text-ink-600">Belum ada link. Buat dari halaman Paket &amp; link.</p> : (
            <table className="mt-3 w-full text-left">
              <thead className="text-sm text-ink-600"><tr><th className="py-2">Link</th><th className="py-2 text-right">Klik</th></tr></thead>
              <tbody className="divide-y divide-border-subtle">
                {[...links].sort((a, b) => b.click_count - a.click_count).slice(0, 5).map((l) => (
                  <tr key={l.id}>
                    <td className="py-2"><span className="font-semibold">{l.package_name ?? `Profil ${l.tenant_name}`}</span><span className="num ml-2 text-sm text-ink-600">{l.code}</span>{!l.is_active && <span className="ml-2 text-sm text-danger">nonaktif</span>}</td>
                    <td className="num py-2 text-right font-bold">{l.click_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      </div>
    </>
  );
}

export default function AgentHome() {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Overview /></Suspense>;
}
