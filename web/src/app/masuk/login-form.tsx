'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Spinner } from '@/components/form';
import { IconEye, IconEyeOff } from '@/components/icons';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

type LoginResp = { data: { user?: unknown; two_factor_required?: boolean; challenge_token?: string } };
type Role = 'jamaah' | 'travel';

const ROLES: { key: Role; label: string }[] = [
  { key: 'jamaah', label: 'Jamaah & Agen' },
  { key: 'travel', label: 'Travel (PPIU)' },
];

const input =
  'h-[52px] w-full rounded-[14px] border border-[#8E8567] bg-white px-4 text-[15px] text-[#16302D] placeholder:text-[#8E8567] focus-visible:border-[#0A4F49] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#B8893A]';
const label = 'text-[13px] font-semibold';

/**
 * JMH-01 Masuk: email/HP + kata sandi, lalu kode 2FA bila aktif (FR-AUTH-02/03).
 * Satu akun bisa punya beberapa peran, jadi pilihan Jamaah/Travel hanya mengubah petunjuk isian.
 */
export function LoginForm({ next, notice }: { next: string; notice?: string }) {
  const router = useRouter();
  const [role, setRole] = useState<Role>('jamaah');
  const [showPass, setShowPass] = useState(false);
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
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      {notice && !error && <Alert tone="success">{notice}</Alert>}
      {msg && <Alert tone="danger">{msg}</Alert>}
      {!challenge ? (
        <>
          <div role="group" aria-label="Masuk sebagai" className="mt-2 flex gap-1 rounded-[14px] bg-[#EFE8D6] p-1">
            {ROLES.map((r) => {
              const active = role === r.key;
              return (
                <button
                  key={r.key}
                  type="button"
                  aria-pressed={active}
                  onClick={() => setRole(r.key)}
                  className={`h-[42px] flex-1 rounded-[10px] text-sm font-semibold transition ${active ? 'bg-white text-[#0A4F49] shadow-[0_1px_3px_rgba(22,48,45,0.14)]' : 'text-[#3E4F4C] hover:text-[#0A4F49]'}`}
                >
                  {r.label}
                </button>
              );
            })}
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="identifier" className={label}>{role === 'travel' ? 'Email kerja' : 'Email atau nomor HP'}</label>
            <input
              id="identifier"
              name="identifier"
              className={input}
              autoComplete="username"
              required
              inputMode="email"
              autoFocus
              placeholder={role === 'travel' ? 'nama@travelanda.com' : 'nama@email.com atau 08…'}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="password" className={label}>Kata sandi</label>
            <div className="relative">
              <input
                id="password"
                name="password"
                type={showPass ? 'text' : 'password'}
                className={`${input} pr-[52px]`}
                autoComplete="current-password"
                required
                placeholder="Masukkan kata sandi"
              />
              <button
                type="button"
                onClick={() => setShowPass((v) => !v)}
                aria-label={showPass ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
                aria-pressed={showPass}
                className="absolute right-1 top-1 flex h-11 w-11 items-center justify-center rounded-[10px] text-[#4F5F5C] hover:bg-[#EFE8D6]"
              >
                {showPass ? <IconEyeOff className="h-[22px] w-[22px]" /> : <IconEye className="h-[22px] w-[22px]" />}
              </button>
            </div>
          </div>

          <div className="-mt-1.5 flex justify-end">
            <Link href="/lupa-sandi" className="text-[13px] font-semibold text-[#8A6420] hover:underline">Lupa kata sandi?</Link>
          </div>
        </>
      ) : (
        <div className="flex flex-col gap-1.5">
          <label htmlFor="code" className={label}>Kode verifikasi dua langkah</label>
          <input id="code" name="code" className={`${input} num tracking-widest`} autoComplete="one-time-code" inputMode="text" required autoFocus aria-describedby="code-hint" />
          <p id="code-hint" className="text-[13px] text-[#3E4F4C]">6 angka dari aplikasi autentikator, atau salah satu kode cadangan Anda.</p>
        </div>
      )}

      <button
        type="submit"
        disabled={busy}
        className="flex h-[54px] items-center justify-center gap-2.5 rounded-2xl bg-[#0A4F49] text-base font-bold text-white transition hover:bg-[#083E39] disabled:opacity-70"
      >
        {busy && <Spinner />}
        {challenge ? 'Verifikasi dan masuk' : 'Masuk'}
        {!busy && (
          <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#E3C37E" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M5 12h14M13 6l6 6-6 6" />
          </svg>
        )}
      </button>
      {challenge && (
        <button type="button" onClick={() => { setChallenge(null); setError(null); }} className="h-12 rounded-[14px] border border-[#8E8567] text-sm font-semibold text-[#16302D] hover:bg-[#EFE8D6]">
          Kembali
        </button>
      )}
    </form>
  );
}
