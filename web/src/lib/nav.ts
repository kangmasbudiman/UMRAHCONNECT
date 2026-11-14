/** Hanya path internal yang boleh jadi tujuan setelah masuk (cegah open redirect). */
export function safeNext(v: string | string[] | undefined, fallback = '/akun'): string {
  const s = Array.isArray(v) ? v[0] : v;
  return s && s.startsWith('/') && !s.startsWith('//') && !s.startsWith('/\\') ? s : fallback;
}

/** Versi dokumen hukum yang disetujui saat daftar; harus sama dengan config('app.legal') di API. */
export const LEGAL = { terms_version: '2026-11-v1', privacy_version: '2026-11-v1' } as const;
