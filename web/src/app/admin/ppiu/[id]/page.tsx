import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { tanggal } from '@/lib/format';
import { requireContexts, serverApi, ServerApiError } from '@/lib/session';
import type { BankAccount, Member, TenantDoc, TenantFull } from '@/lib/tenant-types';
import { ReviewPanel } from './review-panel';
import { SupportPanel, type SupportSession } from './support-panel';
import { ContractForm } from '../../pendapatan/revenue-actions';
import { IconHeading } from '@/components/stat-card';
import { IconHistory, IconOffice, IconSupport } from '@/components/icons';

export const metadata = { title: 'Tinjau PPIU' };

type Detail = TenantFull & {
  documents: TenantDoc[]; bank_accounts: BankAccount[]; members: Member[];
  history: { action: string; new_values: Record<string, string> | null; reason: string | null; actor_context: string; created_at: string }[];
};

const ACTION_LABEL: Record<string, string> = {
  'tenant.registered': 'Didaftarkan', 'tenant.verification_submitted': 'Pengajuan dikirim',
  'tenant.verification_decided': 'Keputusan verifikasi', 'tenant.suspended': 'Ditangguhkan', 'tenant.restored': 'Dipulihkan',
};

async function Review({ params }: PageProps<'/admin/ppiu/[id]'>) {
  const { id } = await params;
  const ctx = await requireContexts(`/admin/ppiu/${id}`);
  if (!ctx.platform_role) notFound();
  let t: Detail;
  try {
    t = (await serverApi<{ data: Detail }>(`/platform/tenants/${id}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.status === 404) notFound();
    if (e instanceof ServerApiError && e.code === 'two_factor_required') {
      return <div className="mx-auto max-w-3xl px-4 py-10"><Alert tone="warning" title="Aktifkan 2FA">Staf platform wajib memakai 2FA. <Link href="/akun#keamanan" className="font-bold text-primary-700 underline">Atur di halaman Akun</Link></Alert></div>;
    }
    throw e;
  }
  const perms = ctx.platform_permissions;
  const allows = (p: string) => perms.includes('platform.*') || perms.includes(p);
  const sessions = allows('platform.support_access') ? (await serverApi<{ data: SupportSession[] }>('/platform/support-sessions')).data : [];
  type Contract = { plan: { code: string; name: string }; subscription: { status: string; price_override: number | null } | null; transaction_fee_amount: number | null; default_transaction_fee_amount: number };
  const contract = ctx.platform_role === 'super_admin' ? await serverApi<{ data: Contract }>(`/platform/tenants/${id}/billing`).then((r) => r.data).catch(() => null) : null;
  const owner = t.members.find((m) => m.role === 'owner');
  const rows: [string, string | null][] = [
    ['Nama badan usaha', t.legal_name], ['Nomor izin PPIU', t.ppiu_license_no],
    ['Izin berlaku s.d.', t.license_expires_on ? tanggal(t.license_expires_on) : null], ['NIB', t.nib], ['NPWP', t.npwp],
    ['Alamat', [t.address, t.city, t.province].filter(Boolean).join(', ')], ['Telepon', t.phone], ['WhatsApp', t.whatsapp], ['Email', t.email],
    ['Owner', owner ? `${owner.user.name} · ${owner.user.email ?? owner.user.phone}` : null],
  ];

  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-end justify-between gap-4 px-4 py-8">
          <div>
            <Link href="/admin/ppiu" className="text-sm font-semibold text-gold-500 hover:underline">← Antrean verifikasi</Link>
            <h1 className="mt-1 text-3xl font-extrabold">{t.brand_name}</h1>
            <p className="mt-1 text-white/85">Kode {t.code} · didaftarkan {t.created_at ? tanggal(t.created_at.slice(0, 10)) : '—'}</p>
          </div>
          <div className="rounded-[10px] bg-white px-3 py-2"><StatusPill status={t.verification_status} /></div>
        </div>
      </section>
      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 lg:grid-cols-[1fr_360px]">
        <ReviewPanel tenantId={t.id} status={t.verification_status} docs={t.documents} banks={t.bank_accounts}
          canVerify={allows('platform.tenant.verify')} canSuspend={allows('platform.tenant.suspend')} />
        <aside className="space-y-6">
          {contract && (
            <section className="card p-5" aria-labelledby="ct-h">
              <h2 id="ct-h" className="text-lg font-extrabold">Kontrak &amp; langganan</h2>
              <p className="mt-1 text-sm text-ink-600">Saat ini: <strong>{contract.plan.name}</strong>{contract.subscription?.status === 'past_due' && <span className="text-danger"> (telat bayar)</span>}</p>
              <div className="mt-3"><ContractForm tenantId={t.id} plan={contract.plan.code} priceOverride={contract.subscription?.price_override ?? null} fee={contract.transaction_fee_amount} defaultFee={contract.default_transaction_fee_amount} /></div>
            </section>
          )}
          {allows('platform.support_access') && (
            <section className="card p-5" aria-labelledby="sp-h">
              <IconHeading icon={IconSupport} id="sp-h" className="text-lg font-extrabold">Akses dukungan</IconHeading>
              <div className="mt-3"><SupportPanel tenantId={t.id} session={sessions.find((s) => s.tenant_id === t.id && s.active) ?? null} /></div>
            </section>
          )}
          <section className="card p-5">
            <IconHeading icon={IconOffice} className="text-lg font-extrabold">Profil</IconHeading>
            <dl className="mt-3 space-y-2">
              {rows.map(([k, v]) => (
                <div key={k}><dt className="text-sm text-ink-600">{k}</dt><dd className="font-semibold break-words">{v || '—'}</dd></div>
              ))}
            </dl>
            {!t.onboarding.license_valid && <p className="mt-3 font-semibold text-danger">Izin sudah tidak berlaku.</p>}
          </section>
          <section className="card p-5">
            <IconHeading icon={IconHistory} className="text-lg font-extrabold">Riwayat status</IconHeading>
            <ol className="mt-3 space-y-3 border-l-2 border-border-subtle pl-4">
              {t.history.map((h, i) => (
                <li key={i}>
                  <p className="font-semibold">{ACTION_LABEL[h.action] ?? h.action}{h.new_values?.verification_status && <> → <StatusPill status={h.new_values.verification_status} /></>}</p>
                  <p className="text-sm text-ink-600">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(h.created_at))} WIB · {h.actor_context === 'platform' ? 'Platform' : 'PPIU'}</p>
                  {h.reason && <p className="text-sm">“{h.reason}”</p>}
                </li>
              ))}
            </ol>
          </section>
        </aside>
      </div>
    </>
  );
}

export default function AdminTenantPage(props: PageProps<'/admin/ppiu/[id]'>) {
  return (
    <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}>
      <Review {...props} />
    </Suspense>
  );
}
