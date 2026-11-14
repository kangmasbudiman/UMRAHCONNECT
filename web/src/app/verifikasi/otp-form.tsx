'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

/** Verifikasi kontak dengan OTP 6 angka (FR-AUTH-01). Kirim ulang paling cepat 60 detik. */
export function OtpForm({ identifier, channel, next, justSent }: { identifier: string; channel: 'email' | 'whatsapp'; next: string; justSent: boolean }) {
  const router = useRouter();
  const [wait, setWait] = useState(justSent ? 60 : 0);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(justSent ? `Kode sudah dikirim ke ${identifier}.` : null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (wait <= 0) return;
    const t = setTimeout(() => setWait((w) => w - 1), 1000);
    return () => clearTimeout(t);
  }, [wait]);

  async function resend() {
    setError(null);
    try {
      await api('/auth/otp/send', { json: { identifier, channel, purpose: 'verify' } });
      setInfo(`Kode baru dikirim ke ${identifier}.`);
      setWait(60);
    } catch (e) {
      if (e instanceof ApiClientError && typeof e.details?.retry_after === 'number') setWait(e.details.retry_after);
      setError(errorMessage(e));
    }
  }

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const code = String(new FormData(e.currentTarget).get('code') ?? '').replace(/\D/g, '');
    setBusy(true);
    setError(null);
    try {
      await api('/auth/otp/verify', { json: { identifier, purpose: 'verify', code } });
      router.replace(next);
      router.refresh();
    } catch (err) {
      setError(errorMessage(err));
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      {info && !error && <Alert tone="success">{info}</Alert>}
      {error && <Alert tone="danger">{error}</Alert>}
      <Field id="code" label="Kode verifikasi" hint={`6 angka yang kami kirim lewat ${channel === 'email' ? 'email' : 'WhatsApp'}. Berlaku 10 menit.`}>
        <input id="code" name="code" className="input num text-center text-2xl font-bold tracking-[0.5em]" inputMode="numeric"
          autoComplete="one-time-code" maxLength={6} pattern="[0-9]{6}" required autoFocus aria-describedby="code-hint" />
      </Field>
      <button type="submit" disabled={busy} className="btn btn-primary w-full disabled:opacity-70">{busy && <Spinner />}Verifikasi</button>
      <button type="button" onClick={resend} disabled={wait > 0} className="btn w-full text-primary-700 hover:bg-primary-50 disabled:text-ink-600 disabled:hover:bg-transparent">
        {wait > 0 ? `Kirim ulang kode dalam ${wait} detik` : 'Kirim ulang kode'}
      </button>
    </form>
  );
}
