'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner, StatusPill } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { DOC_LABEL, DOC_TYPES, type BankAccount, type Member, type TenantDoc, type TenantFull } from '@/lib/tenant-types';

const FIELDS: { name: keyof TenantFull; label: string; type?: string; hint?: string; optional?: boolean; span?: boolean; locked?: boolean; autoComplete?: string }[] = [
  { name: 'brand_name', label: 'Nama brand (tampil di marketplace)', hint: 'Contoh: Amanah Baitullah Tour' },
  { name: 'legal_name', label: 'Nama badan usaha', hint: 'Sesuai akta, contoh: PT Amanah Baitullah Jambi', locked: true },
  { name: 'ppiu_license_no', label: 'Nomor izin PPIU', locked: true },
  { name: 'license_expires_on', label: 'Izin berlaku sampai', type: 'date', locked: true },
  { name: 'nib', label: 'NIB', optional: true },
  { name: 'npwp', label: 'NPWP', optional: true },
  { name: 'address', label: 'Alamat kantor', span: true, autoComplete: 'street-address' },
  { name: 'city', label: 'Kota/Kabupaten', autoComplete: 'address-level2' },
  { name: 'province', label: 'Provinsi', autoComplete: 'address-level1' },
  { name: 'phone', label: 'Telepon kantor', type: 'tel', hint: 'Contoh: 0741 123456' },
  { name: 'whatsapp', label: 'WhatsApp layanan', type: 'tel', optional: true, hint: 'Tampil di halaman paket untuk calon jamaah' },
  { name: 'email', label: 'Email kantor', type: 'email' },
];

/** PPIU-01 Profil PPIU: dipakai untuk daftar baru (tenant = null) dan ubah profil. */
export function TenantProfileForm({ tenant, readOnly = false }: { tenant: TenantFull | null; readOnly?: boolean }) {
  const router = useRouter();
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const licenseLocked = tenant ? ['verified', 'suspended'].includes(tenant.verification_status) : false;

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    const body: Record<string, string | null> = {};
    for (const f of FIELDS) {
      if (licenseLocked && f.locked) continue;
      const v = String(fd.get(f.name) ?? '').trim();
      body[f.name] = v === '' ? null : v;
    }
    setBusy(true);
    setError(null);
    setSaved(false);
    try {
      if (tenant) {
        await api(`/tenants/${tenant.id}`, { method: 'PATCH', json: body });
        setSaved(true);
        router.refresh();
      } else {
        const r = await api<{ data: { id: string } }>('/tenants', { json: body });
        router.replace(`/dasbor/ppiu/${r.data.id}?baru=1`);
        router.refresh();
      }
    } catch (err) {
      setError(err instanceof ApiClientError ? err : errorMessage(err));
    }
    setBusy(false);
  }

  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
      {apiErr && <Alert tone="danger">{apiErr.message}</Alert>}
      {saved && <Alert tone="success">Profil tersimpan.</Alert>}
      {licenseLocked && <Alert tone="info">Nama badan usaha dan data izin terkunci setelah terverifikasi. Hubungi tim UMRAH CONNECT bila izin diperbarui.</Alert>}
      <fieldset disabled={readOnly} className="grid gap-4 md:grid-cols-2">
        {FIELDS.map((f) => (
          <div key={f.name} className={f.span ? 'md:col-span-2' : ''}>
            <Field id={`t-${f.name}`} label={f.label} hint={f.hint} optional={f.optional} error={apiErr?.field(f.name)}>
              {f.name === 'address' ? (
                <textarea id={`t-${f.name}`} name={f.name} rows={2} className="input" defaultValue={(tenant?.[f.name] as string) ?? ''} required autoComplete={f.autoComplete} />
              ) : (
                <input id={`t-${f.name}`} name={f.name} type={f.type ?? 'text'} className="input disabled:bg-surface"
                  defaultValue={(tenant?.[f.name] as string) ?? (f.name === 'province' && !tenant ? 'Jambi' : '')}
                  disabled={licenseLocked && f.locked} required={!f.optional} autoComplete={f.autoComplete} />
              )}
            </Field>
          </div>
        ))}
      </fieldset>
      {!readOnly && (
        <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}{tenant ? 'Simpan profil' : 'Daftarkan PPIU'}</button>
      )}
    </form>
  );
}

