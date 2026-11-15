export type AdminTab = 'dasbor' | 'ppiu' | 'paket' | 'ulasan' | 'pengguna' | 'audit' | 'staf' | 'pengaturan' | 'pendapatan';

/**
 * Judul halaman panel platform. Navigasi ada di sidebar (admin/layout.tsx);
 * `active` dipertahankan agar pemanggil lama tetap valid.
 */
export function AdminHeader({ title, sub }: { active?: AdminTab; title: string; sub?: string }) {
  return (
    <header className="border-b border-border-subtle bg-white">
      <div className="px-4 sm:px-6 lg:px-8 py-6">
        <p className="text-xs font-bold uppercase tracking-[0.14em] text-gold-700">Panel platform</p>
        <h1 className="mt-1 text-2xl font-extrabold text-ink-900 md:text-[28px]">{title}</h1>
        {sub && <p className="mt-1 max-w-3xl text-ink-600">{sub}</p>}
      </div>
    </header>
  );
}
