import type { ReactNode } from 'react';
import { PageBanner } from './ui';

/** Kerangka halaman hukum: daftar isi + bagian bernomor. */
export function LegalPage({ title, version, intro, sections }: { title: string; version: string; intro: ReactNode; sections: { id: string; title: string; body: ReactNode }[] }) {
  return (
    <>
      <PageBanner eyebrow={`Versi ${version} · draf`} title={title} />
      <div className="mx-auto grid max-w-5xl gap-8 px-4 py-8 lg:grid-cols-[220px_1fr]">
        <nav aria-label="Daftar isi" className="text-sm lg:sticky lg:top-24 lg:self-start">
          <p className="font-bold">Daftar isi</p>
          <ol className="mt-2 space-y-1">
            {sections.map((s, i) => <li key={s.id}><a href={`#${s.id}`} className="text-primary-700 hover:underline">{i + 1}. {s.title}</a></li>)}
          </ol>
        </nav>
        <article className="space-y-6 leading-7">
          <p className="rounded-[10px] border border-warning bg-warning-bg px-4 py-3 font-semibold">
            Draf untuk masa pengembangan. Wajib ditinjau konsultan hukum sebelum peluncuran (UU No. 27/2022 tentang Pelindungan Data Pribadi, PP 71/2019, dan regulasi penyelenggaraan umrah).
          </p>
          <div>{intro}</div>
          {sections.map((s, i) => (
            <section key={s.id} id={s.id} className="scroll-mt-24">
              <h2 className="text-xl font-extrabold">{i + 1}. {s.title}</h2>
              <div className="mt-2 space-y-2 [&_li]:ml-5 [&_li]:list-disc">{s.body}</div>
            </section>
          ))}
        </article>
      </div>
    </>
  );
}
