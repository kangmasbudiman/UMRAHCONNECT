import Link from 'next/link';
import { Suspense } from 'react';
import { Fraunces } from 'next/font/google';
import { redirect } from 'next/navigation';
import { safeNext } from '@/lib/nav';
import { getContexts } from '@/lib/session';
import { LoginForm } from './login-form';

export const metadata = { title: 'Masuk' };

const fraunces = Fraunces({ variable: '--font-fraunces', subsets: ['latin'], weight: ['500', '600'] });

const GOLD = '#E3C37E';

async function Form({ searchParams }: PageProps<'/masuk'>) {
  const sp = await searchParams;
  if (await getContexts()) redirect(safeNext(sp.next));
  const notice = sp.reset ? 'Kata sandi berhasil diganti. Silakan masuk dengan kata sandi baru.' : undefined;
  return <LoginForm next={safeNext(sp.next)} notice={notice} />;
}

function Brand() {
  return (
    <Link href="/" className="relative flex w-fit items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#E3C37E]">
      <svg aria-hidden="true" width="34" height="34" viewBox="0 0 30 30">
        <path d="M21 4 A13 13 0 1 0 21 26 A13 13 0 0 1 21 4Z" fill={GOLD} />
        <path d="M23 12 l1.2 2.6 2.8 0.4 -2 2 0.5 2.8 -2.5 -1.4 -2.5 1.4 0.5 -2.8 -2 -2 2.8 -0.4z" fill={GOLD} />
      </svg>
      <span className="font-display text-2xl font-semibold tracking-wide text-[#FBF7EE] lg:text-[28px]">UMRAH CONNECT</span>
    </Link>
  );
}

