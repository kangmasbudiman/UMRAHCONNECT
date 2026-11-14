import Link from 'next/link';
import { IconClipboard, IconCommission, IconDashboard, IconHandshake, IconLink, IconTicket, IconUser } from '@/components/icons';
import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { requireContexts, type Contexts } from '@/lib/session';

/** Konteks area agen: wajib masuk dan sudah terdaftar sebagai agen. */
export async function requireAgent(next: string): Promise<Contexts & { agent: NonNullable<Contexts['agent']> }> {
  const ctx = await requireContexts(next);
  if (!ctx.agent) redirect('/agen/daftar');
  return ctx as Contexts & { agent: NonNullable<Contexts['agent']> };
}

const TABS = [
  { key: 'ringkasan', href: '/agen', label: 'Ringkasan', Icon: IconDashboard },
  { key: 'mitra', href: '/agen/mitra', label: 'Mitra PPIU', Icon: IconHandshake },
  { key: 'paket', href: '/agen/paket', label: 'Paket & link', Icon: IconLink },
  { key: 'prospek', href: '/agen/prospek', label: 'Prospek', Icon: IconClipboard },
  { key: 'booking', href: '/agen/booking', label: 'Booking', Icon: IconTicket },
  { key: 'komisi', href: '/agen/komisi', label: 'Komisi', Icon: IconCommission },
  { key: 'profil', href: '/agen/profil', label: 'Profil', Icon: IconUser },
] as const;

export function AgentHeader({ ctx, active, title, sub }: { ctx: Contexts & { agent: NonNullable<Contexts['agent']> }; active: (typeof TABS)[number]['key']; title?: string; sub?: ReactNode }) {
  return (
    <section className="bg-hero text-white">
      <div className="mx-auto flex max-w-6xl flex-wrap items-end justify-between gap-4 px-4 pt-8">
        <div>
          <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Agen · kode {ctx.agent.code}</p>
          <h1 className="mt-1 text-3xl font-extrabold">{title ?? ctx.user.name}</h1>
          {sub && <div className="mt-1 text-white/85">{sub}</div>}
        </div>
        <div className="rounded-[10px] bg-white/10 px-3 py-2 text-sm"><span className="font-bold text-gold-500">{ctx.agent.active_partnerships}</span> PPIU mitra aktif</div>
      </div>
      <nav aria-label="Menu agen" className="no-scrollbar mx-auto mt-5 flex max-w-6xl gap-1 overflow-x-auto px-4">
        {TABS.map((t) => (
          <Link key={t.key} href={t.href} aria-current={t.key === active ? 'page' : undefined}
            className={`inline-flex min-h-12 shrink-0 items-center gap-2 rounded-t-[10px] px-4 font-semibold ${t.key === active ? 'bg-surface text-primary-800' : 'text-white/85 hover:bg-white/10'}`}>
            <t.Icon className={`h-5 w-5 ${t.key === active ? 'text-gold-700' : 'text-gold-500'}`} />{t.label}
          </Link>
        ))}
      </nav>
    </section>
  );
}
