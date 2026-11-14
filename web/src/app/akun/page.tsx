import Link from 'next/link';
import { Suspense } from 'react';
import { Alert, StatusPill } from '@/components/form';
import { requireContexts, serverApi } from '@/lib/session';
import { DevicesPanel, PrivacyPanel, type DeviceSession } from './security-privacy';
import { InvitationActions, LogoutButton, ProfileForm, TwoFactorSetup } from './account-actions';
import { IconDevices, IconHandshake, IconLock, IconMail, IconOffice, IconPiggy, IconPlus, IconShieldCheck, IconTicket, IconUser, IconWork } from '@/components/icons';
import { IconHeading } from '@/components/stat-card';

export const metadata = { title: 'Akun saya' };

const ROLE_LABEL: Record<string, string> = { owner: 'Owner', admin: 'Admin', finance: 'Finance' };

function WsIcon({ icon: Icon, tone = 'primary' }: { icon: (p: { className?: string }) => React.ReactNode; tone?: 'primary' | 'gold' }) {
  return <span className={`mb-1 flex h-10 w-10 items-center justify-center rounded-[10px] ${tone === 'gold' ? 'bg-gold-100 text-gold-700' : 'bg-primary-50 text-primary-700'}`}><Icon className="h-5 w-5" /></span>;
}

