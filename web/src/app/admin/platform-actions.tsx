'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

/** Tombol aksi dengan alasan wajib (turunkan paket, nonaktifkan akun, dll). Alasan tercatat di audit log. */
export function ReasonAction({ label, path, confirmText, minLength = 5, tone = 'danger', hint, reasonRequired = true }: {
  label: string; path: string; confirmText: string; minLength?: number; tone?: 'danger' | 'primary'; hint?: string; reasonRequired?: boolean;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const btn = tone === 'danger' ? 'btn border border-danger bg-white text-danger hover:bg-danger-bg' : 'btn btn-primary';
  if (!open) return <button type="button" className={`${btn} min-h-10 px-3 text-sm`} onClick={() => setOpen(true)}>{label}</button>;
  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <form className="w-full space-y-2 rounded-[12px] bg-surface p-3" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const reason = String(new FormData(e.currentTarget).get('reason') ?? '').trim();
      if (reasonRequired && reason.length < minLength) { setError(`Tulis alasan minimal ${minLength} karakter.`); return; }
      if (!confirm(confirmText)) return;
      setBusy(true);
      setError(null);
      try { await api(path, { json: reason ? { reason } : {} }); setOpen(false); router.refresh(); }
      catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
      setBusy(false);
    }}>
      <Field id={`r-${path}`} label={reasonRequired ? 'Alasan' : 'Catatan'} optional={!reasonRequired} hint={hint ?? 'Tercatat di audit log.'} error={apiErr?.field('reason')}>
        <textarea id={`r-${path}`} name="reason" rows={2} className="input" />
      </Field>
      {(typeof error === 'string' || (apiErr && !apiErr.errors)) && <Alert tone="danger">{typeof error === 'string' ? error : apiErr!.message}</Alert>}
      <div className="flex gap-2">
        <button className={`${btn} min-h-10 px-3 text-sm`} disabled={busy}>{busy && <Spinner />}{label}</button>
        <button type="button" className="btn min-h-10 px-3 text-sm text-primary-700 hover:bg-primary-50" onClick={() => { setOpen(false); setError(null); }}>Batal</button>
      </div>
    </form>
  );
}

/** Tombol aksi sederhana dengan konfirmasi. */
export function ConfirmAction({ label, path, method = 'POST', confirmText, json, tone = 'outline' }: {
  label: string; path: string; method?: 'POST' | 'DELETE'; confirmText: string; json?: Record<string, unknown>; tone?: 'outline' | 'danger' | 'primary';
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const cls = tone === 'danger' ? 'text-danger hover:underline font-semibold' : tone === 'primary' ? 'btn btn-primary' : 'btn btn-outline';
  return (
    <button type="button" className={`${cls} min-h-10 px-3 text-sm`} disabled={busy} onClick={async () => {
      if (!confirm(confirmText)) return;
      setBusy(true);
      try { await api(path, { method, json: json ?? (method === 'POST' ? {} : undefined) }); router.refresh(); }
      catch (err) { alert(errorMessage(err)); }
      setBusy(false);
    }}>{busy && <Spinner />}{label}</button>
  );
}

/** Ubah satu pengaturan platform (Super Admin). */
export function SettingForm({ settingKey, value, kind }: { settingKey: string; value: number | string; kind: 'number' | 'earn_on' | 'text' }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [saved, setSaved] = useState(false);
  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <form className="flex flex-wrap items-start gap-2" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const raw = String(new FormData(e.currentTarget).get('value') ?? '');
      setBusy(true);
      setError(null);
      setSaved(false);
      try {
        await api(`/platform/settings/${settingKey}`, { method: 'PUT', json: { value: kind === 'number' ? Number(raw) : raw } });
        setSaved(true);
        router.refresh();
      } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
      setBusy(false);
    }}>
      <label htmlFor={`set-${settingKey}`} className="sr-only">Nilai</label>
      {kind === 'number' ? (
        <input id={`set-${settingKey}`} name="value" type="number" inputMode="numeric" className="input num w-40" defaultValue={value} />
      ) : kind === 'text' ? (
        <input id={`set-${settingKey}`} name="value" className="input w-full sm:w-96" defaultValue={value} maxLength={200} />
      ) : (
        <select id={`set-${settingKey}`} name="value" className="input w-auto" defaultValue={String(value)}>
          <option value="fully_paid">Saat lunas</option>
          <option value="dp_verified">Saat DP terverifikasi</option>
        </select>
      )}
      <button className="btn btn-outline" disabled={busy}>{busy && <Spinner />}Simpan</button>
      {saved && <span role="status" className="self-center text-sm font-semibold text-success">Tersimpan</span>}
      {(apiErr?.field('value') || (apiErr && !apiErr.errors && apiErr.message) || (typeof error === 'string' && error)) && (
        <p role="alert" className="w-full text-sm font-semibold text-danger">{apiErr?.field('value') ?? apiErr?.message ?? (error as string)}</p>
      )}
    </form>
  );
}

/** Super Admin: cari akun lalu jadikan Admin Platform. */
export function AddStaffForm() {
  const router = useRouter();
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<{ id: string; name: string; email: string | null; platform_role: string | null; status: string; email_verified: boolean }[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  return (
    <div className="space-y-3">
      <form className="flex gap-2" role="search" onSubmit={async (e) => {
        e.preventDefault();
        if (q.trim().length < 3) { setError('Ketik minimal 3 huruf nama atau email.'); return; }
        setBusy('search');
        setError(null);
        try { setRows((await api<{ data: NonNullable<typeof rows> }>(`/platform/users?q=${encodeURIComponent(q.trim())}&limit=10`)).data); }
        catch (err) { setError(errorMessage(err)); }
        setBusy(null);
      }}>
        <label htmlFor="staf-q" className="sr-only">Cari akun</label>
        <input id="staf-q" value={q} onChange={(e) => setQ(e.target.value)} className="input max-w-md" placeholder="Nama atau email akun yang sudah terdaftar" />
        <button className="btn btn-outline" disabled={busy === 'search'}>{busy === 'search' && <Spinner />}Cari</button>
      </form>
      {error && <Alert tone="danger">{error}</Alert>}
      {rows && (rows.length === 0 ? <p className="text-ink-600">Akun tidak ditemukan. Minta yang bersangkutan mendaftar dulu dengan email kantornya.</p> : (
        <ul className="divide-y divide-border-subtle rounded-[12px] border border-border-subtle">
          {rows.map((u) => {
            const blocked = u.platform_role ? 'Sudah staf platform' : u.status !== 'active' ? 'Akun tidak aktif' : !u.email || !u.email_verified ? 'Email belum terverifikasi' : null;
            return (
              <li key={u.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                <span><span className="font-semibold">{u.name}</span> <span className="text-sm text-ink-600">{u.email ?? '—'}</span></span>
                {blocked ? <span className="text-sm text-ink-600">{blocked}</span> : (
                  <button type="button" className="btn btn-primary min-h-10 px-3 text-sm" disabled={!!busy} onClick={async () => {
                    if (!confirm(`Jadikan ${u.name} Admin Platform? Ia bisa memverifikasi PPIU, menurunkan paket, dan menonaktifkan akun agen/jamaah.`)) return;
                    setBusy(u.id);
                    try { await api('/platform/admins', { json: { user_id: u.id } }); setRows(null); setQ(''); router.refresh(); }
                    catch (err) { setError(errorMessage(err)); }
                    setBusy(null);
                  }}>{busy === u.id && <Spinner />}Jadikan Admin Platform</button>
                )}
              </li>
            );
          })}
        </ul>
      ))}
    </div>
  );
}
