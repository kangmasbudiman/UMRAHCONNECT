/** Bentuk respons API konteks tenant & platform (api/openapi.yaml: Tenant, TenantDocument, BankAccount, Member). */
export type Onboarding = { profile_complete: boolean; license_valid: boolean; documents_missing: string[]; has_bank_account: boolean; can_submit: boolean };
export type TenantFull = {
  id: string; code: string; slug: string; legal_name: string; brand_name: string; ppiu_license_no: string; license_expires_on: string | null;
  nib: string | null; npwp: string | null; address: string | null; city: string | null; province: string | null;
  phone: string | null; whatsapp: string | null; email: string | null;
  verification_status: string; verified_at: string | null; status_note: string | null; created_at: string | null;
  onboarding: Onboarding;
};
export type FileInfo = { id: string; purpose: string; mime_type: string; size_bytes: number; original_name: string | null; url: string | null };
export type TenantDoc = { id: string; type: string; file_id: string; file_name: string | null; status: string; review_note: string | null; created_at: string | null; file?: FileInfo };
export type BankAccount = { id: string; bank_name: string; account_no: string; account_holder: string; status: string; is_default: boolean };
export type MemberUser = { id: string; name: string; email: string | null; phone: string | null; two_factor_enabled: boolean };
export type Member = { id: string; user: MemberUser; role: 'owner' | 'admin' | 'finance'; status: string; invited_at: string | null; joined_at: string | null };

export const DOC_TYPES: { type: string; label: string; hint: string }[] = [
  { type: 'akta', label: 'Akta pendirian & perubahan', hint: 'Akta notaris beserta SK Kemenkumham' },
  { type: 'nib', label: 'NIB', hint: 'Nomor Induk Berusaha dari OSS' },
  { type: 'npwp', label: 'NPWP badan usaha', hint: 'Kartu atau surat keterangan terdaftar' },
  { type: 'ppiu_license', label: 'Izin PPIU', hint: 'SK izin penyelenggara umrah yang masih berlaku' },
  { type: 'pic_ktp', label: 'KTP penanggung jawab', hint: 'KTP direktur/penanggung jawab yang terbaca jelas' },
];
export const DOC_LABEL: Record<string, string> = Object.fromEntries([...DOC_TYPES.map((d) => [d.type, d.label]), ['other', 'Dokumen lain']]);
