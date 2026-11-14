import Link from 'next/link';
import { notFound } from 'next/navigation';
import type { ReactNode } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { requireContexts, serverApi, ServerApiError, type Contexts, type TenantContext } from '@/lib/session';
import type { TenantFull } from '@/lib/tenant-types';
import { TenantNav, type TenantTab } from './tenant-nav';

export type TenantPage = { ctx: Contexts; membership: TenantContext; tenant: TenantFull };

/**
 * Muat konteks halaman PPIU: wajib anggota aktif, permission tertentu, dan 2FA bila wajib.
 * Mengembalikan elemen peringatan (bukan data) bila 2FA belum aktif.
 */
export async function loadTenantPage(id: string, next: string, permission?: string): Promise<TenantPage | ReactNode> {
  const ctx = await requireContexts(next);
  const membership = ctx.tenants.find((t) => t.id === id);
  if (!membership || (permission && !membership.permissions.includes(permission))) notFound();
  try {
    const tenant = (await serverApi<{ data: TenantFull }>(`/tenants/${id}`)).data;
    return { ctx, membership, tenant };
  } catch (e) {
    if (e instanceof ServerApiError && e.code === 'two_factor_required') {
      return (
        <div className="mx-auto max-w-3xl px-4 py-10">
          <Alert tone="warning" title="Aktifkan verifikasi dua langkah (2FA)">
            Peran Anda wajib memakai 2FA untuk membuka dasbor {membership.brand_name}.{' '}
            <Link href="/akun#keamanan" className="font-bold text-primary-700 underline">Atur 2FA di halaman Akun</Link>
          </Alert>
        </div>
      );
    }
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }
}

export function isTenantPage(x: TenantPage | ReactNode): x is TenantPage {
  return typeof x === 'object' && x !== null && 'tenant' in x && 'membership' in x;
}

const ROLE: Record<string, string> = { owner: 'Owner', admin: 'Admin', finance: 'Finance' };

export function TenantHeader({ page, active, title, sub, back }: { page: TenantPage; active: TenantTab; title?: string; sub?: ReactNode; back?: { href: string; label: string } }) {
  const { tenant, membership } = page;
  return (
    <section className="bg-hero text-white">
      <div className="mx-auto flex max-w-6xl flex-wrap items-end justify-between gap-4 px-4 pt-8">
        <div className="min-w-0">
          {back
            ? <Link href={back.href} className="text-sm font-semibold text-gold-500 hover:underline">← {back.label}</Link>
            : <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Dasbor PPIU · {ROLE[membership.role]}</p>}
          <h1 className="mt-1 text-3xl font-extrabold">{title ?? tenant.brand_name}</h1>
          {sub && <div className="mt-1 text-white/85">{sub}</div>}
        </div>
        <div className="rounded-[10px] bg-white px-3 py-2"><StatusPill status={tenant.verification_status} /></div>
      </div>
      <div className="mx-auto max-w-6xl px-4"><TenantNav tenantId={tenant.id} active={active} permissions={membership.permissions} /></div>
    </section>
  );
}
