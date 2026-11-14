/** Bentuk respons API konteks agen (api/openapi.yaml: AgentProfile, Partnership, CommissionScheme, ReferralLink). */
export type Scheme = {
  id: string; scope: 'default' | 'package' | 'agent' | 'agent_package'; package_id: string | null; agent_id: string | null;
  type: 'flat' | 'percent'; flat_amount: number | null; percent_bp: number | null; earn_on: 'fully_paid' | 'dp_verified';
  effective_from: string; effective_to: string | null; label: string;
  package_name?: string | null; agent?: AgentRef | null;
};
export type AgentRef = { id: string; code: string; display_name: string; domicile_city: string | null; phone?: string | null; email?: string | null };
export type AgentProfile = {
  id: string; code: string; display_name: string; nik_masked: string; ktp_file_id: string | null; domicile_city: string | null;
  payout_bank_name: string | null; payout_account_no_masked: string | null; payout_account_holder: string | null;
  status: 'active' | 'suspended'; whatsapp: string | null;
};
export type Partnership = {
  id: string; tenant: { id: string; slug: string; brand_name: string; whatsapp: string | null }; agent: AgentRef;
  status: 'invited' | 'applied' | 'active' | 'rejected' | 'suspended' | 'ended'; source: 'applied' | 'invited';
  message: string | null; reject_reason: string | null; status_note: string | null; commission_scheme: Scheme | null;
  decided_at: string | null; created_at: string | null; referral_clicks?: number;
};
export type AgentTenant = {
  id: string; slug: string; brand_name: string; legal_name: string; ppiu_license_no: string; city: string | null; province: string | null;
  whatsapp: string | null; partnership_id: string | null; partnership_status: Partnership['status'] | null; commission_scheme: Scheme | null;
};
export type AgentPackage = {
  id: string; slug: string; name: string; duration_days: number; airline: string;
  tenant: { id: string; slug: string; brand_name: string }; cover_image_url: string | null; price_from_amount: number | null;
  next_departure: { departure_date: string; departure_city: string; seats_remaining: number } | null;
  commission_scheme: Scheme | null; referral_code: string | null;
};
export type ReferralLink = {
  id: string; tenant_id: string; tenant_name: string; package_id: string | null; package_name: string | null;
  code: string; url: string; click_count: number; is_active: boolean; created_at: string | null;
};

export const PARTNER_STATUS: Record<Partnership['status'], string> = {
  invited: 'Diundang', applied: 'Menunggu persetujuan', active: 'Aktif', rejected: 'Ditolak', suspended: 'Ditangguhkan', ended: 'Berakhir',
};
export const EARN_ON: Record<Scheme['earn_on'], string> = { fully_paid: 'saat booking lunas', dp_verified: 'saat DP terverifikasi' };
