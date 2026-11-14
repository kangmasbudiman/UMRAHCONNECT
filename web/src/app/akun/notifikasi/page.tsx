import Link from 'next/link';
import { Suspense } from 'react';
import { PageBanner } from '@/components/ui';
import type { AppNotification } from '@/lib/finance-types';
import { requireContexts, serverApi } from '@/lib/session';
import { MarkAllRead } from './mark-read';
import { IconBell, IconCalendarClock, IconCommission, IconHandshake, IconInbox, IconMoney, IconPackage, IconPayout, IconRefresh, IconShieldCheck, IconSupport, IconTicket, IconUndo, IconUser } from '@/components/icons';

export const metadata = { title: 'Notifikasi' };

/** Ikon per jenis notifikasi (awalan event). */
function eventIcon(event: string) {
  const c = 'h-5 w-5';
  if (event.startsWith('payment')) return <IconMoney className={c} />;
  if (event.startsWith('commission')) return <IconCommission className={c} />;
  if (event.startsWith('payout')) return <IconPayout className={c} />;
  if (event.startsWith('partnership')) return <IconHandshake className={c} />;
  if (event.startsWith('booking.cancel') || event.startsWith('cancel')) return <IconUndo className={c} />;
  if (event.startsWith('refund')) return <IconRefresh className={c} />;
  if (event.startsWith('booking')) return <IconTicket className={c} />;
  if (event.startsWith('support')) return <IconSupport className={c} />;
  if (event.startsWith('package')) return <IconPackage className={c} />;
  if (event.startsWith('tenant') || event.startsWith('license') || event.startsWith('platform')) return <IconShieldCheck className={c} />;
  if (event.startsWith('account')) return <IconUser className={c} />;
  if (event.startsWith('invoice') || event.startsWith('departure')) return <IconCalendarClock className={c} />;
  return <IconBell className={c} />;
}

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
      {res.data.length === 0 ? <div className="card p-8 text-center text-ink-600"><IconInbox className="mx-auto mb-2 h-10 w-10 text-primary-600/50" />Belum ada notifikasi.</div> : (
        <ul className="card divide-y divide-border-subtle">
          {res.data.map((n) => {
            const body = (
              <div className="flex gap-3 p-4">
                <NotifIcon event={n.event} unread={!n.read_at} />
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

function NotifIcon({ event, unread }: { event: string; unread: boolean }) {
  return (
    <span className={`relative flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] ${unread ? 'bg-primary-700 text-white' : 'bg-primary-50 text-primary-700'}`}>
      {eventIcon(event)}
      {unread && <span aria-hidden="true" className="absolute -right-1 -top-1 h-3 w-3 rounded-full border-2 border-white bg-gold-500" />}
    </span>
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
