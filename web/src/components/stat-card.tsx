import type { ReactNode } from 'react';

type IconC = (p: { className?: string }) => ReactNode;

/** Kartu angka ringkas dengan ikon (dasbor PPIU, agen, platform). */
export function StatCard({ icon: Icon, label, value, sub, tone = 'primary' }: { icon: IconC; label: string; value: ReactNode; sub?: ReactNode; tone?: 'primary' | 'gold' | 'warning' }) {
  const chip = tone === 'gold' ? 'bg-gold-50 text-gold-700' : tone === 'warning' ? 'bg-warning-bg text-warning' : 'bg-primary-50 text-primary-700';
  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:gap-3">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] ${chip}`}><Icon className="h-5 w-5" /></span>
      <div className="min-w-0">
        <p className="text-sm text-ink-600">{label}</p>
        <p className="num break-words text-lg font-extrabold text-primary-800 sm:text-xl md:text-2xl">{value}</p>
        {sub && <p className="text-sm text-ink-600">{sub}</p>}
      </div>
    </div>
  );
}

/** Judul bagian dengan ikon. */
export function IconHeading({ icon: Icon, children, as: Tag = 'h2', id, className = 'text-xl font-extrabold' }: { icon: IconC; children: ReactNode; as?: 'h2' | 'h3'; id?: string; className?: string }) {
  return (
    <Tag id={id} className={`flex items-center gap-2 ${className}`}>
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><Icon className="h-5 w-5" /></span>
      {children}
    </Tag>
  );
}
