'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

/** Atur ulang kata sandi: kontak → OTP → kata sandi baru (FR-AUTH-02). */
export function ResetForm() {
  const router = useRouter();
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [identifier, setIdentifier] = useState('');
  const [token, setToken] = useState('');
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState(false);

  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof ApiClientError ? e : errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    if (step === 1) {
      const id = String(fd.get('identifier') ?? '').trim();
      await run(async () => {
        await api('/auth/otp/send', { json: { identifier: id, channel: id.includes('@') ? 'email' : 'whatsapp', purpose: 'reset' } });
        setIdentifier(id);
        setStep(2);
      });
    } else if (step === 2) {
      await run(async () => {
        const r = await api<{ data: { reset_token: string } }>('/auth/otp/verify', { json: { identifier, purpose: 'reset', code: String(fd.get('code') ?? '').replace(/\D/g, '') } });
        setToken(r.data.reset_token);
        setStep(3);
      });
    } else {
      await run(async () => {
        await api('/auth/password/reset', { json: { reset_token: token, password: fd.get('password') } });
        router.replace('/masuk?reset=1');
      });
    }
  }

  const msg = typeof error === 'string' ? error : error?.errors ? null : error?.message;
  return (
    <form onSubmit={submit} className="space-y-4" noValidate key={step}>
      <ol className="flex gap-2 text-sm font-semibold" aria-label="Langkah">
        {['Kontak', 'Kode', 'Sandi baru'].map((l, i) => (
          <li key={l} aria-current={step === i + 1 ? 'step' : undefined}
            className={`flex-1 rounded-full px-3 py-1 text-center ${step >= i + 1 ? 'bg-primary-700 text-white' : 'bg-primary-50 text-primary-700'}`}>{i + 1}. {l}</li>
        ))}
      </ol>
      {msg && <Alert tone="danger">{msg}</Alert>}
      {step === 1 && (
        <Field id="identifier" label="Email atau nomor HP akun Anda" error={error instanceof ApiClientError ? error.field('identifier') : undefined}>
          <input id="identifier" name="identifier" className="input" autoComplete="username" required autoFocus />
        </Field>
      )}
      {step === 2 && (
        <>
          <Alert tone="info">Bila {identifier} terdaftar, kode 6 angka sudah kami kirim. Berlaku 10 menit.</Alert>
          <Field id="code" label="Kode verifikasi">
            <input id="code" name="code" className="input num text-center text-2xl font-bold tracking-[0.5em]" inputMode="numeric" autoComplete="one-time-code" maxLength={6} required autoFocus />
          </Field>
        </>
      )}
      {step === 3 && (
        <Field id="password" label="Kata sandi baru" hint="Minimal 8 karakter, berisi huruf dan angka." error={error instanceof ApiClientError ? error.field('password') : undefined}>
          <input id="password" name="password" type="password" className="input" autoComplete="new-password" required minLength={8} autoFocus />
        </Field>
      )}
      <button type="submit" disabled={busy} className="btn btn-primary w-full disabled:opacity-70">
        {busy && <Spinner />}{step === 1 ? 'Kirim kode' : step === 2 ? 'Lanjut' : 'Simpan kata sandi'}
      </button>
    </form>
  );
}
