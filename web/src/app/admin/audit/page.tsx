import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { requireContexts, serverApi } from '@/lib/session';
import { AdminHeader } from '../admin-nav';
import { IconChevronRight, IconFilter } from '@/components/icons';

export const metadata = { title: 'Audit log' };

type Log = {
  id: number; action: string; entity_type: string; entity_id: string; tenant_id: string | null; tenant_name: string | null;
  actor_name: string | null; actor_context: string | null; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null;
  reason: string | null; ip_address: string | null; created_at: string;
};
const wib = (iso: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'medium', timeZone: 'Asia/Jakarta' }).format(new Date(iso));
const CTX: Record<string, string> = { platform: 'Platform', tenant: 'PPIU', agent: 'Agen', pilgrim: 'Jamaah', system: 'Sistem' };

/** ADM-08 Audit log (FR-ADM-08): Admin Platform hanya melihat log tenant. */
async function Logs({ searchParams }: PageProps<'/admin/audit'>) {
  const sp = await searchParams;
  const ctx = await requireContexts('/admin/audit');
  if (!ctx.platform_role) notFound();
  const action = typeof sp.action === 'string' ? sp.action.slice(0, 60) : '';
  const cursor = typeof sp.cursor === 'string' ? sp.cursor : '';
  const qs = new URLSearchParams({ limit: '50', ...(action ? { 'filter[action]': action } : {}), ...(cursor ? { cursor } : {}) });
  const res = await serverApi<{ data: Log[]; meta: { next_cursor: string | null } }>(`/platform/audit-logs?${qs}`);
  return (
    <>
      <form className="flex flex-wrap gap-2" role="search">
        <label htmlFor="action" className="sr-only">Filter aksi</label>
        <input id="action" name="action" defaultValue={action} className="input max-w-sm" placeholder="Awalan aksi, mis. payment. atau tenant.verification" />
        <button className="btn btn-outline"><IconFilter className="h-5 w-5" />Saring</button>
      </form>
      <div className="card overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-surface text-ink-600"><tr><th className="px-3 py-2">Waktu (WIB)</th><th className="px-3 py-2">Aksi</th><th className="px-3 py-2">Pelaku</th><th className="px-3 py-2">PPIU</th><th className="px-3 py-2">Rincian</th></tr></thead>
          <tbody className="divide-y divide-border-subtle">
            {res.data.map((l) => (
              <tr key={l.id} className="align-top">
                <td className="num whitespace-nowrap px-3 py-2">{wib(l.created_at)}</td>
                <td className="px-3 py-2"><code className="font-semibold">{l.action}</code><br /><span className="text-xs text-ink-600">{l.entity_type}</span></td>
                <td className="px-3 py-2">{l.actor_name ?? '—'}<br /><span className="text-xs text-ink-600">{CTX[l.actor_context ?? ''] ?? l.actor_context}{l.ip_address && ` · ${l.ip_address}`}</span></td>
                <td className="px-3 py-2">{l.tenant_name ?? '—'}</td>
                <td className="max-w-80 px-3 py-2 text-xs text-ink-600">
                  {l.reason && <p className="text-sm text-ink-900">“{l.reason}”</p>}
                  {l.new_values && <p className="break-words font-mono">{JSON.stringify(l.new_values).slice(0, 200)}</p>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {res.meta.next_cursor && <Link href={`/admin/audit?${new URLSearchParams({ ...(action ? { action } : {}), cursor: res.meta.next_cursor })}`} className="btn btn-outline"><IconChevronRight className="h-5 w-5" />Muat berikutnya</Link>}
    </>
  );
}

export default function AuditPage(props: PageProps<'/admin/audit'>) {
  return (
    <>
      <AdminHeader active="audit" title="Audit log" sub="Jejak tindakan sensitif: verifikasi, pembayaran, pembatalan, akses identitas, dan akses dukungan." />
      <div className="mx-auto max-w-6xl space-y-4 px-4 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><Logs {...props} /></Suspense></div>
    </>
  );
}
