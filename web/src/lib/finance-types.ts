/** Bentuk respons komisi, payout, laporan, notifikasi, dasbor (api/openapi.yaml). */
export type CommissionStatus = 'estimated' | 'earned' | 'approved' | 'paid' | 'cancelled';
export type CommissionRow = {
  id: string; amount: number; status: CommissionStatus; earned_at: string | null; approved_at: string | null; paid_at: string | null; payout_id: string | null;
  booking: { id: string; code: string; status: string; participant_count: number; total_amount: number; package_name: string; departure_date: string };
  tenant: { id: string; brand_name: string };
  agent?: { id: string; code: string; display_name: string };
};
export type Totals = Partial<Record<CommissionStatus, { count: number; amount: number }>>;
export type Payout = {
  id: string; tenant: { id: string; brand_name: string };
  agent: { id: string; code: string; display_name: string; payout_bank_name: string | null; payout_account_no_masked: string | null; payout_account_holder: string | null };
  total_amount: number; status: 'requested' | 'paid' | 'rejected'; requested_at: string | null; paid_at: string | null; reject_reason: string | null;
  commission_ids: string[]; commission_count: number;
};
export type ReportColumn = { key: string; label: string; money?: boolean };
export type Report = { columns: ReportColumn[]; rows: Record<string, string | number | null>[]; totals: Record<string, number> };
export type AppNotification = { id: string; event: string; payload: { title: string; body: string; url?: string }; read_at: string | null; created_at: string };
export type TenantDashboard = {
  bookings_by_status: Record<string, number>; bookings_this_month: number; sales_this_month: number; received_this_month: number;
  pending_payments: { count: number; amount: number }; cancel_requests: number; commissions_to_approve: { count: number; amount: number };
  payout_requests: number; refunds_pending: { count: number; amount: number };
  upcoming_departures: { id: string; package_name: string; departure_date: string; departure_city: string; quota: number; seats_held: number; seats_confirmed: number; seats_remaining: number; sales_status: string }[];
};

export const COMMISSION_STATUS: Record<CommissionStatus, string> = {
  estimated: 'Perkiraan', earned: 'Diperoleh', approved: 'Disetujui', paid: 'Dibayar', cancelled: 'Batal',
};
