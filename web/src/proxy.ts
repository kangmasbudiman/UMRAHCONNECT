import { NextResponse, type NextRequest } from 'next/server';

const API = process.env.API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1';

/**
 * Link agen ?ref=KODE (BP-04, CR-01): simpan kode di cookie first-party uc_ref selama 30 hari
 * (ditimpa link terbaru = last-click), catat satu klik ke API, lalu bersihkan URL.
 * Server tetap memvalidasi kemitraan saat booking, jadi cookie yang diubah tidak bisa menghasilkan komisi.
 */
export const SESSION_COOKIE = 'umrah-connect-session';
const PROTECTED = ['/akun', '/dasbor', '/admin'];

export async function proxy(req: NextRequest) {
  // Area akun: tanpa cookie sesi langsung ke halaman masuk (cek sesi sebenarnya tetap di server API).
  const path = req.nextUrl.pathname;
  if (PROTECTED.some((p) => path === p || path.startsWith(p + '/')) && !req.cookies.has(SESSION_COOKIE)) {
    const login = req.nextUrl.clone();
    login.pathname = '/masuk';
    login.search = `?next=${encodeURIComponent(path + req.nextUrl.search)}`;
    return NextResponse.redirect(login);
  }

  const ref = req.nextUrl.searchParams.get('ref');
  if (!ref || !/^[A-Za-z0-9]{4,16}$/.test(ref)) return NextResponse.next();

  const clean = req.nextUrl.clone();
  clean.searchParams.delete('ref');
  const res = NextResponse.redirect(clean);
  res.cookies.set('uc_ref', JSON.stringify({ code: ref.toUpperCase(), at: Date.now() }), {
    maxAge: 60 * 60 * 24 * 30, httpOnly: true, sameSite: 'lax', path: '/',
  });

  try {
    await fetch(`${API}/public/referrals/${encodeURIComponent(ref.toUpperCase())}/clicks`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-Forwarded-For': req.headers.get('x-forwarded-for') ?? '' },
      signal: AbortSignal.timeout(1500),
    });
  } catch {
    // Pengunjung tidak boleh tertahan bila pencatatan klik gagal.
  }
  return res;
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api/|sanctum/).*)'],
};
