import { Suspense } from 'react';
import { serverApi } from '@/lib/session';
import type { AgentProfile } from '@/lib/agent-types';
import { AgentHeader, requireAgent } from '../agent-shell';
import { AgentProfileForm } from '../agent-forms';

export const metadata = { title: 'Profil agen' };

async function Profile() {
  const ctx = await requireAgent('/agen/profil');
  const profile = (await serverApi<{ data: AgentProfile }>('/agent/profile')).data;
  return (
    <>
      <AgentHeader ctx={ctx} active="profil" title="Profil agen" />
      <div className="mx-auto max-w-4xl px-4 py-6">
        <div className="card p-5 md:p-6">
          <dl className="mb-5 grid gap-3 sm:grid-cols-3">
            <div><dt className="text-sm text-ink-600">Kode agen</dt><dd className="num text-lg font-extrabold">{profile.code}</dd></div>
            <div><dt className="text-sm text-ink-600">Nama</dt><dd className="font-bold">{profile.display_name}</dd></div>
            <div><dt className="text-sm text-ink-600">WhatsApp</dt><dd className="font-bold">{profile.whatsapp ?? '—'}</dd></div>
          </dl>
          <AgentProfileForm profile={profile} />
        </div>
      </div>
    </>
  );
}

export default function AgentProfilePage() {
  return <Suspense fallback={<div className="mx-auto max-w-4xl px-4 py-10"><div className="h-96 animate-pulse rounded-[12px] bg-white" /></div>}><Profile /></Suspense>;
}
