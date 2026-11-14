import type { Metadata } from 'next';
import { Suspense } from 'react';
import { Plus_Jakarta_Sans } from 'next/font/google';
import { SiteFooter, SiteHeader } from '@/components/site-chrome';
import { CompareTray } from '@/components/compare';
import './globals.css';

const jakarta = Plus_Jakarta_Sans({ variable: '--font-jakarta', subsets: ['latin'], weight: ['400', '600', '700', '800'] });

export const metadata: Metadata = {
  title: { default: 'UMRAH CONNECT — Paket umrah dari PPIU terverifikasi', template: '%s · UMRAH CONNECT' },
  description: 'Bandingkan paket umrah dari PPIU terverifikasi di Jambi. Harga resmi PPIU, pembayaran langsung ke rekening resmi travel.',
};

export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html lang="id" className={`${jakarta.variable} h-full antialiased`}>
      <body className="flex min-h-full flex-col font-sans text-base leading-6">
        <a href="#isi" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2">
          Lewati ke isi
        </a>
        <SiteHeader />
        <main id="isi" className="flex-1">{children}</main>
        <SiteFooter />
        <Suspense fallback={null}><CompareTray /></Suspense>
      </body>
    </html>
  );
}
