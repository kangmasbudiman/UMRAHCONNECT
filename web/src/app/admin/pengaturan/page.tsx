import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { requireContexts, serverApi } from '@/lib/session';
import { AdminHeader } from '../admin-nav';
import { SettingForm } from '../platform-actions';

export const metadata = { title: 'Pengaturan platform' };

type Setting = { key: string; label: string; help: string; value: number | string; default: number | string; updated_at: string | null; updated_by: string | null };
const wib = (iso: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso));

/** ADM-09 Pengaturan sistem (FR-ADM-09), hanya Super Admin. Setiap perubahan tercatat di audit log. */
async function SettingsList() {
  const ctx = await requireContexts('/admin/pengaturan');
  if (ctx.platform_role !== 'super_admin') notFound();
  const rows = (await serverApi<{ data: Setting[] }>('/platform/settings')).data;
  return (
    <ul className="space-y-3">
      {rows.map((s) => (
        <li key={s.key} className="card space-y-2 p-5">
          <p className="font-bold">{s.label}</p>
          <p className="text-sm text-ink-600">{s.help} Bawaan: <span className="num">{s.default === 'fully_paid' ? 'saat lunas' : s.default === 'dp_verified' ? 'saat DP terverifikasi' : String(s.default)}</span>.</p>
          <SettingForm settingKey={s.key} value={s.value} kind={typeof s.default === 'number' ? 'number' : s.key === 'commission.earn_on_default' ? 'earn_on' : 'text'} />
          {s.updated_at && <p className="text-xs text-ink-600">Terakhir diubah {wib(s.updated_at)}{s.updated_by && ` oleh ${s.updated_by}`}</p>}
        </li>
      ))}
    </ul>
  );
}

export default function SettingsPage() {
  return (
    <>
      <AdminHeader active="pengaturan" title="Pengaturan platform" sub="Nilai bawaan yang berlaku untuk seluruh platform." />
      <div className="mx-auto max-w-3xl space-y-4 px-4 py-6"><Suspense fallback={<div className="h-96 animate-pulse rounded-[12px] bg-white" />}><SettingsList /></Suspense></div>
    </>
  );
}
