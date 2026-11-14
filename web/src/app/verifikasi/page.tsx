import Link from 'next/link';
import { redirect } from 'next/navigation';
import { Suspense } from 'react';
import { AuthShell } from '@/components/form';
import { safeNext } from '@/lib/nav';
import { requireContexts } from '@/lib/session';
import { OtpForm } from './otp-form';

export const metadata = { title: 'Verifikasi akun' };

async function Content({ searchParams }: PageProps<'/verifikasi'>) {
  const sp = await searchParams;
  const next = safeNext(sp.next);
  const ctx = await requireContexts(`/verifikasi?next=${encodeURIComponent(next)}`);
  if (ctx.contact_verified) redirect(next);

  const u = ctx.user;
  const viaEmail = !!u.email;
  const identifier = (viaEmail ? u.email : u.phone) as string;
  // Akun baru (< 2 menit) baru saja dikirimi kode saat registrasi.
  return <OtpForm identifier={identifier} channel={viaEmail ? 'email' : 'whatsapp'} next={next} justSent={sp.baru === '1'} />;
}

export default function VerifyPage(props: PageProps<'/verifikasi'>) {
  return (
    <AuthShell
      title="Verifikasi akun Anda"
      sub="Satu langkah lagi. Masukkan kode yang kami kirim untuk memastikan kontak ini milik Anda."
      footer={<Link href="/akun" className="font-bold text-primary-700 hover:underline">Lewati, verifikasi nanti</Link>}
    >
      <Suspense fallback={<div className="h-64" />}>
        <Content {...props} />
      </Suspense>
    </AuthShell>
  );
}