/** PPIU-02 Dokumen legalitas: unggah PDF/JPG/PNG maks 5 MB per jenis. */
export function DocumentsPanel({ tenantId, docs, editable }: { tenantId: string; docs: TenantDoc[]; editable: boolean }) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const latest = new Map<string, TenantDoc>();
  for (const d of docs) if (!latest.has(d.type)) latest.set(d.type, d);

  async function upload(type: string, file: File | undefined) {
    if (!file) return;
    setBusy(type);
    setErrors((e) => ({ ...e, [type]: '' }));
    try {
      if (file.size > 5 * 1024 * 1024) throw new Error('Ukuran file maksimal 5 MB.');
      const fd = new FormData();
      fd.set('purpose', 'tenant_document');
      fd.set('file', file);
      const f = await api<{ data: { id: string } }>('/files', { form: fd });
      await api(`/tenants/${tenantId}/documents`, { json: { type, file_id: f.data.id } });
      router.refresh();
    } catch (e) {
      const msg = e instanceof ApiClientError ? (e.field('file') ?? e.message) : e instanceof Error ? e.message : errorMessage(e);
      setErrors((x) => ({ ...x, [type]: msg }));
    }
    setBusy(null);
  }

  return (
    <ul className="divide-y divide-border-subtle">
      {DOC_TYPES.map((t) => {
        const d = latest.get(t.type);
        return (
          <li key={t.type} className="flex flex-wrap items-center gap-3 py-4">
            <div className="min-w-0 flex-1">
              <p className="font-bold">{t.label}</p>
              <p className="text-sm text-ink-600">{t.hint}</p>
              {d && (
                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                  <StatusPill status={d.status} />
                  {d.file?.url ? <a href={d.file.url} target="_blank" rel="noopener" className="font-semibold text-primary-700 underline">{d.file_name ?? 'Lihat file'}</a> : <span>{d.file_name}</span>}
                </p>
              )}
              {d?.review_note && <p className="mt-1 text-sm font-semibold text-danger">Catatan peninjau: {d.review_note}</p>}
              {errors[t.type] && <p role="alert" className="mt-1 text-sm font-semibold text-danger">{errors[t.type]}</p>}
            </div>
            {editable && (
              <label className={`btn ${d ? 'btn-outline' : 'btn-primary'} cursor-pointer ${busy ? 'pointer-events-none opacity-70' : ''}`}>
                {busy === t.type && <Spinner />}{d ? 'Ganti file' : 'Unggah'}
                <input type="file" accept="application/pdf,image/jpeg,image/png" className="sr-only" disabled={!!busy}
                  aria-label={`Unggah ${t.label}`} onChange={(e) => { upload(t.type, e.currentTarget.files?.[0]); e.currentTarget.value = ''; }} />
              </label>
            )}
          </li>
        );
      })}
      {docs.filter((d) => d.type === 'other').slice(0, 1).map((d) => (
        <li key={d.id} className="py-3 text-sm">{DOC_LABEL.other}: {d.file_name}</li>
      ))}
    </ul>
  );
}