async function Account() {
  const ctx = await requireContexts('/akun');
  const u = ctx.user;
  const [sessions, deletion] = await Promise.all([
    serverApi<{ data: DeviceSession[] }>('/me/sessions').then((r) => r.data).catch(() => [] as DeviceSession[]),
    serverApi<{ data: { blockers: string[] } }>('/me/account/deletion').then((r) => r.data.blockers).catch(() => [] as string[]),
  ]);
  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-end justify-between gap-4 px-4 py-10">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Akun saya</p>
            <h1 className="mt-1 text-3xl font-extrabold">Halo, {u.name}</h1>
            <p className="mt-1 text-white/85">{u.email ?? u.phone}</p>
          </div>
          <LogoutButton className="btn border border-white/60 text-white hover:bg-white/10" />
        </div>
      </section>

      <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
        {!ctx.contact_verified && (
          <Alert tone="warning" title="Kontak belum diverifikasi">
            Verifikasi {u.email ? 'email' : 'nomor HP'} Anda untuk mendaftarkan PPIU atau melakukan pemesanan.{' '}
            <Link href="/verifikasi?next=/akun" className="font-bold text-primary-700 underline">Verifikasi sekarang</Link>
          </Alert>
        )}
        {ctx.two_factor_required && (
          <Alert tone="warning" title="Aktifkan verifikasi dua langkah (2FA)">
            Peran Anda wajib memakai 2FA sebelum bisa membuka dasbor. <a href="#keamanan" className="font-bold text-primary-700 underline">Atur sekarang</a>
          </Alert>
        )}

        {ctx.invitations.length > 0 && (
          <section className="card p-5" aria-labelledby="undangan">
            <IconHeading icon={IconMail} id="undangan">Undangan tim</IconHeading>
            <ul className="mt-3 divide-y divide-border-subtle">
              {ctx.invitations.map((i) => (
                <li key={i.member_id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <p><span className="font-bold">{i.brand_name}</span> mengundang Anda sebagai <span className="font-bold">{ROLE_LABEL[i.role] ?? i.role}</span>.</p>
                  <InvitationActions memberId={i.member_id} />
                </li>
              ))}
            </ul>
          </section>
        )}

        <section aria-labelledby="ruang-kerja">
          <IconHeading icon={IconWork} id="ruang-kerja">Ruang kerja</IconHeading>
          <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {ctx.platform_role && (
              <Link href="/admin" className="card group flex flex-col gap-1 p-5 hover:border-primary-600">
                <WsIcon icon={IconShieldCheck} />
                <span className="text-sm font-semibold text-gold-700">Platform · {ctx.platform_role === 'super_admin' ? 'Super Admin' : 'Admin Platform'}</span>
                <span className="text-lg font-extrabold group-hover:text-primary-700">Dasbor platform</span>
                <span className="text-ink-600">Verifikasi PPIU, angka global, audit log, akses dukungan.</span>
              </Link>
            )}
            {ctx.tenants.map((t) => (
              <Link key={t.id} href={`/dasbor/ppiu/${t.id}`} className="card group flex flex-col gap-2 p-5 hover:border-primary-600">
                <WsIcon icon={IconOffice} />
                <span className="text-sm font-semibold text-gold-700">PPIU · {ROLE_LABEL[t.role]}</span>
                <span className="text-lg font-extrabold group-hover:text-primary-700">{t.brand_name}</span>
                <span><StatusPill status={t.tenant_status} /></span>
              </Link>
            ))}
            <Link href="/dasbor/ppiu/daftar" className="flex flex-col gap-1 rounded-[12px] border border-dashed border-primary-600 bg-primary-50 p-5 hover:bg-primary-100">
              <WsIcon icon={IconPlus} />
              <span className="text-sm font-semibold text-primary-700">Untuk travel berizin</span>
              <span className="text-lg font-extrabold text-primary-800">Daftarkan PPIU</span>
              <span className="text-ink-600">Isi profil, unggah legalitas, lalu ajukan verifikasi.</span>
            </Link>
            {ctx.agent ? (
              <Link href="/agen" className="card group flex flex-col gap-1 p-5 hover:border-primary-600">
                <WsIcon icon={IconHandshake} />
                <span className="text-sm font-semibold text-gold-700">Agen · kode {ctx.agent.code}</span>
                <span className="text-lg font-extrabold group-hover:text-primary-700">Dasbor agen</span>
                <span className="text-ink-600">{ctx.agent.active_partnerships} PPIU mitra aktif. Bagikan link paket.</span>
              </Link>
            ) : (
              <Link href="/agen/daftar" className="flex flex-col gap-1 rounded-[12px] border border-dashed border-gold-600 bg-gold-50 p-5 hover:bg-gold-100">
                <WsIcon icon={IconHandshake} tone="gold" />
                <span className="text-sm font-semibold text-gold-700">Untuk agen pemasaran</span>
                <span className="text-lg font-extrabold text-primary-800">Jadi agen</span>
                <span className="text-ink-600">Bermitra dengan PPIU dan terima komisi resmi.</span>
              </Link>
            )}
            <Link href="/akun/tabungan" className="card group flex flex-col gap-1 p-5 hover:border-primary-600">
              <WsIcon icon={IconPiggy} tone="gold" />
              <span className="text-sm font-semibold text-gold-700">Persiapan</span>
              <span className="text-lg font-extrabold group-hover:text-primary-700">Tabungan umrah</span>
              <span className="text-ink-600">Target, setoran per bulan, dan pengingat.</span>
            </Link>
            <Link href="/akun/booking" className="card group flex flex-col gap-1 p-5 hover:border-primary-600">
              <WsIcon icon={IconTicket} />
              <span className="text-sm font-semibold text-gold-700">Jamaah</span>
              <span className="text-lg font-extrabold group-hover:text-primary-700">Booking saya</span>
              <span className="text-ink-600">Status booking, tagihan DP dan pelunasan, rekening tujuan.</span>
            </Link>
          </div>
        </section>

        <div className="grid gap-6 lg:grid-cols-2">
          <section className="card p-5" aria-labelledby="profil">
            <IconHeading icon={IconUser} id="profil">Profil</IconHeading>
            <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-ink-600">
              {u.email && <><dt>Email</dt><dd className="text-ink-900">{u.email} {u.email_verified ? '· terverifikasi' : '· belum diverifikasi'}</dd></>}
              {u.phone && <><dt>Nomor HP</dt><dd className="text-ink-900">{u.phone} {u.phone_verified ? '· terverifikasi' : '· belum diverifikasi'}</dd></>}
            </dl>
            <div className="mt-4"><ProfileForm name={u.name} /></div>
          </section>
          <section id="keamanan" className="card scroll-mt-24 p-5" aria-labelledby="keamanan-h">
            <IconHeading icon={IconLock} id="keamanan-h">Keamanan</IconHeading>
            <p className="mt-1 text-ink-600">Verifikasi dua langkah (2FA) melindungi akun walau kata sandi bocor. Wajib untuk Owner, Finance, dan staf platform.</p>
            <div className="mt-4"><TwoFactorSetup enabled={u.two_factor_enabled} /></div>
          </section>
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          <section id="perangkat" className="card scroll-mt-24 p-5" aria-labelledby="perangkat-h">
            <IconHeading icon={IconDevices} id="perangkat-h">Perangkat yang sedang masuk</IconHeading>
            <div className="mt-3"><DevicesPanel sessions={sessions} /></div>
          </section>
          <section id="privasi" className="card scroll-mt-24 p-5" aria-labelledby="privasi-h">
            <IconHeading icon={IconShieldCheck} id="privasi-h">Privasi &amp; data saya</IconHeading>
            <p className="mt-1 text-ink-600">Hak Anda menurut UU Pelindungan Data Pribadi. Baca <Link href="/privasi" className="font-semibold text-primary-700 underline">Kebijakan Privasi</Link>.</p>
            <div className="mt-4"><PrivacyPanel blockers={deletion} twoFactor={u.two_factor_enabled} /></div>
          </section>
        </div>
      </div>
    </>
  );
}

export default function AccountPage() {
  return (
    <Suspense fallback={<div className="mx-auto h-96 max-w-5xl px-4 py-10"><div className="h-full animate-pulse rounded-[12px] bg-white" /></div>}>
      <Account />
    </Suspense>
  );
}
