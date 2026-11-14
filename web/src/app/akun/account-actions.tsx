'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

export function LogoutButton({ className = 'btn btn-outline' }: { className?: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  return (
    <button type="button" disabled={busy} className={className} onClick={async () => {
      setBusy(true);
      try { await api('/auth/logout', { method: 'POST' }); } catch { /* sesi mungkin sudah berakhir */ }
      router.replace('/');
      router.refresh();
    }}>
      {busy && <Spinner />}Keluar
    </button>
  );
}

export function ProfileForm({ name }: { name: string }) {
  const router = useRouter();
  const [state, setState] = useState<{ ok?: boolean; err?: string; busy?: boolean }>({});
  return (
    <form className="space-y-3" onSubmit={async (e) => {
      e.preventDefault();
      setState({ busy: true });
      try {
        await api('/me', { method: 'PATCH', json: { name: new FormData(e.currentTarget).get('name') } });
        setState({ ok: true });
        router.refresh();
      } catch (err) {
        setState({ err: err instanceof ApiClientError ? (err.field('name') ?? err.message) : errorMessage(err) });
      }
    }}>
      <Field id="profile-name" label="Nama lengkap" error={state.err}>
        <input id="profile-name" name="name" defaultValue={name} className="input" required maxLength={150} autoComplete="name" />
      </Field>
      {state.ok && <p role="status" className="text-sm font-semibold text-success">Nama tersimpan.</p>}
      <button className="btn btn-outline" disabled={state.busy}>{state.busy && <Spinner />}Simpan</button>
    </form>
  );
}

/** Aktifkan 2FA: QR → kode 6 angka → simpan 8 kode cadangan (FR-AUTH-03). */
export function TwoFactorSetup({ enabled }: { enabled: boolean }) {
  const router = useRouter();
  const [setup, setSetup] = useState<{ qr_svg: string; secret: string } | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (codes) {
    return (
      <div className="space-y-3">
        <Alert tone="success" title="2FA aktif">
          Simpan 8 kode cadangan ini di tempat aman. Setiap kode hanya bisa dipakai sekali bila ponsel Anda hilang. Kode tidak akan ditampilkan lagi.
        </Alert>
        <ul className="num grid grid-cols-2 gap-2 rounded-[10px] bg-surface p-4 font-mono text-lg font-bold">
          {codes.map((c) => <li key={c}>{c}</li>)}
        </ul>
        <button type="button" className="btn btn-primary" onClick={() => { setCodes(null); router.refresh(); }}>Saya sudah menyimpannya</button>
      </div>
    );
  }
  if (enabled) {
    return <Alert tone="success" title="Verifikasi dua langkah aktif">Setiap kali masuk, Anda diminta kode dari aplikasi autentikator.</Alert>;
  }
  if (!setup) {
    return (
      <div className="space-y-3">
        {error && <Alert tone="danger">{error}</Alert>}
        <button type="button" className="btn btn-primary" disabled={busy} onClick={async () => {
          setBusy(true);
          setError(null);
          try {
            const r = await api<{ data: { qr_svg: string; secret: string } }>('/auth/two-factor/setup', { method: 'POST' });
            setSetup(r.data);
          } catch (e) { setError(errorMessage(e)); }
          setBusy(false);
        }}>{busy && <Spinner />}Aktifkan 2FA</button>
      </div>
    );
  }
  return (
    <form className="space-y-4" onSubmit={async (e) => {
      e.preventDefault();
      setBusy(true);
      setError(null);
      try {
        const r = await api<{ data: { recovery_codes: string[] } }>('/auth/two-factor/confirm', { json: { code: new FormData(e.currentTarget).get('code') } });
        setCodes(r.data.recovery_codes);
      } catch (err) { setError(errorMessage(err)); }
      setBusy(false);
    }}>
      <ol className="list-decimal space-y-1 pl-5 text-ink-600">
        <li>Buka aplikasi autentikator (Google Authenticator, Microsoft Authenticator, atau sejenisnya).</li>
        <li>Pindai kode QR ini, atau masukkan kunci secara manual.</li>
        <li>Ketik 6 angka yang muncul di aplikasi.</li>
      </ol>
      <div className="flex flex-wrap items-center gap-4">
        <div className="rounded-[10px] border border-border-subtle bg-white p-2" aria-label="Kode QR untuk aplikasi autentikator" role="img"
          dangerouslySetInnerHTML={{ __html: setup.qr_svg }} />
        <div className="min-w-0">
          <p className="text-sm text-ink-600">Kunci manual</p>
          <p className="num break-all font-mono font-bold">{setup.secret.match(/.{1,4}/g)?.join(' ')}</p>
        </div>
      </div>
      {error && <Alert tone="danger">{error}</Alert>}
      <Field id="totp" label="Kode 6 angka dari aplikasi">
        <input id="totp" name="code" className="input num max-w-48 tracking-widest" inputMode="numeric" maxLength={6} autoComplete="one-time-code" required />
      </Field>
      <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Konfirmasi dan aktifkan</button>
    </form>
  );
}

export function InvitationActions({ memberId }: { memberId: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const act = async (decision: 'accept' | 'decline') => {
    setBusy(decision);
    setError(null);
    try {
      await api(`/me/invitations/${memberId}/${decision}`, { method: 'POST' });
      router.refresh();
    } catch (e) { setError(errorMessage(e)); setBusy(null); }
  };
  return (
    <div className="flex flex-wrap items-center gap-2">
      <button type="button" className="btn btn-primary" disabled={!!busy} onClick={() => act('accept')}>{busy === 'accept' && <Spinner />}Terima</button>
      <button type="button" className="btn btn-outline" disabled={!!busy} onClick={() => act('decline')}>{busy === 'decline' && <Spinner />}Tolak</button>
      {error && <p role="alert" className="w-full text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}
