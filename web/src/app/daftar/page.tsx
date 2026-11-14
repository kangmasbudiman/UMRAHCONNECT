import Link from 'next/link';
import { Suspense } from 'react';
import { AuthShell } from '@/components/form';
import { redirect } from 'next/navigation';
import { getContexts } from '@/lib/session';
import { RegisterForm } from './register-form';

export const metadata = { title: 'Daftar akun' };

async function Form({ searchParams }: PageProps<'/daftar'>) {
  const peran = (await searchParams).peran;
  if (await getContexts()) redirect(peran === 'ppiu' ? '/dasbor/ppiu/daftar' : '/akun');
  const role = peran === 'ppiu' || peran === 'agen' ? peran : 'jamaah';
  return <RegisterForm initialRole={role} />;
}

export default function RegisterPage(props: PageProps<'/daftar'>) {
  return (
    <AuthShell
      title="Buat akun UMRAH CONNECT"
      sub="Gratis. Satu akun untuk memesan, memasarkan, atau mengelola PPIU."
      footer={<>Sudah punya akun? <Link href="/masuk" className="font-bold text-primary-700 hover:underline">Masuk</Link></>}
    >
      <Suspense fallback={<div className="h-[560px]" />}>
        <Form {...props} />
      </Suspense>
    </AuthShell>
  );
}
