import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { requireContexts, serverApi, ServerApiError } from '@/lib/session';
import type { BankAccount, Member, TenantDoc, TenantFull } from '@/lib/tenant-types';
import { tanggal } from '@/lib/format';
import { BankAccountsPanel, DocumentsPanel, OwnershipTransfer, SubmitVerification, TeamPanel, TenantProfileForm } from '../tenant-forms';
import { TenantNav } from '../tenant-nav';
import { DashboardCards } from '../dashboard-cards';

export const metadata = { title: 'Dasbor PPIU' };

const STATUS_COPY: Record<string, { tone: 'info' | 'warning' | 'danger' | 'success'; title: string; body: string }> = {
  draft: { tone: 'info', title: 'Lengkapi pendaftaran', body: 'Isi profil, unggah 5 dokumen legalitas, tambahkan rekening resmi, lalu kirim pengajuan verifikasi.' },
  pending_review: { tone: 'warning', title: 'Sedang ditinjau tim UMRAH CONNECT', body: 'Biasanya selesai dalam 2 hari kerja. Data terkunci selama peninjauan; kami kabari hasilnya lewat email.' },
  needs_revision: { tone: 'danger', title: 'Perlu revisi', body: 'Perbaiki data sesuai catatan peninjau, lalu kirim ulang pengajuan.' },
  verified: { tone: 'success', title: 'PPIU terverifikasi', body: 'PPIU Anda tampil di marketplace. Kelola paket, foto, keberangkatan, dan harga di tab Paket.' },
  rejected: { tone: 'danger', title: 'Pengajuan ditolak', body: 'Hubungi tim UMRAH CONNECT bila Anda ingin mengajukan keberatan.' },
  suspended: { tone: 'danger', title: 'PPIU ditangguhkan', body: 'Paket tidak tampil di marketplace selama penangguhan. Hubungi tim UMRAH CONNECT.' },
};

