'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import Script from 'next/script';
import { useEffect, useRef, useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { LEGAL } from '@/lib/nav';

type Role = 'jamaah' | 'agen' | 'ppiu';
const TURNSTILE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY;

const ROLES: { id: Role; title: string; desc: string }[] = [
  { id: 'jamaah', title: 'Jamaah', desc: 'Pesan paket dan pantau pembayaran' },
  { id: 'agen', title: 'Agen', desc: 'Pasarkan paket PPIU mitra' },
  { id: 'ppiu', title: 'PPIU', desc: 'Daftarkan travel berizin Anda' },
];

/** JMH-02 Daftar (FR-AUTH-01, FR-AUTH-08): satu akun untuk semua peran; peran menentukan langkah berikutnya. */
export function RegisterForm({ initialRole }: { initialRole: Role }) {
  const router = useRouter();
  const [role, setRole] = useState<Role>(initialRole);
  const [via, setVia] = useState<'email' | 'phone'>('email');
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState(false);
  // Penangkal bot: waktu formulir mulai diisi (dikirim ke API; < 2,5 detik dianggap otomatis).
  const startedAt = useRef(0);
  useEffect(() => { startedAt.current = Date.now(); }, []);

  const after = role === 'ppiu' ? '/dasbor/ppiu/daftar' : role === 'agen' ? '/agen/daftar' : '/akun';

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    if (!fd.get('agree')) {
      setError('Centang persetujuan Syarat & Ketentuan dan Kebijakan Privasi untuk melanjutkan.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await api('/auth/register', {
        json: {
          name: fd.get('name'),
          ...(via === 'email' ? { email: fd.get('contact') } : { phone: fd.get('contact') }),
          password: fd.get('password'),
          website: fd.get('website'),
          form_started_at: startedAt.current || undefined,
          turnstile_token: fd.get('cf-turnstile-response') || undefined,
          ...LEGAL,
        },
      });
      router.replace(`/verifikasi?baru=1&next=${encodeURIComponent(after)}`);
      router.refresh();
    } catch (err) {
      setError(err instanceof ApiClientError ? err : errorMessage(err));
      setBusy(false);
    }
  }

  const apiErr = error instanceof ApiClientError ? error : null;
  const contactErr = apiErr?.field(via === 'email' ? 'email' : 'phone');
  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <fieldset>
        <legend className="mb-2 font-semibold">Saya mendaftar sebagai</legend>
        <div className="grid grid-cols-3 gap-2">
          {ROLES.map((r) => (
            <label key={r.id} className={`flex cursor-pointer flex-col rounded-[10px] border p-3 text-sm transition ${role === r.id ? 'border-primary-700 bg-primary-50 ring-1 ring-primary-700' : 'border-border-subtle hover:border-primary-600'}`}>
              <input type="radio" name="role" value={r.id} checked={role === r.id} onChange={() => setRole(r.id)} className="sr-only" />
              <span className="font-bold text-ink-900">{r.title}</span>
              <span className="mt-0.5 leading-5 text-ink-600">{r.desc}</span>
            </label>
          ))}
        </div>
        {role === 'agen' && <p className="mt-2 text-sm text-ink-600">Setelah akun dibuat, Anda melengkapi data agen (NIK, KTP, rekening komisi) lalu mengajukan kemitraan ke PPIU.</p>}
        {role === 'ppiu' && <p className="mt-2 text-sm text-ink-600">Setelah akun dibuat, Anda mengisi data PPIU, mengunggah dokumen legalitas, dan mengajukan verifikasi.</p>}
      </fieldset>

      {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
      {apiErr && !apiErr.errors && <Alert tone="danger">{apiErr.message}</Alert>}

      <Field id="name" label={role === 'ppiu' ? 'Nama penanggung jawab' : 'Nama lengkap'} error={apiErr?.field('name')}>
        <input id="name" name="name" className="input" autoComplete="name" required maxLength={150} />
      </Field>

      <div>
        <div className="mb-1.5 flex items-center justify-between gap-2">
          <label htmlFor="contact" className="font-semibold">{via === 'email' ? 'Email' : 'Nomor HP (WhatsApp)'}</label>
          <button type="button" onClick={() => setVia(via === 'email' ? 'phone' : 'email')} className="min-h-10 text-sm font-semibold text-primary-700 hover:underline">
            {via === 'email' ? 'Pakai nomor HP' : 'Pakai email'}
          </button>
        </div>
        <input id="contact" name="contact" key={via} className="input" required
          type={via === 'email' ? 'email' : 'tel'} inputMode={via === 'email' ? 'email' : 'tel'}
          autoComplete={via === 'email' ? 'email' : 'tel'} placeholder={via === 'email' ? 'nama@contoh.com' : '0812 3456 7890'}
          aria-describedby="contact-hint" />
        {contactErr
          ? <p role="alert" className="mt-1 text-sm font-semibold text-danger">{contactErr}</p>
          : <p id="contact-hint" className="mt-1 text-sm text-ink-600">Kami kirim kode verifikasi 6 angka ke {via === 'email' ? 'email' : 'WhatsApp'} ini.</p>}
      </div>

      <Field id="password" label="Kata sandi" hint="Minimal 8 karakter, berisi huruf dan angka." error={apiErr?.field('password')}>
        <input id="password" name="password" type="password" className="input" autoComplete="new-password" required minLength={8} aria-describedby="password-hint" />
      </Field>

      {/* Honeypot: tak terlihat dan dilewati pembaca layar; hanya bot yang mengisinya. */}
      <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
        <label htmlFor="website">Website</label>
        <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
      </div>
      {TURNSTILE_KEY && (
        <>
          <Script src="https://challenges.cloudflare.com/turnstile/v0/api.js" strategy="afterInteractive" />
          <div className="cf-turnstile" data-sitekey={TURNSTILE_KEY} data-language="id" />
        </>
      )}

      <label className="flex gap-3 text-sm leading-6">
        <input type="checkbox" name="agree" value="1" className="mt-1 h-5 w-5 shrink-0 accent-primary-700" />
        <span>
          Saya menyetujui <Link href="/syarat" target="_blank" className="font-semibold text-primary-700 underline">Syarat & Ketentuan</Link> dan{' '}
          <Link href="/privasi" target="_blank" className="font-semibold text-primary-700 underline">Kebijakan Privasi</Link> UMRAH CONNECT.
        </span>
      </label>

      <button type="submit" disabled={busy} className="btn btn-gold w-full disabled:opacity-70">
        {busy && <Spinner />}Buat akun
      </button>
    </form>
  );
}
