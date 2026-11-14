import Link from 'next/link';
import { Suspense } from 'react';
import { AuthShell } from '@/components/form';
import { redirect } from 'next/navigation';
import { safeNext } from '@/lib/nav';
import { getContexts } from '@/lib/session';
import { LoginForm } from './login-form';

export const metadata = { title: 'Masuk' };

async function Form({ searchParams }: PageProps<'/masuk'>) {
  const sp = await searchParams;
  if (await getContexts()) redirect(safeNext(sp.next));
  const notice = sp.reset ? 'Kata sandi berhasil diganti. Silakan masuk dengan kata sandi baru.' : undefined;
  return <LoginForm next={safeNext(sp.next)} notice={notice} />;
}

export default function LoginPage(props: PageProps<'/masuk'>) {
  return (
    <AuthShell
      title="Masuk ke UMRAH CONNECT"
      sub="Untuk jamaah, agen, dan tim PPIU."
      footer={<>Belum punya akun? <Link href="/daftar" className="font-bold text-primary-700 hover:underline">Daftar</Link></>}
    >
      <Suspense fallback={<div className="h-72" />}>
        <Form {...props} />
      </Suspense>
    </AuthShell>
  );
}