async function Dashboard({ params, searchParams }: PageProps<'/dasbor/ppiu/[tenant]'>) {
  const { tenant: id } = await params;
  const sp = await searchParams;
  const ctx = await requireContexts(`/dasbor/ppiu/${id}`);
  const membership = ctx.tenants.find((t) => t.id === id);
  if (!membership) notFound();
  const can = (p: string) => membership.permissions.includes(p);
  const isOwner = membership.role === 'owner';

  let tenant: TenantFull;
  try {
    tenant = (await serverApi<{ data: TenantFull }>(`/tenants/${id}`)).data;
  } catch (e) {
    if (e instanceof ServerApiError && e.code === 'two_factor_required') {
      return (
        <div className="mx-auto max-w-3xl px-4 py-10">
          <Alert tone="warning" title="Aktifkan verifikasi dua langkah (2FA)">
            Sebagai {membership.role === 'owner' ? 'Owner' : 'Finance'}, Anda wajib memakai 2FA untuk membuka dasbor {membership.brand_name}.{' '}
            <Link href="/akun#keamanan" className="font-bold text-primary-700 underline">Atur 2FA di halaman Akun</Link>
          </Alert>
        </div>
      );
    }
    if (e instanceof ServerApiError && e.status === 404) notFound();
    throw e;
  }

  const [docs, banks, members] = await Promise.all([
    can('tenant.documents.manage') ? serverApi<{ data: TenantDoc[] }>(`/tenants/${id}/documents`).then((r) => r.data) : Promise.resolve(null),
    can('bank_account.view') ? serverApi<{ data: BankAccount[] }>(`/tenants/${id}/bank-accounts`).then((r) => r.data) : Promise.resolve(null),
    can('member.view') ? serverApi<{ data: Member[] }>(`/tenants/${id}/members`).then((r) => r.data) : Promise.resolve(null),
  ]);

  const st = tenant.verification_status;
  const editable = ['draft', 'needs_revision'].includes(st);
  const ob = tenant.onboarding;
  const steps = [
    { label: 'Profil PPIU', done: ob.profile_complete && ob.license_valid, href: '#profil' },
    { label: 'Dokumen legalitas', done: ob.documents_missing.length === 0, href: '#dokumen' },
    { label: 'Rekening resmi', done: ob.has_bank_account, href: '#rekening' },
  ];
  const copy = STATUS_COPY[st] ?? STATUS_COPY.draft;

  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-end justify-between gap-4 px-4 pt-8">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Dasbor PPIU · {membership.role === 'owner' ? 'Owner' : membership.role === 'admin' ? 'Admin' : 'Finance'}</p>
            <h1 className="mt-1 text-3xl font-extrabold">{tenant.brand_name}</h1>
            <p className="mt-1 text-white/85">{tenant.legal_name} · Izin {tenant.ppiu_license_no}</p>
          </div>
          <div className="rounded-[10px] bg-white px-3 py-2"><StatusPill status={st} /></div>
        </div>
        <div className="mx-auto max-w-6xl px-4"><TenantNav tenantId={id} active="ringkasan" permissions={membership.permissions} /></div>
      </section>

      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 lg:grid-cols-[1fr_320px]">
        <div className="space-y-6">
          {st === 'verified' && can('report.sales.view') && <DashboardCards tenantId={id} />}
          {sp.baru === '1' && st === 'draft' && <Alert tone="success" title="PPIU tersimpan sebagai draf">Lanjutkan dengan mengunggah dokumen legalitas dan menambahkan rekening resmi.</Alert>}
          <Alert tone={copy.tone} title={copy.title}>
            {copy.body}
            {tenant.status_note && st !== 'verified' && <p className="mt-2 font-semibold">Catatan peninjau: {tenant.status_note}</p>}
          </Alert>

          <section id="profil" className="card scroll-mt-24 p-5 md:p-6">
            <h2 className="text-xl font-extrabold">Profil PPIU</h2>
            <div className="mt-4"><TenantProfileForm tenant={tenant} readOnly={!can('tenant.profile.update') || !['draft', 'needs_revision', 'verified'].includes(st)} /></div>
          </section>

          {docs && (
            <section id="dokumen" className="card scroll-mt-24 p-5 md:p-6">
              <h2 className="text-xl font-extrabold">Dokumen legalitas</h2>
              <p className="mt-1 text-ink-600">PDF, JPG, atau PNG, maksimal 5 MB per file. Hanya tim PPIU Anda dan peninjau UMRAH CONNECT yang dapat melihatnya.</p>
              <DocumentsPanel tenantId={id} docs={docs} editable={editable || st === 'verified'} />
            </section>
          )}

          {banks && (
            <section id="rekening" className="card scroll-mt-24 p-5 md:p-6">
              <h2 className="text-xl font-extrabold">Rekening resmi</h2>
              <p className="mt-1 text-ink-600">Jamaah mentransfer langsung ke rekening ini. Rekening atas nama badan usaha, diverifikasi tim UMRAH CONNECT sebelum dipakai.</p>
              <div className="mt-4"><BankAccountsPanel tenantId={id} accounts={banks} canManage={can('bank_account.manage') && st !== 'pending_review'} /></div>
            </section>
          )}

          {members && (
            <section id="tim" className="card scroll-mt-24 p-5 md:p-6">
              <h2 className="text-xl font-extrabold">Tim</h2>
              <div className="mt-2"><TeamPanel tenantId={id} members={members} canManage={can('member.manage')} meId={ctx.user.id} /></div>
              {can('ownership.transfer') && <div className="mt-4"><OwnershipTransfer tenantId={id} brandName={tenant.brand_name} members={members} meId={ctx.user.id} twoFactorEnabled={ctx.user.two_factor_enabled} /></div>}
            </section>
          )}
        </div>

        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card p-5">
            <h2 className="text-lg font-extrabold">Langkah verifikasi</h2>
            <ol className="mt-3 space-y-2">
              {steps.map((s, i) => (
                <li key={s.label}>
                  <a href={s.href} className="flex min-h-12 items-center gap-3 rounded-[10px] px-2 hover:bg-primary-50">
                    <span aria-hidden="true" className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold ${s.done ? 'bg-success text-white' : 'border-2 border-border-input text-ink-600'}`}>{s.done ? '✓' : i + 1}</span>
                    <span className="font-semibold">{s.label}</span>
                    <span className="sr-only">{s.done ? '(selesai)' : '(belum)'}</span>
                  </a>
                </li>
              ))}
            </ol>
            {ob.documents_missing.length > 0 && editable && (
              <p className="mt-2 text-sm text-ink-600">Dokumen kurang: {ob.documents_missing.length} dari 5.</p>
            )}
            {!ob.license_valid && <p className="mt-2 text-sm font-semibold text-danger">Masa berlaku izin PPIU sudah lewat.</p>}
            <div className="mt-4">
              {editable && isOwner && <SubmitVerification tenantId={id} ready={ob.can_submit} />}
              {editable && !isOwner && <p className="text-sm text-ink-600">Hanya Owner yang dapat mengirim pengajuan verifikasi.</p>}
              {st === 'pending_review' && <p className="text-sm text-ink-600">Pengajuan terkirim. Tidak ada yang perlu dilakukan sampai hasil tinjauan keluar.</p>}
              {st === 'verified' && tenant.verified_at && (
                <p className="text-sm text-ink-600">Terverifikasi sejak {tanggal(tenant.verified_at.slice(0, 10))}. <Link href={`/ppiu/${tenant.slug}`} className="font-semibold text-primary-700 underline">Lihat profil publik</Link></p>
              )}
            </div>
          </div>
        </aside>
      </div>
    </>
  );
}

export default function TenantDashboardPage(props: PageProps<'/dasbor/ppiu/[tenant]'>) {
  return (
    <Suspense fallback={<div className="mx-auto max-w-6xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}>
      <Dashboard {...props} />
    </Suspense>
  );
}
