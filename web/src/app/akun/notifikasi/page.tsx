import Link from 'next/link';
import { Suspense } from 'react';
import { PageBanner } from '@/components/ui';
import type { AppNotification } from '@/lib/finance-types';
import { requireContexts, serverApi } from '@/lib/session';
import { MarkAllRead } from './mark-read';

export const metadata = { title: 'Notifikasi' };

const wib = (iso: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso)) + ' WIB';

/** Pusat notifikasi (FR-NTF-01). Notifikasi penting juga dikirim lewat email. */
async function List() {
  await requireContexts('/akun/notifikasi');
  const res = await serverApi<{ data: AppNotification[]; meta: { unread: number } }>('/me/notifications?limit=50');
  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-ink-600">{res.meta.unread > 0 ? `${res.meta.unread} belum dibaca` : 'Semua sudah dibaca'}</p>
        {res.meta.unread > 0 && <MarkAllRead />}
      </div>
      {res.data.length === 0 ? <div className="card p-8 text-center text-ink-600">Belum ada notifikasi.</div> : (
        <ul className="card divide-y divide-border-subtle">
          {res.data.map((n) => {
            const body = (
              <div className="flex gap-3 p-4">
                <span aria-hidden="true" className={`mt-2 h-2.5 w-2.5 shrink-0 rounded-full ${n.read_at ? 'bg-transparent' : 'bg-gold-500'}`} />
                <div className="min-w-0">
                  <p className={n.read_at ? 'font-semibold' : 'font-extrabold'}>{n.payload.title}{!n.read_at && <span className="sr-only"> (belum dibaca)</span>}</p>
                  <p className="text-ink-600">{n.payload.body}</p>
                  <p className="mt-1 text-sm text-ink-600">{wib(n.created_at)}</p>
                </div>
              </div>
            );
            return <li key={n.id}>{n.payload.url ? <Link href={n.payload.url} className="block hover:bg-primary-50">{body}</Link> : body}</li>;
          })}
        </ul>
      )}
    </>
  );
}

export default function NotificationsPage() {
  return (
    <>
      <PageBanner eyebrow="Akun saya" title="Notifikasi" />
      <div className="mx-auto max-w-3xl space-y-4 px-4 py-6">
        <Suspense fallback={<div className="h-64 animate-pulse rounded-[12px] bg-white" />}><List /></Suspense>
      </div>
    </>
  );
}
