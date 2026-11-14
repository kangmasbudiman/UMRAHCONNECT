import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { IconInbox } from '@/components/icons';
import { RatingStars } from '@/components/trust';
import { tanggal } from '@/lib/format';
import { requireContexts, serverApi } from '@/lib/session';
import { AdminHeader } from '../admin-nav';
import { ConfirmAction, ReasonAction } from '../platform-actions';

export const metadata = { title: 'Moderasi ulasan' };

type Row = { id: string; rating: number; body: string; author: string; status: string; hidden_reason: string | null; tenant_reply: string | null; created_at: string; package: { name: string }; tenant_name: string | null };

/** Ulasan yang memuat data pribadi, ujaran kasar, atau promosi bisa disembunyikan (tidak dihapus; tercatat di audit log). */
async function List() {
  const ctx = await requireContexts('/admin/ulasan');
  if (!ctx.platform_role) notFound();
  const res = await serverApi<{ data: Row[] }>('/platform/reviews?limit=50');
  if (res.data.length === 0) return <div className="card p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />Belum ada ulasan.</div>;
  return (
    <ul className="space-y-3">
      {res.data.map((r) => (
        <li key={r.id} className={`card p-4 ${r.status === 'hidden' ? 'opacity-75' : ''}`}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="font-bold">{r.author} <span className="text-sm font-normal text-ink-600">· {r.tenant_name} · {r.package.name} · {tanggal(r.created_at.slice(0, 10))}</span></p>
            <RatingStars value={r.rating} />
          </div>
          <p className="mt-2 whitespace-pre-line">{r.body}</p>
          {r.hidden_reason && <p className="mt-1 text-sm font-semibold text-danger">Disembunyikan: {r.hidden_reason}</p>}
          <div className="mt-3">
            {r.status === 'published'
              ? <ReasonAction label="Sembunyikan ulasan" path={`/platform/reviews/${r.id}/hide`} hint="Mis. memuat nomor telepon, kata kasar, atau promosi. Tercatat di audit log." confirmText="Sembunyikan ulasan ini dari publik?" />
              : <ConfirmAction label="Tampilkan lagi" tone="primary" path={`/platform/reviews/${r.id}/unhide`} confirmText="Tampilkan ulasan ini lagi?" />}
          </div>
        </li>
      ))}
    </ul>
  );
}

export default function AdminReviewsPage() {
  return (
    <>
      <AdminHeader active="ulasan" title="Moderasi ulasan" sub="Ulasan berasal dari jamaah yang sudah berangkat; sembunyikan hanya yang melanggar aturan." />
      <div className="mx-auto max-w-4xl space-y-4 px-4 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><List /></Suspense></div>
    </>
  );
}
