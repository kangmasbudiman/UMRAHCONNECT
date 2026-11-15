'use client';

import Image from 'next/image';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { PARTNERSHIP_TERMS_VERSION } from '@/lib/nav';
import type { AgentProfile, ReferralLink } from '@/lib/agent-types';
import { RegionSelect } from '@/components/region-select';

type Err = ApiClientError | string | null;
const msgOf = (e: Err) => (typeof e === 'string' ? e : e?.message);
const BANKS = ['BSI', 'Bank Mandiri', 'BRI', 'BNI', 'BCA', 'Bank Muamalat', 'CIMB Niaga Syariah', 'Bank Mega Syariah', 'BPD setempat'];

/** AGT-01 Daftar agen: NIK + foto KTP + rekening komisi (FR-AGT-01/02). */
export function AgentProfileForm({ profile }: { profile?: AgentProfile }) {
  const router = useRouter();
  const [error, setError] = useState<Err>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const apiErr = error instanceof ApiClientError ? error : null;
  const creating = !profile;

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    setBusy(true);
    setError(null);
    setSaved(false);
    try {
      const body: Record<string, unknown> = {
        payout_bank_name: fd.get('payout_bank_name'), payout_account_holder: fd.get('payout_account_holder'),
      };
      const acct = String(fd.get('payout_account_no') ?? '').trim();
      if (acct || creating) body.payout_account_no = acct;
      const ktp = fd.get('ktp') as File | null;
      if (ktp && ktp.size > 0) {
        if (ktp.size > 5 * 1024 * 1024) throw new Error('Ukuran foto KTP maksimal 5 MB.');
        const up = new FormData();
        up.set('purpose', 'ktp');
        up.set('file', ktp);
        body.ktp_file_id = (await api<{ data: { id: string } }>('/files', { form: up })).data.id;
      } else if (creating) {
        throw new Error('Unggah foto KTP Anda.');
      }
      if (creating) body.nik = fd.get('nik');
      const region = String(fd.get('region_code') ?? '');
      if (region || creating) body.region_code = region || null;
      const street = String(fd.get('address') ?? '').trim();
      if (street || creating) body.address = street || null;
      body.postal_code = String(fd.get('postal_code') ?? '').trim() || null;
      await api('/agent/profile', { method: creating ? 'POST' : 'PATCH', json: body });
      if (creating) { router.replace('/agen/mitra?baru=1'); router.refresh(); return; }
      setSaved(true);
      router.refresh();
    } catch (err) { setError(err instanceof ApiClientError ? err : err instanceof Error ? err.message : errorMessage(err)); }
    setBusy(false);
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-4 md:grid-cols-2">
      {msgOf(error) && (!apiErr?.errors || apiErr.status !== 422) && <div className="md:col-span-2"><Alert tone="danger">{msgOf(error)}</Alert></div>}
      {saved && <div className="md:col-span-2"><Alert tone="success">Profil tersimpan.</Alert></div>}
      {creating ? (
        <Field id="nik" label="NIK (sesuai KTP)" hint="16 angka. Disimpan terenkripsi, hanya ditampilkan tersamar." error={apiErr?.field('nik')}>
          <input id="nik" name="nik" className="input num tracking-wider" inputMode="numeric" maxLength={19} required autoComplete="off" />
        </Field>
      ) : (
        <div><p className="mb-1.5 font-semibold">NIK</p><p className="num flex min-h-12 items-center rounded-[10px] bg-surface px-3 tracking-wider">{profile.nik_masked}</p></div>
      )}
      <Field id="ktp" label={creating ? 'Foto KTP' : 'Ganti foto KTP'} optional={!creating} hint="JPG, PNG, atau PDF, maksimal 5 MB." error={apiErr?.field('ktp_file_id') ?? apiErr?.field('file')}>
        <input id="ktp" name="ktp" type="file" accept="image/jpeg,image/png,application/pdf" className="input py-2.5" required={creating} />
      </Field>
      <div className="md:col-span-2 border-t border-border-subtle pt-4">
        <p className="font-bold">Domisili</p>
        <p className="text-sm text-ink-600">Dipakai calon jamaah untuk menemukan agen terdekat. Alamat lengkap tidak ditampilkan.</p>
      </div>
      <div className="md:col-span-2">
        <RegionSelect idPrefix="agent-region" initial={profile?.region ?? null} error={apiErr?.field('region_code')} after={<>
          <div className="sm:col-span-2">
            <Field id="agent-address" label="Nama jalan, nomor rumah, RT/RW" hint="Contoh: Jl. Kapten Pattimura No. 12, RT 03/RW 01. Tidak ditampilkan ke publik." error={apiErr?.field('address')}>
              <input id="agent-address" name="address" className="input" defaultValue={profile?.address ?? ''} required autoComplete="street-address" />
            </Field>
          </div>
          <Field id="agent-postal" label="Kode pos" optional error={apiErr?.field('postal_code')}>
            <input id="agent-postal" name="postal_code" className="input num" inputMode="numeric" maxLength={5} pattern="[0-9]{5}" defaultValue={profile?.postal_code ?? ''} autoComplete="postal-code" />
          </Field>
        </>} />
      </div>
      <div className="md:col-span-2 border-t border-border-subtle pt-4">
        <p className="font-bold">Rekening pencairan komisi</p>
        <p className="text-sm text-ink-600">Komisi dari PPIU mitra ditransfer ke rekening ini atas nama Anda sendiri.</p>
      </div>
      <Field id="payout_bank_name" label="Bank" error={apiErr?.field('payout_bank_name')}>
        <input id="payout_bank_name" name="payout_bank_name" className="input" list="agent-banks" defaultValue={profile?.payout_bank_name ?? ''} required />
      </Field>
      <datalist id="agent-banks">{BANKS.map((b) => <option key={b} value={b} />)}</datalist>
      <Field id="payout_account_no" label="Nomor rekening" hint={profile ? `Tersimpan: ${profile.payout_account_no_masked}. Kosongkan bila tidak diganti.` : undefined} error={apiErr?.field('payout_account_no')}>
        <input id="payout_account_no" name="payout_account_no" className="input num" inputMode="numeric" required={creating} autoComplete="off" />
      </Field>
      <div className="md:col-span-2">
        <Field id="payout_account_holder" label="Nama pemilik rekening" error={apiErr?.field('payout_account_holder')}>
          <input id="payout_account_holder" name="payout_account_holder" className="input" defaultValue={profile?.payout_account_holder ?? ''} required />
        </Field>
      </div>
      <div className="md:col-span-2"><button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}{creating ? 'Daftar sebagai agen' : 'Simpan profil'}</button></div>
    </form>
  );
}

