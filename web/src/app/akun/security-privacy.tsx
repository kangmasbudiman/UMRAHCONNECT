'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { IconDownload, IconLogout, IconTrash } from '@/components/icons';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

export type DeviceSession = { key: string; device: string; ip_address: string | null; last_active_at: string; current: boolean };
const wib = (iso: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso)) + ' WIB';

/** Perangkat yang sedang masuk; keluarkan satu atau semua perangkat lain. */
export function DevicesPanel({ sessions }: { sessions: DeviceSession[] }) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const others = sessions.filter((s) => !s.current).length;
  const run = async (key: string, fn: () => Promise<unknown>, done?: string) => {
    setBusy(key);
    try { await fn(); if (done) setMsg(done); router.refresh(); } catch (e) { setMsg(errorMessage(e)); }
    setBusy(null);
  };
  return (
    <div className="space-y-3">
      <ul className="divide-y divide-border-subtle">
        {sessions.map((s) => (
          <li key={s.key} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <div>
              <p className="font-semibold">{s.device}{s.current && <span className="ml-2 rounded-full bg-success-bg px-2 py-0.5 text-xs font-bold text-success">Perangkat ini</span>}</p>
              <p className="text-sm text-ink-600">{s.ip_address ?? 'IP tidak diketahui'} · aktif {wib(s.last_active_at)}</p>
            </div>
            {!s.current && (
              <button type="button" className="inline-flex min-h-10 items-center gap-1 px-2 text-sm font-semibold text-danger hover:underline" disabled={!!busy}
                onClick={() => run(s.key, () => api(`/me/sessions/${s.key}`, { method: 'DELETE' }), 'Perangkat dikeluarkan.')}>
                {busy === s.key ? <Spinner /> : <IconLogout className="h-4 w-4" />}Keluarkan
              </button>
            )}
          </li>
        ))}
      </ul>
      {others > 0 && (
        <button type="button" className="btn btn-outline" disabled={!!busy}
          onClick={() => { if (confirm(`Keluarkan ${others} perangkat lain? Perangkat ini tetap masuk.`)) run('all', () => api('/me/sessions/revoke-others', { json: {} }), 'Semua perangkat lain sudah dikeluarkan.'); }}>
          {busy === 'all' ? <Spinner /> : <IconLogout className="h-5 w-5" />}Keluarkan semua perangkat lain
        </button>
      )}
      {msg && <p role="status" className="text-sm font-semibold text-primary-700">{msg}</p>}
      <p className="text-sm text-ink-600">Kami mengirim email/notifikasi setiap kali akun masuk dari perangkat baru.</p>
    </div>
  );
}

function ReauthFields({ id, twoFactor, err }: { id: string; twoFactor: boolean; err: ApiClientError | null }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field id={`${id}-pw`} label="Kata sandi" error={err?.field('password')}><input id={`${id}-pw`} name="password" type="password" autoComplete="current-password" className="input" /></Field>
      {twoFactor && <Field id={`${id}-2fa`} label="Kode 2FA" error={err?.field('two_factor_code')}><input id={`${id}-2fa`} name="two_factor_code" inputMode="numeric" autoComplete="one-time-code" className="input num" /></Field>}
    </div>
  );
}

/** Hak subjek data (UU PDP): unduh salinan data dan hapus akun. */
export function PrivacyPanel({ blockers, twoFactor }: { blockers: string[]; twoFactor: boolean }) {
  const router = useRouter();
  const [mode, setMode] = useState<'export' | 'delete' | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const apiErr = error instanceof ApiClientError ? error : null;
  const fields = (fd: FormData) => ({ password: String(fd.get('password') ?? ''), two_factor_code: String(fd.get('two_factor_code') ?? '').trim() || null });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <button type="button" className={`btn ${mode === 'export' ? 'btn-primary' : 'btn-outline'}`} onClick={() => { setMode('export'); setError(null); setDone(null); }}><IconDownload className="h-5 w-5" />Unduh data saya</button>
        <button type="button" className="btn border border-danger bg-white text-danger hover:bg-danger-bg" onClick={() => { setMode('delete'); setError(null); setDone(null); }}><IconTrash className="h-5 w-5" />Hapus akun</button>
      </div>

      {mode === 'export' && (
        <form className="space-y-3 rounded-[12px] bg-surface p-4" noValidate onSubmit={async (e) => {
          e.preventDefault();
          setBusy(true); setError(null);
          try {
            const data = await api<unknown>('/me/data-export', { json: fields(new FormData(e.currentTarget)) });
            const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
            const a = document.createElement('a');
            a.href = url;
            a.download = `data-saya-umrah-connect-${new Date().toISOString().slice(0, 10)}.json`;
            a.click();
            URL.revokeObjectURL(url);
            setDone('File data Anda sudah diunduh. Simpan di tempat aman karena memuat NIK dan nomor paspor.');
            setMode(null);
          } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
          setBusy(false);
        }}>
          <p className="text-sm">Salinan berisi akun, persetujuan, data jamaah (termasuk NIK & paspor), booking, ulasan, tabungan, dan riwayat notifikasi dalam format JSON. Konfirmasi identitas Anda:</p>
          <ReauthFields id="ex" twoFactor={twoFactor} err={apiErr} />
          {apiErr && !apiErr.errors && <Alert tone="danger">{apiErr.message}</Alert>}
          {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
          <button className="btn btn-primary" disabled={busy}>{busy ? <Spinner /> : <IconDownload className="h-5 w-5" />}Unduh</button>
        </form>
      )}

      {mode === 'delete' && (blockers.length > 0 ? (
        <Alert tone="warning" title="Akun belum bisa dihapus">
          <ul className="ml-5 list-disc">{blockers.map((b) => <li key={b}>{b}</li>)}</ul>
        </Alert>
      ) : (
        <form className="space-y-3 rounded-[12px] border border-danger/40 bg-danger-bg/40 p-4" noValidate onSubmit={async (e) => {
          e.preventDefault();
          const fd = new FormData(e.currentTarget);
          setBusy(true); setError(null);
          try {
            await api('/me/account/delete', { json: { ...fields(fd), confirm: String(fd.get('confirm') ?? '').trim() } });
            router.replace('/?akun=dihapus');
            router.refresh();
          } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); setBusy(false); }
        }}>
          <p className="font-bold">Hapus akun secara permanen</p>
          <ul className="ml-5 list-disc text-sm">
            <li>Nama, email, nomor HP, 2FA, perangkat, tabungan, notifikasi, dan jamaah tersimpan yang belum pernah dipesan akan dihapus.</li>
            <li>Booking yang sudah selesai, invoice, kwitansi, dan manifes jamaah tetap disimpan PPIU karena kewajiban hukum, tanpa terhubung ke akun Anda.</li>
            <li>Tindakan ini tidak bisa dibatalkan. Unduh data Anda dulu bila perlu.</li>
          </ul>
          <ReauthFields id="del" twoFactor={twoFactor} err={apiErr} />
          <Field id="del-confirm" label='Ketik "HAPUS" untuk konfirmasi' error={apiErr?.field('confirm')}><input id="del-confirm" name="confirm" className="input" autoComplete="off" /></Field>
          {apiErr && !apiErr.errors && <Alert tone="danger">{apiErr.message}</Alert>}
          {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
          <button className="btn border border-danger bg-danger text-white hover:opacity-90" disabled={busy}>{busy ? <Spinner /> : <IconTrash className="h-5 w-5" />}Hapus akun saya</button>
        </form>
      ))}
      {done && <Alert tone="success">{done}</Alert>}
    </div>
  );
}
