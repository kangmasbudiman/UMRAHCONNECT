'use client';

/**
 * Klien API untuk Client Component. Memanggil /api/v1 di domain web (di-rewrite ke Laravel)
 * dengan sesi cookie Sanctum SPA + token CSRF (header X-XSRF-TOKEN dari cookie XSRF-TOKEN).
 */
export class ApiClientError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public errors: Record<string, string[]> | null = null,
    public details: Record<string, unknown> | null = null,
  ) {
    super(message);
  }
  /** Pesan pertama untuk sebuah field (untuk ditampilkan di bawah input). */
  field(name: string): string | undefined {
    return this.errors?.[name]?.[0];
  }
}

function xsrfToken(): string | null {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : null;
}

async function ensureCsrf(): Promise<void> {
  if (!xsrfToken()) await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
}

type Options = { method?: string; json?: unknown; form?: FormData };

export async function api<T = unknown>(path: string, opts: Options = {}): Promise<T> {
  const method = opts.method ?? (opts.json !== undefined || opts.form ? 'POST' : 'GET');
  if (method !== 'GET') await ensureCsrf();

  const doFetch = () => {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const token = xsrfToken();
    if (token) headers['X-XSRF-TOKEN'] = token;
    if (opts.json !== undefined) headers['Content-Type'] = 'application/json';
    return fetch(`/api/v1${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: opts.form ?? (opts.json !== undefined ? JSON.stringify(opts.json) : undefined),
    });
  };

  let res = await doFetch();
  if (res.status === 419) {
    // Token CSRF kedaluwarsa: ambil ulang sekali lalu ulangi.
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
    res = await doFetch();
  }
  if (res.status === 204) return undefined as T;

  const body = await res.json().catch(() => null);
  if (!res.ok) {
    const e = body?.error;
    throw new ApiClientError(
      res.status,
      e?.code ?? 'network_error',
      e?.message ?? 'Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.',
      e?.errors ?? null,
      e?.details ?? null,
    );
  }
  return body as T;
}

export function errorMessage(e: unknown): string {
  return e instanceof ApiClientError ? e.message : 'Terjadi kendala. Coba lagi sebentar lagi.';
}
