import type { Region } from '@/components/region-select';

/** Bentuk respons API konteks tenant & platform (api/openapi.yaml: Tenant, TenantDocument, BankAccount, Member). */
export type Onboarding = { profile_complete: boolean; license_valid: boolean; documents_missing: string[]; has_bank_account: boolean; can_submit: boolean };
export type TenantFull = {
  id: string; code: string; slug: string; legal_name: string; brand_name: string; ppiu_license_no: string; license_expires_on: string | null;
  nib: string | null; npwp: string | null; address: string | null; city: string | null; province: string | null;
  postal_code: string | null; region: Region; latitude: number | null; longitude: number | null;
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

export type RoomType = 'quad' | 'triple' | 'double';
export type ItineraryDay = { day: number; title: string; activities: string[] };
export type PackageImage = { id: string; file_id: string; url: string | null; sort_order: number };
export type DepartureManaged = {
  id: string; package_id: string; departure_date: string; return_date: string; departure_city: string;
  quota: number; seats_held: number; seats_confirmed: number; seats_remaining: number;
  dp_min_per_pax_amount: number; dp_deadline_hours: number; final_payment_due_on: string;
  prices: { room_type: RoomType; price_amount: number }[];
  sales_status: 'open' | 'closed' | 'full'; status: 'scheduled' | 'departed' | 'completed' | 'cancelled'; etag: string;
};
export type PackageManaged = {
  id: string; slug: string; status: 'draft' | 'published' | 'archived' | 'taken_down'; taken_down_reason: string | null;
  name: string; duration_days: number; airline: string;
  hotel_makkah: string | null; hotel_makkah_stars: number | null; hotel_makkah_distance_m: number | null;
  hotel_madinah: string | null; hotel_madinah_stars: number | null; hotel_madinah_distance_m: number | null;
  description: string | null; itinerary: ItineraryDay[]; facilities: string[]; includes: string[]; excludes: string[];
  document_requirements: string | null; payment_terms: string | null; cancellation_terms: string | null;
  published_at: string | null; updated_at: string | null; images: PackageImage[];
  departures_count: number; price_from_amount: number | null; next_departure_date: string | null; etag: string;
  departures?: DepartureManaged[];
  publish_checklist?: { items: Record<string, boolean>; complete: boolean; has_images: boolean };
};

export const PUBLISH_ITEMS: Record<string, string> = {
  description: 'Deskripsi paket', hotels: 'Hotel Makkah dan Madinah', itinerary: 'Rencana perjalanan',
  includes: 'Daftar yang termasuk', payment_terms: 'Ketentuan pembayaran', cancellation_terms: 'Kebijakan pembatalan',
  departure: 'Minimal satu keberangkatan mendatang dengan harga',
};
