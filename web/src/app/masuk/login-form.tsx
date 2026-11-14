'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

type LoginResp = { data: { user?: unknown; two_factor_required?: boolean; challenge_token?: string } };

/** JMH-01 Masuk: email/HP + kata sandi, lalu kode 2FA bila aktif (FR-AUTH-02/03). */
export function LoginForm({ next, notice }: { next: string; notice?: string }) {
  const router = useRouter();
  const [challenge, setChallenge] = useState<string | null>(null);
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    setBusy(true);
    setError(null);
    try {
      if (!challenge) {
        const r = await api<LoginResp>('/auth/login', { json: { identifier: fd.get('identifier'), password: fd.get('password') } });
        if (r.data.two_factor_required && r.data.challenge_token) {
          setChallenge(r.data.challenge_token);
          setBusy(false);
          return;
        }
      } else {
        await api('/auth/two-factor/challenge', { json: { challenge_token: challenge, code: fd.get('code') } });
      }
      router.replace(next);
      router.refresh();
    } catch (err) {
      if (err instanceof ApiClientError && err.code === 'challenge_expired') setChallenge(null);
      setError(err instanceof ApiClientError ? err : errorMessage(err));
      setBusy(false);
    }
  }

  const msg = typeof error === 'string' ? error : error?.message;
  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      {notice && !error && <Alert tone="success">{notice}</Alert>}
      {msg && <Alert tone="danger">{msg}</Alert>}
      {!challenge ? (
        <>
          <Field id="identifier" label="Email atau nomor HP">
            <input id="identifier" name="identifier" className="input" autoComplete="username" required inputMode="email" autoFocus />
          </Field>
          <Field id="password" label="Kata sandi">
            <input id="password" name="password" type="password" className="input" autoComplete="current-password" required />
          </Field>
          <div className="text-right">
            <Link href="/lupa-sandi" className="font-semibold text-primary-700 hover:underline">Lupa kata sandi?</Link>
          </div>
        </>
      ) : (
        <Field id="code" label="Kode verifikasi dua langkah" hint="6 angka dari aplikasi autentikator, atau salah satu kode cadangan Anda.">
          <input id="code" name="code" className="input num tracking-widest" autoComplete="one-time-code" inputMode="text" required autoFocus aria-describedby="code-hint" />
        </Field>
      )}
      <button type="submit" disabled={busy} className="btn btn-primary w-full disabled:opacity-70">
        {busy && <Spinner />}{challenge ? 'Verifikasi dan masuk' : 'Masuk'}
      </button>
      {challenge && (
        <button type="button" onClick={() => { setChallenge(null); setError(null); }} className="btn w-full text-primary-700 hover:bg-primary-50">
          Kembali
        </button>
      )}
    </form>
  );
}
