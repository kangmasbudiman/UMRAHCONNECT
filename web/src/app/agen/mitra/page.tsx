import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { serverApi } from '@/lib/session';
import { EARN_ON, type AgentTenant, type Partnership } from '@/lib/agent-types';
import { AgentHeader, requireAgent } from '../agent-shell';
import { ApplyPartnership, PartnershipActions } from '../agent-forms';

export const metadata = { title: 'Mitra PPIU' };

async function Partners({ searchParams }: PageProps<'/agen/mitra'>) {
  const sp = await searchParams;
  const ctx = await requireAgent('/agen/mitra');
  const [tenants, parts] = await Promise.all([
    serverApi<{ data: AgentTenant[] }>('/agent/tenants?limit=100').then((r) => r.data),
    serverApi<{ data: Partnership[] }>('/agent/partnerships').then((r) => r.data),
  ]);
  const byTenant = new Map(parts.map((p) => [p.tenant.id, p]));

  return (
    <>
      <AgentHeader ctx={ctx} active="mitra" title="Mitra PPIU" sub="Ajukan kemitraan ke PPIU terverifikasi. Komisi ditetapkan oleh masing-masing PPIU." />
      <div className="mx-auto max-w-6xl space-y-4 px-4 py-6">
        {sp.baru === '1' && <Alert tone="success" title={`Selamat, Anda terdaftar sebagai agen (kode ${ctx.agent.code})`}>Langkah berikutnya: ajukan kemitraan ke PPIU di bawah ini.</Alert>}
        <ul className="grid gap-4 md:grid-cols-2">
          {tenants.map((t) => {
            const p = byTenant.get(t.id);
            const s = p?.status ?? null;
            return (
              <li key={t.id} className="card flex flex-col gap-3 p-5">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-lg font-extrabold">{t.brand_name}</p>
                    <p className="text-sm text-ink-600">{t.legal_name} · {t.city ?? '—'} · Izin {t.ppiu_license_no}</p>
                  </div>
                  {s && <StatusPill status={s} />}
                </div>
                <p className="rounded-[10px] bg-gold-50 px-3 py-2 text-sm">
                  Komisi: <span className="font-bold">{(p?.commission_scheme ?? t.commission_scheme)?.label ?? 'belum ditetapkan'}</span>
                  {(p?.commission_scheme ?? t.commission_scheme) && <span className="text-ink-600">, diperoleh {EARN_ON[(p?.commission_scheme ?? t.commission_scheme)!.earn_on]}</span>}
                </p>
                {s === 'rejected' && p?.reject_reason && <p className="text-sm text-danger">Alasan: {p.reject_reason}</p>}
                {s === 'suspended' && p?.status_note && <p className="text-sm text-danger">Ditangguhkan: {p.status_note}. Link Anda untuk PPIU ini tidak mengatribusi sementara.</p>}
                <div className="mt-auto">
                  {(!s || s === 'rejected' || s === 'ended') && <ApplyPartnership tenantId={t.id} brand={t.brand_name} />}
                  {p && ['invited', 'active', 'suspended', 'applied'].includes(p.status) && <PartnershipActions id={p.id} status={p.status} brand={t.brand_name} />}
                </div>
              </li>
            );
          })}
        </ul>
      </div>
    </>
  );
}

export default function AgentPartnersPage(props: PageProps<'/agen/mitra'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Partners {...props} /></Suspense>;
}
