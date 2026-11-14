import 'server-only';
import { cookies } from 'next/headers';
import { connection } from 'next/server';
import { getReferral } from './api';

/** Kode referral dari cookie uc_ref bila belum lewat 30 hari (CR-01); server API tetap memvalidasi ulang. */
export async function activeReferral(tenantSlug: string): Promise<{ code: string; agentName: string; agentCode: string } | null> {
  const raw = (await cookies()).get('uc_ref')?.value;
  await connection();
  try {
    const parsed = raw ? JSON.parse(raw) : null;
    if (!parsed?.code || Date.now() - Number(parsed.at) > 30 * 864e5) return null;
    const ref = await getReferral(String(parsed.code));
    return ref && ref.tenant.slug === tenantSlug ? { code: String(parsed.code), agentName: ref.agent.display_name, agentCode: ref.agent.code } : null;
  } catch {
    return null;
  }
}