/** Ajukan kemitraan ke PPIU dengan persetujuan ketentuan kemitraan (FR-AGT-03). */
export function ApplyPartnership({ tenantId, brand }: { tenantId: string; brand: string }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  if (!open) return <button type="button" className="btn btn-gold min-h-10 px-4" onClick={() => setOpen(true)}>Ajukan kemitraan</button>;
  return (
    <form className="w-full space-y-3 rounded-[12px] bg-surface p-3" onSubmit={async (e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      if (!fd.get('agree')) { setError('Centang persetujuan ketentuan kemitraan.'); return; }
      setBusy(true);
      setError(null);
      try {
        await api('/agent/partnerships', { json: { tenant_id: tenantId, message: fd.get('message') || null, accepted_terms_version: PARTNERSHIP_TERMS_VERSION } });
        router.refresh();
      } catch (err) { setError(errorMessage(err)); setBusy(false); }
    }}>
      <Field id={`msg-${tenantId}`} label={`Pesan untuk ${brand}`} optional hint="Perkenalkan diri: wilayah, komunitas, pengalaman.">
        <textarea id={`msg-${tenantId}`} name="message" rows={2} className="input" maxLength={1000} />
      </Field>
      <label className="flex gap-3 text-sm leading-6">
        <input type="checkbox" name="agree" value="1" className="mt-1 h-5 w-5 shrink-0 accent-primary-700" />
        <span>Saya menyetujui ketentuan kemitraan agen: menjual dengan harga resmi PPIU, tidak menerima uang jamaah atas nama pribadi, dan mengarahkan pembayaran ke rekening resmi PPIU.</span>
      </label>
      {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
      <div className="flex gap-2">
        <button className="btn btn-primary min-h-10 px-4" disabled={busy}>{busy && <Spinner />}Kirim pengajuan</button>
        <button type="button" className="btn min-h-10 px-3 text-primary-700 hover:bg-primary-50" onClick={() => setOpen(false)}>Batal</button>
      </div>
    </form>
  );
}

/** Terima/tolak undangan PPIU, atau akhiri kemitraan (FR-AGT-14, ST-PART). */
export function PartnershipActions({ id, status, brand }: { id: string; status: string; brand: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const call = async (key: string, path: string, json?: object) => {
    setBusy(key);
    setError(null);
    try { await api(`/agent/partnerships/${id}/${path}`, { method: 'POST', json }); router.refresh(); } catch (e) { setError(errorMessage(e)); }
    setBusy(null);
  };
  return (
    <div className="flex flex-wrap items-center gap-2">
      {status === 'invited' && (
        <>
          <button type="button" className="btn btn-primary min-h-10 px-4" disabled={!!busy}
            onClick={() => { if (confirm(`Terima undangan ${brand}? Anda menyetujui ketentuan kemitraan: menjual dengan harga resmi dan mengarahkan pembayaran ke rekening resmi PPIU.`)) call('accept', 'accept', { accepted_terms_version: PARTNERSHIP_TERMS_VERSION }); }}>
            {busy === 'accept' && <Spinner />}Terima undangan
          </button>
          <button type="button" className="btn btn-outline min-h-10 px-4" disabled={!!busy} onClick={() => call('decline', 'decline')}>Tolak</button>
        </>
      )}
      {['active', 'suspended', 'applied'].includes(status) && (
        <button type="button" className="min-h-10 px-2 text-sm font-semibold text-danger hover:underline" disabled={!!busy}
          onClick={() => { if (confirm(status === 'applied' ? `Batalkan pengajuan ke ${brand}?` : `Akhiri kemitraan dengan ${brand}? Semua link referral untuk PPIU ini berhenti bekerja. Komisi yang sudah diperoleh tetap dibayar.`)) call('end', 'end'); }}>
          {status === 'applied' ? 'Batalkan pengajuan' : 'Akhiri kemitraan'}
        </button>
      )}
      {error && <p role="alert" className="w-full text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}

/** Buat (atau ambil) link referral lalu tampilkan alat bagikan. */
export function CreateLinkButton({ tenantId, packageId, label = 'Buat link' }: { tenantId: string; packageId?: string | null; label?: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <div>
      <button type="button" className="btn btn-gold min-h-10 px-4" disabled={busy} onClick={async () => {
        setBusy(true);
        setError(null);
        try { await api('/agent/referral-links', { json: { tenant_id: tenantId, package_id: packageId ?? null } }); router.refresh(); }
        catch (e) { setError(errorMessage(e)); }
        setBusy(false);
      }}>{busy && <Spinner />}{label}</button>
      {error && <p role="alert" className="mt-1 text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}

/** Salin, bagikan ke WhatsApp, dan QR untuk satu link (FR-AGT-05). */
export function LinkTools({ link, shareText }: { link: ReferralLink; shareText: string }) {
  const [copied, setCopied] = useState(false);
  const [qr, setQr] = useState(false);
  const wa = `https://wa.me/?text=${encodeURIComponent(`${shareText}\n${link.url}`)}`;
  if (!link.is_active) return <p className="text-sm text-ink-600">Link nonaktif karena kemitraan tidak aktif.</p>;
  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <code className="num min-w-0 flex-1 truncate rounded-[10px] bg-surface px-3 py-2.5 text-sm" title={link.url}>{link.url}</code>
        <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" onClick={async () => {
          await navigator.clipboard.writeText(link.url);
          setCopied(true);
          setTimeout(() => setCopied(false), 2000);
        }}>{copied ? 'Tersalin ✓' : 'Salin'}</button>
        <a href={wa} target="_blank" rel="noopener noreferrer" className="btn min-h-10 bg-[#1FA855] px-3 text-sm text-white hover:bg-[#178a45]">WhatsApp</a>
        <button type="button" className="btn min-h-10 px-3 text-sm text-primary-700 hover:bg-primary-50" aria-expanded={qr} onClick={() => setQr((v) => !v)}>QR</button>
      </div>
      <span role="status" className="sr-only">{copied ? 'Link tersalin' : ''}</span>
      {qr && (
        <div className="flex flex-wrap items-center gap-4 rounded-[12px] bg-surface p-3">
          <Image src={`/api/v1/agent/referral-links/${link.id}/qr`} alt={`QR code link ${link.code}`} width={160} height={160} unoptimized className="rounded-lg bg-white" />
          <div className="space-y-2 text-sm">
            <p className="text-ink-600">Cetak di brosur atau tunjukkan dari ponsel. Pindaian membuka halaman dengan kode Anda.</p>
            <a href={`/api/v1/agent/referral-links/${link.id}/qr`} download={`qr-${link.code}.png`} className="btn btn-outline min-h-10 px-3">Unduh PNG</a>
          </div>
        </div>
      )}
    </div>
  );
}
