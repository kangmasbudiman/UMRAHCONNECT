'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Spinner } from '@/components/form';
import { api, errorMessage } from '@/lib/client-api';

export function MarkAllRead() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  return (
    <button type="button" className="btn btn-outline min-h-10 px-4" disabled={busy} onClick={async () => {
      setBusy(true);
      try { await api('/me/notifications/read', { json: {} }); router.refresh(); } catch (e) { alert(errorMessage(e)); }
      setBusy(false);
    }}>{busy && <Spinner />}Tandai semua dibaca</button>
  );
}
