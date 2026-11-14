import Link from 'next/link';
import { Suspense } from 'react';
import { Cover } from '@/components/ui';
import { rupiah, tanggal } from '@/lib/format';
import { serverApi } from '@/lib/session';
import type { AgentPackage, Partnership, ReferralLink } from '@/lib/agent-types';
import { AgentHeader, requireAgent } from '../agent-shell';
import { CreateLinkButton, LinkTools } from '../agent-forms';

export const metadata = { title: 'Paket & link' };

async function Packages() {
  const ctx = await requireAgent('/agen/paket');
  const [pkgs, links, parts] = await Promise.all([
    serverApi<{ data: AgentPackage[] }>('/agent/packages?limit=100').then((r) => r.data),
    serverApi<{ data: ReferralLink[] }>('/agent/referral-links').then((r) => r.data),
    serverApi<{ data: Partnership[] }>('/agent/partnerships?filter[status]=active').then((r) => r.data),
  ]);
  const linkFor = (tenantId: string, packageId: string | null) => links.find((l) => l.tenant_id === tenantId && l.package_id === packageId && l.is_active);
  const share = (p: AgentPackage) => `Assalamu'alaikum. Info ${p.name} dari ${p.tenant.brand_name} (PPIU resmi), mulai ${p.price_from_amount ? rupiah(p.price_from_amount) : ''}. Detail & jadwal:`;

  return (
    <>
      <AgentHeader ctx={ctx} active="paket" title="Paket & link" sub="Bagikan link berkode Anda. Jamaah yang mendaftar lewat link ini tercatat sebagai jamaah Anda selama 30 hari (klik terakhir)." />
      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        {parts.length === 0 ? (
          <div className="card p-8 text-center">
            <p className="text-lg font-bold">Belum ada PPIU mitra aktif</p>
            <p className="mt-1 text-ink-600">Paket dan link tersedia setelah kemitraan disetujui.</p>
            <Link href="/agen/mitra" className="btn btn-primary mt-4">Ajukan kemitraan</Link>
          </div>
        ) : (
          <>
            <section aria-labelledby="ppiu-link-h" className="card p-5">
              <h2 id="ppiu-link-h" className="text-xl font-extrabold">Link profil PPIU</h2>
              <p className="mt-1 text-ink-600">Untuk calon jamaah yang belum memilih paket.</p>
              <ul className="mt-3 space-y-4">
                {parts.map((p) => {
                  const l = linkFor(p.tenant.id, null);
                  return (
                    <li key={p.id} className="space-y-2 border-t border-border-subtle pt-3 first:border-0 first:pt-0">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-bold">{p.tenant.brand_name}</p>
                        {l ? <span className="num text-sm text-ink-600">{l.click_count} klik</span> : <CreateLinkButton tenantId={p.tenant.id} label="Buat link profil" />}
                      </div>
                      {l && <LinkTools link={l} shareText={`Assalamu'alaikum. Paket umrah ${p.tenant.brand_name} (PPIU resmi) bisa dilihat di sini:`} />}
                    </li>
                  );
                })}
              </ul>
            </section>
            <section aria-labelledby="pkg-h">
              <h2 id="pkg-h" className="text-xl font-extrabold">Paket dari PPIU mitra</h2>
              {pkgs.length === 0 && <p className="mt-2 text-ink-600">PPIU mitra belum menerbitkan paket.</p>}
              <ul className="mt-3 grid gap-4 lg:grid-cols-2">
                {pkgs.map((p) => {
                  const l = linkFor(p.tenant.id, p.id);
                  return (
                    <li key={p.id} className="card overflow-hidden">
                      <div className="grid sm:grid-cols-[140px_1fr]">
                        <Cover url={p.cover_image_url} title={p.name} className="h-28 sm:h-full" />
                        <div className="space-y-1 p-4">
                          <p className="text-sm font-semibold text-gold-700">{p.tenant.brand_name}</p>
                          <Link href={`/paket/${p.tenant.slug}/${p.slug}`} target="_blank" className="text-lg font-bold hover:text-primary-700">{p.name}</Link>
                          <p className="text-sm text-ink-600">{p.duration_days} hari · {p.airline}{p.next_departure && <> · berangkat {tanggal(p.next_departure.departure_date)} (sisa {p.next_departure.seats_remaining})</>}</p>
                          <p className="text-sm">Mulai <span className="num font-bold text-primary-800">{p.price_from_amount ? rupiah(p.price_from_amount) : '—'}</span></p>
                          <p className="inline-block rounded-md bg-gold-100 px-2 py-0.5 text-sm font-semibold text-gold-700">Komisi Anda: {p.commission_scheme?.label ?? '—'}</p>
                        </div>
                      </div>
                      <div className="border-t border-border-subtle p-4">
                        {l ? <LinkTools link={l} shareText={share(p)} /> : <CreateLinkButton tenantId={p.tenant.id} packageId={p.id} label="Buat link paket" />}
                      </div>
                    </li>
                  );
                })}
              </ul>
            </section>
          </>
        )}
      </div>
    </>
  );
}

export default function AgentPackagesPage() {
  return <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Packages /></Suspense>;
}
