import Link from 'next/link';

export type AdminTab = 'dasbor' | 'ppiu' | 'audit';

/** Navigasi area platform (Super Admin / Admin Platform). */
export function AdminNav({ active }: { active: AdminTab }) {
  const items: { key: AdminTab; href: string; label: string }[] = [
    { key: 'dasbor', href: '/admin', label: 'Dasbor' },
    { key: 'ppiu', href: '/admin/ppiu', label: 'Verifikasi PPIU' },
    { key: 'audit', href: '/admin/audit', label: 'Audit log' },
  ];
  return (
    <nav aria-label="Menu platform" className="no-scrollbar mt-5 -mb-px flex gap-1 overflow-x-auto">
      {items.map((i) => (
        <Link key={i.key} href={i.href} aria-current={i.key === active ? 'page' : undefined}
          className={`inline-flex min-h-12 shrink-0 items-center rounded-t-[10px] px-4 font-semibold ${i.key === active ? 'bg-surface text-primary-800' : 'text-white/85 hover:bg-white/10'}`}>
          {i.label}
        </Link>
      ))}
    </nav>
  );
}

export function AdminHeader({ active, title, sub }: { active: AdminTab; title: string; sub?: string }) {
  return (
    <section className="bg-hero text-white">
      <div className="mx-auto max-w-6xl px-4 pt-8">
        <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Platform</p>
        <h1 className="mt-1 text-3xl font-extrabold">{title}</h1>
        {sub && <p className="mt-1 text-white/85">{sub}</p>}
        <AdminNav active={active} />
      </div>
    </section>
  );
}
