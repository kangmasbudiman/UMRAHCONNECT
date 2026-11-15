import { Suspense } from 'react';
import { Logo } from '@/components/site-chrome';
import { getContexts } from '@/lib/session';
import { AdminSidebar } from './admin-sidebar';

async function SidebarWithUser() {
  const ctx = await getContexts().catch(() => null);
  const user = ctx?.platform_role ? { name: ctx.user.name, superAdmin: ctx.platform_role === 'super_admin' } : null;
  return <AdminSidebar brand={<Logo onDark />} user={user} />;
}

/** Kerangka sidebar selagi sesi dibaca (tanpa usePathname agar aman di shell statis). */
function SidebarSkeleton() {
  return (
    <>
      <aside className="sticky top-0 hidden h-screen w-[264px] shrink-0 bg-primary-900 lg:block"><div className="flex h-16 items-center px-5"><Logo onDark /></div></aside>
      <div className="sticky top-0 z-40 flex h-14 items-center bg-primary-900 px-4 lg:hidden"><Logo onDark /></div>
    </>
  );
}

/** Panel platform tampil sebagai aplikasi: tanpa navbar & footer situs, dengan sidebar sendiri. */
export default function AdminLayout({ children }: LayoutProps<'/admin'>) {
  return (
    <div data-no-navbar data-no-footer className="min-h-screen bg-surface lg:flex">
      <Suspense fallback={<SidebarSkeleton />}>
        <SidebarWithUser />
      </Suspense>
      <div className="min-w-0 flex-1 pb-10">{children}</div>
    </div>
  );
}
