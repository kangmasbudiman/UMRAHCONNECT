import 'server-only';
import { cookies } from 'next/headers';
import { connection } from 'next/server';
import { getPublicConfig, getReferral } from './api';

/** Kode referral dari cookie uc_ref bila belum lewat jendela atribusi (pengaturan platform, bawaan 30 hari; CR-01). */
export async function activeReferral(tenantSlug: string): Promise<{ code: string; agentName: string; agentCode: string } | null> {
  const raw = (await cookies()).get('uc_ref')?.value;
  await connection();
  try {
    const parsed = raw ? JSON.parse(raw) : null;
    const { attribution_window_days: days } = await getPublicConfig();
    if (!parsed?.code || Date.now() - Number(parsed.at) > days * 864e5) return null;
    const ref = await getReferral(String(parsed.code));
    return ref && ref.tenant.slug === tenantSlug ? { code: String(parsed.code), agentName: ref.agent.display_name, agentCode: ref.agent.code } : null;
  } catch {
    return null;
  }
}