const POINTS: { text: string; icon: React.ReactNode }[] = [
  { text: 'Paket hanya dari PPIU berizin resmi Kemenag', icon: <><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z" /><path d="M8.5 12l2.5 2.5 4.5-5" /></> },
  { text: 'Bayar langsung ke rekening resmi travel', icon: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18" /></> },
  { text: 'Pantau jadwal dan dokumen keberangkatan', icon: <><path d="M6 3h9l4 4v14H6z" /><path d="M14 3v5h5M9 13h6M9 17h6" /></> },
];

/** Panel kiri: motif bintang, bulan sabit, siluet masjid (dekoratif, aria-hidden).
 *  Busur bulan sabit memakai jari-jari > setengah tali busur; versi desain asli berimpit sehingga tak terlihat. */
function Hero() {
  return (
    <section className="relative flex min-w-0 flex-col overflow-hidden bg-[#0A4F49] px-6 py-8 sm:px-10 lg:px-16 lg:py-12">
      <svg aria-hidden="true" className="absolute inset-0 h-full w-full opacity-[0.14]">
        <defs>
          <pattern id="uc-star" width="56" height="56" patternUnits="userSpaceOnUse">
            <path d="M28 5 L35 21 L51 28 L35 35 L28 51 L21 35 L5 28 L21 21 Z" fill="none" stroke={GOLD} strokeWidth="1" />
            <circle cx="28" cy="28" r="2" fill={GOLD} />
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#uc-star)" />
      </svg>

      <svg aria-hidden="true" width="150" height="150" viewBox="0 0 150 150" className="absolute right-16 top-10 hidden md:block">
        <g fill={GOLD}>
          <path d="M96 20 A34 34 0 1 0 96 80 A34 34 0 0 1 96 20Z" />
          <path d="M30 40 Q30 46 36 46 Q30 46 30 52 Q30 46 24 46 Q30 46 30 40Z" />
          <path d="M130 96 Q130 100 134 100 Q130 100 130 104 Q130 100 126 100 Q130 100 130 96Z" />
          <path d="M60 110 Q60 114 64 114 Q60 114 60 118 Q60 114 56 114 Q60 114 60 110Z" />
        </g>
      </svg>

      <svg aria-hidden="true" viewBox="0 60 640 300" preserveAspectRatio="xMaxYMax slice" className="absolute bottom-0 left-0 h-[96px] w-full sm:h-[150px] lg:h-[300px]">
        <g fill={GOLD} fillOpacity="0.08" stroke={GOLD} strokeOpacity="0.45" strokeWidth="1.4">
          <path d="M34 360 V330 A36 36 0 0 1 106 330 V360" />
          <path d="M142 360 V336 A28 28 0 0 1 198 336 V360" />
          <path d="M229 360 V270 H241 V360" />
          <path d="M227 270 L235 246 L243 270Z" />
          <path d="M235 246 V238" />
          <path d="M262 360 V342 A24 24 0 0 1 310 342 V360" />
        </g>
        <g fill={GOLD} fillOpacity="0.14" stroke={GOLD} strokeWidth="1.6">
          <path d="M370 360 V290 H510 V360" />
          <path d="M370 290 V280 A70 70 0 0 1 510 280 V290" />
          <path d="M440 210 V186" />
          <path d="M415 360 V322 A25 25 0 0 1 465 322 V360" fill="none" />
          <path d="M384 346 V330 a6 6 0 0 1 12 0 V346 M484 346 V330 a6 6 0 0 1 12 0 V346" fill="none" />
          <path d="M338 360 V170 H354 V360" />
          <path d="M336 170 L346 130 L356 170Z" />
          <path d="M346 130 V118 M333 208 H359" fill="none" />
          <path d="M526 360 V190 H542 V360" />
          <path d="M524 190 L534 152 L544 190Z" />
          <path d="M534 152 V140 M521 226 H547" fill="none" />
        </g>
        <path d="M445 166 A11.5 11.5 0 1 0 445 186 A11.5 11.5 0 0 1 445 166Z" fill={GOLD} />
        <path d="M350 104 A8 8 0 1 0 350 118 A8 8 0 0 1 350 104Z" fill={GOLD} />
        <path d="M538 130 A8 8 0 1 0 538 144 A8 8 0 0 1 538 130Z" fill={GOLD} />
      </svg>

      <Brand />

      <div className="relative max-w-[480px] pb-20 pt-6 sm:pb-32 sm:pt-10 lg:pb-[280px] lg:pt-[72px]">
        <p className="text-[13px] font-semibold tracking-[0.14em] text-[#E3C37E]">ASSALAMU&apos;ALAIKUM</p>
        <h1 className="mt-3 font-display text-[30px] sm:text-[34px] font-medium leading-[1.1] text-[#FBF7EE] lg:text-[52px]">Mulai perjalanan ibadah Anda</h1>
        <p className="mt-5 hidden text-base leading-relaxed sm:block text-[#E9E3D2] lg:text-[17px]">
          Temukan dan bandingkan paket umrah dari PPIU terverifikasi dalam satu tempat.
        </p>
        <ul className="mt-9 hidden flex-col gap-4 sm:flex">
          {POINTS.map((p) => (
            <li key={p.text} className="flex items-center gap-3.5 text-[15px] text-[#FBF7EE]">
              <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke={GOLD} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="shrink-0">
                {p.icon}
              </svg>
              {p.text}
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

export default function LoginPage(props: PageProps<'/masuk'>) {
  return (
    <div className={`${fraunces.variable} grid min-h-[calc(100vh-4rem)] bg-[#FBF7EE] text-[#16302D] lg:grid-cols-2`}>
      <Hero />
      <section className="flex min-w-0 items-center justify-center px-6 py-10 sm:px-8 lg:py-12">
        <div className="flex w-full max-w-[420px] flex-col gap-4">
          <div>
            <h2 className="font-display text-[30px] font-medium leading-[1.15] lg:text-[34px]">Masuk ke akun Anda</h2>
            <p className="mt-2 text-[15px] leading-normal text-[#3E4F4C]">Lanjutkan mencari dan memesan paket umrah.</p>
          </div>
          <Suspense fallback={<div className="h-96" />}>
            <Form {...props} />
          </Suspense>
          <p className="text-center text-sm text-[#3E4F4C]">
            Belum punya akun?{' '}
            <Link href="/daftar" className="font-bold text-[#0A4F49] hover:underline">Daftar sekarang</Link>
          </p>
        </div>
      </section>
    </div>
  );
}
