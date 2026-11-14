import 'server-only';
import { cookies, headers } from 'next/headers';
import { redirect } from 'next/navigation';

/**
 * Akses API atas nama pengguna yang sedang masuk, dari Server Component.
 * Cookie sesi diteruskan ke Laravel; Referer domain web membuat Sanctum memakai sesi (stateful).
 * Tidak di-cache: datanya pribadi dan harus selalu terbaru.
 */
const API = process.env.API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1';
const WEB_ORIGIN = process.env.WEB_ORIGIN ?? 'http://localhost:3000';

export type User = {
  id: string; name: string; email: string | null; phone: string | null; status: string;
  two_factor_enabled: boolean; platform_role: 'super_admin' | 'platform_admin' | null;
  last_login_at: string | null; email_verified: boolean; phone_verified: boolean;
};
export type TenantContext = { id: string; brand_name: string; role: 'owner' | 'admin' | 'finance'; tenant_status: string; permissions: string[] };
export type Invitation = { member_id: string; tenant_id: string; brand_name: string; role: string; invited_at: string | null };
export type Contexts = {
  user: User; tenants: TenantContext[]; invitations: Invitation[];
  agent: { id: string; code: string; active_partnerships: number } | null;
  platform_role: User['platform_role']; platform_permissions: string[];
  two_factor_required: boolean; contact_verified: boolean;
};

export class ServerApiError extends Error {
  constructor(public status: number, public code: string, message: string) {
    super(message);
  }
}

export async function serverApi<T>(path: string): Promise<T> {
  const jar = await cookies();
  const h = await headers();
  const res = await fetch(`${API}${path}`, {
    headers: {
      Accept: 'application/json',
      Cookie: jar.toString(),
      Referer: `${WEB_ORIGIN}/`,
      'X-Forwarded-For': h.get('x-forwarded-for') ?? '',
    },
    cache: 'no-store',
  });
  const body = await res.json().catch(() => null);
  if (!res.ok) {
    throw new ServerApiError(res.status, body?.error?.code ?? 'server_error', body?.error?.message ?? 'Terjadi kendala di server.');
  }
  return body as T;
}

/** Konteks pengguna; null bila belum masuk. */
export async function getContexts(): Promise<Contexts | null> {
  const jar = await cookies();
  if (!jar.has('umrah-connect-session')) return null;
  try {
    return (await serverApi<{ data: Contexts }>('/me/contexts')).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 401) return null;
    throw e;
  }
}

/** Wajib masuk; selain itu diarahkan ke /masuk?next=… */
export async function requireContexts(next: string): Promise<Contexts> {
  const ctx = await getContexts();
  if (!ctx) redirect(`/masuk?next=${encodeURIComponent(next)}`);
  return ctx;
}