/** PPIU-03 Rekening resmi: rekening baru menunggu verifikasi platform. */
export function BankAccountsPanel({ tenantId, accounts, canManage }: { tenantId: string; accounts: BankAccount[]; canManage: boolean }) {
  const router = useRouter();
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [open, setOpen] = useState(accounts.length === 0);

  async function add(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    const form = e.currentTarget;
    setBusy('add');
    setError(null);
    try {
      await api(`/tenants/${tenantId}/bank-accounts`, { json: Object.fromEntries(fd) });
      form.reset();
      setOpen(false);
      router.refresh();
    } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
    setBusy(null);
  }
  async function patch(id: string, json: object) {
    setBusy(id);
    try { await api(`/tenants/${tenantId}/bank-accounts/${id}`, { method: 'PATCH', json }); router.refresh(); }
    catch (err) { setError(errorMessage(err)); }
    setBusy(null);
  }

  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <div className="space-y-4">
      {accounts.length > 0 && (
        <ul className="divide-y divide-border-subtle">
          {accounts.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 py-3">
              <div className="min-w-0 flex-1">
                <p className="font-bold">{a.bank_name} · <span className="num">{a.account_no}</span></p>
                <p className="text-sm text-ink-600">a.n. {a.account_holder}</p>
              </div>
              <StatusPill status={a.status} />
              {a.is_default && <span className="rounded-full bg-gold-100 px-2.5 py-0.5 text-sm font-bold text-gold-700">Rekening utama</span>}
              {canManage && a.status === 'verified' && !a.is_default && (
                <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" disabled={!!busy} onClick={() => patch(a.id, { is_default: true })}>Jadikan utama</button>
              )}
              {canManage && a.status !== 'inactive' && (
                <button type="button" className="min-h-10 px-2 text-sm font-semibold text-danger hover:underline" disabled={!!busy}
                  onClick={() => { if (confirm(`Nonaktifkan rekening ${a.bank_name} ${a.account_no}? Jamaah tidak akan diarahkan ke rekening ini lagi.`)) patch(a.id, { status: 'inactive' }); }}>
                  Nonaktifkan
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
      {canManage && (open ? (
        <form onSubmit={add} className="grid gap-4 rounded-[12px] bg-surface p-4 md:grid-cols-3" noValidate>
          {apiErr && !apiErr.errors && <div className="md:col-span-3"><Alert tone="danger">{apiErr.message}</Alert></div>}
          <Field id="b-bank" label="Bank" error={apiErr?.field('bank_name')}>
            <input id="b-bank" name="bank_name" className="input" list="bank-list" required placeholder="BSI, Mandiri, BRI…" />
          </Field>
          <Field id="b-no" label="Nomor rekening" error={apiErr?.field('account_no')}>
            <input id="b-no" name="account_no" className="input num" inputMode="numeric" required />
          </Field>
          <Field id="b-holder" label="Atas nama" hint="Harus atas nama badan usaha" error={apiErr?.field('account_holder')}>
            <input id="b-holder" name="account_holder" className="input" required />
          </Field>
          <datalist id="bank-list">{['BSI', 'Bank Mandiri', 'BRI', 'BNI', 'BCA', 'Bank Muamalat', 'Bank Jambi Syariah', 'CIMB Niaga Syariah'].map((b) => <option key={b} value={b} />)}</datalist>
          <div className="flex gap-2 md:col-span-3">
            <button className="btn btn-primary" disabled={busy === 'add'}>{busy === 'add' && <Spinner />}Simpan rekening</button>
            {accounts.length > 0 && <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={() => setOpen(false)}>Batal</button>}
          </div>
        </form>
      ) : (
        <button type="button" className="btn btn-outline" onClick={() => setOpen(true)}>+ Tambah rekening</button>
      ))}
    </div>
  );
}

const ROLE_LABEL: Record<string, string> = { owner: 'Owner', admin: 'Admin', finance: 'Finance' };

/** PPIU-12 Tim: undang Admin/Finance, ubah peran, nonaktifkan (FR-AUTH-07). */
export function TeamPanel({ tenantId, members, canManage, meId }: { tenantId: string; members: Member[]; canManage: boolean; meId: string }) {
  const router = useRouter();
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  async function call(key: string, fn: () => Promise<unknown>) {
    setBusy(key);
    setError(null);
    try { await fn(); router.refresh(); } catch (e) { setError(e instanceof ApiClientError ? e : errorMessage(e)); }
    setBusy(null);
  }
  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <div className="space-y-4">
      <ul className="divide-y divide-border-subtle">
        {members.map((m) => (
          <li key={m.id} className="flex flex-wrap items-center gap-3 py-3">
            <div className="min-w-0 flex-1">
              <p className="font-bold">{m.user.name}{m.user.id === meId && <span className="font-normal text-ink-600"> (Anda)</span>}</p>
              <p className="text-sm text-ink-600">{m.user.email ?? m.user.phone}</p>
            </div>
            {canManage && m.role !== 'owner' && m.status !== 'deactivated' ? (
              <select aria-label={`Peran ${m.user.name}`} className="input w-auto min-w-32" defaultValue={m.role} disabled={!!busy}
                onChange={(e) => call(m.id, () => api(`/tenants/${tenantId}/members/${m.id}`, { method: 'PATCH', json: { role: e.target.value } }))}>
                <option value="admin">Admin</option>
                <option value="finance">Finance</option>
              </select>
            ) : (
              <span className="font-semibold">{ROLE_LABEL[m.role]}</span>
            )}
            <StatusPill status={m.status} />
            {canManage && m.role !== 'owner' && m.status !== 'deactivated' && (
              <button type="button" className="min-h-10 px-2 text-sm font-semibold text-danger hover:underline" disabled={!!busy}
                onClick={() => { if (confirm(`Nonaktifkan ${m.user.name}? Aksesnya ke PPIU ini langsung berhenti.`)) call(m.id, () => api(`/tenants/${tenantId}/members/${m.id}/deactivate`, { method: 'POST' })); }}>
                Nonaktifkan
              </button>
            )}
          </li>
        ))}
      </ul>
      {apiErr && !apiErr.errors && <Alert tone="danger">{apiErr.message}</Alert>}
      {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
      {canManage && (
        <form className="grid gap-4 rounded-[12px] bg-surface p-4 md:grid-cols-[1fr_auto_auto] md:items-end" noValidate
          onSubmit={(e) => { e.preventDefault(); const form = e.currentTarget; const fd = new FormData(form);
            call('invite', async () => { await api(`/tenants/${tenantId}/members/invitations`, { json: Object.fromEntries(fd) }); form.reset(); }); }}>
          <Field id="inv-email" label="Undang anggota lewat email" hint="Yang diundang harus sudah punya akun UMRAH CONNECT." error={apiErr?.field('email')}>
            <input id="inv-email" name="email" type="email" className="input" required />
          </Field>
          <Field id="inv-role" label="Peran">
            <select id="inv-role" name="role" className="input" defaultValue="admin">
              <option value="admin">Admin (paket, booking)</option>
              <option value="finance">Finance (pembayaran, komisi)</option>
            </select>
          </Field>
          <button className="btn btn-primary" disabled={busy === 'invite'}>{busy === 'invite' && <Spinner />}Kirim undangan</button>
        </form>
      )}
    </div>
  );
}

export function SubmitVerification({ tenantId, ready }: { tenantId: string; ready: boolean }) {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  return (
    <div className="space-y-2">
      <button type="button" className="btn btn-gold w-full" disabled={!ready || busy}
        onClick={async () => {
          setBusy(true);
          setError(null);
          try { await api(`/tenants/${tenantId}/verification-submissions`, { method: 'POST' }); router.refresh(); }
          catch (e) { setError(errorMessage(e)); }
          setBusy(false);
        }}>
        {busy && <Spinner />}Kirim pengajuan verifikasi
      </button>
      {!ready && <p className="text-sm text-ink-600">Lengkapi semua langkah di atas untuk mengirim.</p>}
      {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}

/** Pengalihan kepemilikan (User Role §5): Owner menyerahkan peran ke anggota aktif; wajib kata sandi + kode 2FA. */
export function OwnershipTransfer({ tenantId, brandName, members, meId, twoFactorEnabled }: {
  tenantId: string; brandName: string; members: Member[]; meId: string; twoFactorEnabled: boolean;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState(false);
  const eligible = members.filter((m) => m.status === 'active' && m.role !== 'owner' && m.user.id !== meId);
  const apiErr = error instanceof ApiClientError ? error : null;

  if (!open) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-dashed border-border-input p-4">
        <p className="text-sm text-ink-600">Pindahkan peran Owner ke anggota tim lain. Anda akan menjadi Admin.</p>
        <button type="button" className="btn btn-outline" onClick={() => setOpen(true)} disabled={eligible.length === 0}
          title={eligible.length === 0 ? 'Belum ada anggota aktif yang bisa menerima' : undefined}>Alihkan kepemilikan</button>
      </div>
    );
  }
  return (
    <form className="space-y-3 rounded-[12px] border border-danger/40 bg-danger-bg/40 p-4" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      const target = eligible.find((m) => m.id === fd.get('member_id'));
      if (!target || !confirm(`Alihkan kepemilikan ${brandName} ke ${target.user.name}? Anda akan menjadi Admin dan tidak bisa membatalkannya sendiri.`)) return;
      setBusy(true);
      setError(null);
      try {
        await api(`/tenants/${tenantId}/ownership-transfer`, { json: { member_id: target.id, password: fd.get('password'), two_factor_code: String(fd.get('code') ?? '').trim() } });
        setOpen(false);
        router.refresh();
      } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
      setBusy(false);
    }}>
      <p className="font-bold">Alihkan kepemilikan</p>
      <p className="text-sm text-ink-600">Owner baru memegang semua akses, termasuk rekening, tim, dan pengalihan berikutnya. Owner wajib memakai 2FA.</p>
      {!twoFactorEnabled ? (
        <Alert tone="warning">Aktifkan verifikasi dua langkah (2FA) di <a href="/akun#keamanan" className="font-bold underline">halaman Akun</a> sebelum mengalihkan kepemilikan.</Alert>
      ) : (
        <>
          <Field id="ot-member" label="Owner baru" error={apiErr?.field('member_id')}>
            <select id="ot-member" name="member_id" className="input" defaultValue="">
              <option value="" disabled>Pilih anggota tim</option>
              {eligible.map((m) => <option key={m.id} value={m.id}>{m.user.name} · {ROLE_LABEL[m.role]}</option>)}
            </select>
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field id="ot-password" label="Kata sandi Anda" error={apiErr?.field('password')}>
              <input id="ot-password" name="password" type="password" autoComplete="current-password" className="input" />
            </Field>
            <Field id="ot-code" label="Kode 2FA" hint="6 angka dari aplikasi autentikator" error={apiErr?.field('two_factor_code')}>
              <input id="ot-code" name="code" inputMode="numeric" autoComplete="one-time-code" className="input num" />
            </Field>
          </div>
        </>
      )}
      {apiErr && !apiErr.errors && <Alert tone="danger">{apiErr.message}</Alert>}
      {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
      <div className="flex gap-2">
        {twoFactorEnabled && <button className="btn border border-danger bg-white text-danger hover:bg-danger-bg" disabled={busy}>{busy && <Spinner />}Alihkan sekarang</button>}
        <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={() => { setOpen(false); setError(null); }}>Batal</button>
      </div>
    </form>
  );
}
