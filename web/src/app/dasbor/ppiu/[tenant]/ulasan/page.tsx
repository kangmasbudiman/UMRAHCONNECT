import { Suspense } from 'react';
import { StatusPill } from '@/components/form';
import { IconInbox, IconStar } from '@/components/icons';
import { StatCard } from '@/components/stat-card';
import { RatingStars } from '@/components/trust';
import { tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';
import { isTenantPage, loadTenantPage, TenantHeader } from '../../tenant-shell';
import { ReplyForm } from './reply-form';

export const metadata = { title: 'Ulasan jamaah' };

type Row = { id: string; rating: number; body: string; author: string; status: string; hidden_reason: string | null; tenant_reply: string | null; created_at: string; package: { id: string; name: string }; booking_code: string };

/** Ulasan terverifikasi untuk PPIU: baca dan balas. Ulasan tidak bisa dihapus PPIU (hanya disembunyikan tim platform bila melanggar). */
async function Reviews({ params }: PageProps<'/dasbor/ppiu/[tenant]/ulasan'>) {
  const { tenant: id } = await params;
  const page = await loadTenantPage(id, `/dasbor/ppiu/${id}/ulasan`, 'package.view');
  if (!isTenantPage(page)) return page;
  const res = await serverApi<{ data: Row[]; meta: { summary: { avg: number | null; count: number } } }>(`/tenants/${id}/reviews?limit=50`);
  const canReply = page.membership.permissions.includes('package.manage');
  const s = res.meta.summary;
  return (
    <>
      <TenantHeader page={page} active="ulasan" sub="Ulasan hanya bisa ditulis jamaah yang benar-benar berangkat lewat UMRAH CONNECT." />
      <div className="mx-auto max-w-4xl space-y-4 px-4 py-6">
        <div className="card p-4"><StatCard icon={IconStar} tone="gold" label="Rating rata-rata" value={s.avg !== null ? `${s.avg.toLocaleString('id-ID', { minimumFractionDigits: 1 })} / 5` : '—'} sub={`${s.count} ulasan terverifikasi`} /></div>
        {res.data.length === 0 ? (
          <div className="card p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />Belum ada ulasan. Ulasan muncul setelah jamaah Anda berangkat.</div>
        ) : (
          <ul className="space-y-3">
            {res.data.map((r) => (
              <li key={r.id} className="card p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-bold">{r.author} <span className="num text-sm font-normal text-ink-600">· {r.booking_code} · {r.package.name}</span></p>
                  <span className="flex items-center gap-2"><RatingStars value={r.rating} /><span className="sr-only">{r.rating} dari 5</span>{r.status === 'hidden' && <StatusPill status="taken_down" />}</span>
                </div>
                <p className="mt-0.5 text-xs text-ink-600">{tanggal(r.created_at.slice(0, 10))}</p>
                <p className="mt-2 whitespace-pre-line">{r.body}</p>
                {r.hidden_reason && <p className="mt-1 text-sm text-danger">Disembunyikan tim UMRAH CONNECT: {r.hidden_reason}</p>}
                {r.tenant_reply && <div className="mt-3 rounded-[10px] border-l-4 border-primary-600 bg-primary-50 p-3 text-sm"><p className="font-semibold text-primary-800">Balasan Anda</p><p>{r.tenant_reply}</p></div>}
                {canReply && <ReplyForm tenantId={id} reviewId={r.id} current={r.tenant_reply} />}
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}

export default function TenantReviewsPage(props: PageProps<'/dasbor/ppiu/[tenant]/ulasan'>) {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Reviews {...props} /></Suspense>;
}
