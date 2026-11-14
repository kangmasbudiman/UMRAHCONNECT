<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database Design v1.2 — migrasi langkah 4–8: agen, jamaah, booking, keuangan, sistem.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ===================== Agen dan kemitraan =====================
CREATE TABLE agents (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id               uuid NOT NULL UNIQUE REFERENCES users (id),
  code                  varchar(8) NOT NULL UNIQUE,
  nik_enc               text NOT NULL,
  nik_hash              varchar(64) NOT NULL UNIQUE,
  ktp_file_id           uuid REFERENCES files (id),
  domicile_city         varchar(100),
  payout_bank_name      varchar(50),
  payout_account_no_enc text,
  payout_account_holder varchar(150),
  status                varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
  created_at            timestamptz NOT NULL DEFAULT now(),
  updated_at            timestamptz NOT NULL DEFAULT now(),
  deleted_at            timestamptz
);

CREATE TABLE partnerships (
  id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id            uuid NOT NULL REFERENCES tenants (id),
  agent_id             uuid NOT NULL REFERENCES agents (id),
  status               varchar(20) NOT NULL CHECK (status IN ('invited', 'applied', 'active', 'rejected', 'suspended', 'ended')),
  source               varchar(10) NOT NULL CHECK (source IN ('applied', 'invited')),
  message              text,
  decided_by           uuid REFERENCES users (id),
  decided_at           timestamptz,
  reject_reason        text,
  status_note          text,
  agreement_consent_id uuid REFERENCES consents (id),
  invited_by           uuid REFERENCES users (id),
  invited_at           timestamptz,
  created_at           timestamptz NOT NULL DEFAULT now(),
  updated_at           timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, agent_id),
  CHECK (status <> 'invited' OR source = 'invited')
);

CREATE TABLE commission_schemes (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      uuid NOT NULL REFERENCES tenants (id),
  scope          varchar(20) NOT NULL CHECK (scope IN ('default', 'package', 'agent', 'agent_package')),
  package_id     uuid,
  agent_id       uuid REFERENCES agents (id),
  type           varchar(10) NOT NULL CHECK (type IN ('flat', 'percent')),
  flat_amount    bigint CHECK (flat_amount >= 0),
  percent_bp     integer CHECK (percent_bp BETWEEN 0 AND 10000),
  earn_on        varchar(20) NOT NULL DEFAULT 'fully_paid' CHECK (earn_on IN ('fully_paid', 'dp_verified')),
  effective_from timestamptz NOT NULL DEFAULT now(),
  effective_to   timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, package_id) REFERENCES packages (tenant_id, id),
  CHECK ((scope IN ('package', 'agent_package')) = (package_id IS NOT NULL)),
  CHECK ((scope IN ('agent', 'agent_package')) = (agent_id IS NOT NULL)),
  CHECK ((type = 'flat') = (flat_amount IS NOT NULL)),
  CHECK ((type = 'percent') = (percent_bp IS NOT NULL))
);
CREATE UNIQUE INDEX commission_schemes_one_active ON commission_schemes (tenant_id, scope, package_id, agent_id)
  NULLS NOT DISTINCT WHERE effective_to IS NULL;

CREATE TABLE referral_links (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      uuid NOT NULL,
  partnership_id uuid NOT NULL,
  agent_id       uuid NOT NULL REFERENCES agents (id),
  package_id     uuid,
  code           varchar(16) NOT NULL UNIQUE,
  click_count    integer NOT NULL DEFAULT 0,
  is_active      boolean NOT NULL DEFAULT true,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, partnership_id) REFERENCES partnerships (tenant_id, id),
  FOREIGN KEY (tenant_id, package_id) REFERENCES packages (tenant_id, id)
);

CREATE TABLE prospects (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agent_id          uuid NOT NULL REFERENCES agents (id),
  tenant_id         uuid REFERENCES tenants (id),
  package_id        uuid REFERENCES packages (id),
  name              varchar(150) NOT NULL,
  phone             varchar(20) NOT NULL,
  notes             text,
  follow_up_status  varchar(20) NOT NULL DEFAULT 'new'
                    CHECK (follow_up_status IN ('new', 'contacted', 'interested', 'booked', 'lost')),
  next_follow_up_on date,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);

-- ===================== Jamaah =====================
CREATE TABLE pilgrims (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  managed_by_user_id  uuid NOT NULL REFERENCES users (id),
  user_id             uuid REFERENCES users (id),
  full_name           varchar(150) NOT NULL,
  nik_enc             text,
  nik_hash            varchar(64),
  birth_place         varchar(100) NOT NULL,
  birth_date          date NOT NULL,
  gender              char(1) NOT NULL CHECK (gender IN ('L', 'P')),
  passport_no_enc     text,
  passport_expires_on date,
  phone               varchar(20),
  emergency_contact   jsonb,
  mahram_pilgrim_id   uuid REFERENCES pilgrims (id),
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  deleted_at          timestamptz
);
CREATE UNIQUE INDEX pilgrims_unique_nik_per_manager ON pilgrims (managed_by_user_id, nik_hash) WHERE deleted_at IS NULL;

CREATE TABLE pilgrim_documents (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  pilgrim_id  uuid NOT NULL REFERENCES pilgrims (id),
  type        varchar(20) NOT NULL CHECK (type IN ('ktp', 'passport', 'photo', 'vaccine', 'other')),
  file_id     uuid NOT NULL REFERENCES files (id),
  status      varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'replaced')),
  uploaded_by uuid REFERENCES users (id),
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE pilgrim_document_reviews (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           uuid NOT NULL REFERENCES tenants (id),
  pilgrim_document_id uuid NOT NULL REFERENCES pilgrim_documents (id),
  status              varchar(20) NOT NULL CHECK (status IN ('accepted', 'rejected')),
  note                text,
  reviewed_by         uuid NOT NULL REFERENCES users (id),
  reviewed_at         timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, pilgrim_document_id),
  CHECK (status = 'accepted' OR note IS NOT NULL)
);

-- ===================== Booking =====================
CREATE TABLE commission_payouts (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     uuid NOT NULL REFERENCES tenants (id),
  agent_id      uuid NOT NULL REFERENCES agents (id),
  total_amount  bigint NOT NULL CHECK (total_amount >= 0),
  status        varchar(20) NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'paid', 'rejected')),
  requested_by  uuid NOT NULL REFERENCES users (id),
  requested_at  timestamptz NOT NULL DEFAULT now(),
  paid_at       timestamptz,
  paid_by       uuid REFERENCES users (id),
  proof_file_id uuid REFERENCES files (id),
  reject_reason text,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id)
);

CREATE TABLE bookings (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id             uuid NOT NULL REFERENCES tenants (id),
  code                  varchar(20) NOT NULL UNIQUE,
  departure_id          uuid NOT NULL,
  package_id            uuid NOT NULL,
  booker_user_id        uuid NOT NULL REFERENCES users (id),
  agent_id              uuid REFERENCES agents (id),
  partnership_id        uuid,
  attribution_source    varchar(20) NOT NULL CHECK (attribution_source IN
                          ('agent_created', 'ppiu_assigned', 'manual_code', 'referral_link', 'none')),
  referral_link_id      uuid,
  channel               varchar(20) NOT NULL CHECK (channel IN ('marketplace', 'agent_portal', 'ppiu_dashboard')),
  status                varchar(20) NOT NULL DEFAULT 'awaiting_dp' CHECK (status IN
                          ('awaiting_dp', 'dp_verified', 'fully_paid', 'departed', 'completed', 'expired', 'cancelled')),
  participant_count     smallint NOT NULL CHECK (participant_count > 0),
  total_amount          bigint NOT NULL CHECK (total_amount > 0),
  dp_amount             bigint NOT NULL CHECK (dp_amount > 0 AND dp_amount <= total_amount),
  paid_amount           bigint NOT NULL DEFAULT 0 CHECK (paid_amount >= 0),
  commission_snapshot   jsonb,
  hold_expires_at       timestamptz,
  confirmed_at          timestamptz NOT NULL DEFAULT now(),
  dp_verified_at        timestamptz,
  fully_paid_at         timestamptz,
  cancelled_at          timestamptz,
  cancel_reason         text,
  cancelled_by          uuid REFERENCES users (id),
  cancel_request_status varchar(20) CHECK (cancel_request_status IN ('pending', 'approved', 'rejected')),
  cancel_requested_at   timestamptz,
  cancel_request_reason text,
  refund_decided_amount bigint CHECK (refund_decided_amount >= 0),
  refund_basis          text,
  created_by            uuid NOT NULL REFERENCES users (id),
  created_at            timestamptz NOT NULL DEFAULT now(),
  updated_at            timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, departure_id)     REFERENCES departures (tenant_id, id),
  FOREIGN KEY (tenant_id, package_id)       REFERENCES packages (tenant_id, id),
  FOREIGN KEY (tenant_id, partnership_id)   REFERENCES partnerships (tenant_id, id),
  FOREIGN KEY (tenant_id, referral_link_id) REFERENCES referral_links (tenant_id, id),
  CHECK ((agent_id IS NULL) = (partnership_id IS NULL)),
  CHECK ((attribution_source = 'none') = (agent_id IS NULL)),
  CHECK ((cancel_request_status IS NULL) = (cancel_requested_at IS NULL)),
  CHECK (refund_decided_amount IS NULL OR status = 'cancelled'),
  CHECK (refund_decided_amount IS NULL OR refund_decided_amount <= paid_amount),
  CHECK ((refund_decided_amount IS NULL) = (refund_basis IS NULL))
);
CREATE INDEX bookings_tenant_status_idx ON bookings (tenant_id, status);
CREATE INDEX bookings_tenant_departure_idx ON bookings (tenant_id, departure_id);
CREATE INDEX bookings_agent_status_idx ON bookings (agent_id, status);
CREATE INDEX bookings_expiry_idx ON bookings (status, hold_expires_at) WHERE status = 'awaiting_dp';
CREATE INDEX bookings_cancel_request_idx ON bookings (tenant_id, cancel_request_status) WHERE cancel_request_status = 'pending';

CREATE TABLE booking_participants (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    uuid NOT NULL REFERENCES tenants (id),
  booking_id   uuid NOT NULL,
  departure_id uuid NOT NULL,
  pilgrim_id   uuid NOT NULL REFERENCES pilgrims (id),
  room_type    varchar(10) NOT NULL CHECK (room_type IN ('quad', 'triple', 'double')),
  price_amount bigint NOT NULL CHECK (price_amount > 0),
  is_active    boolean NOT NULL DEFAULT true,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, booking_id)   REFERENCES bookings (tenant_id, id),
  FOREIGN KEY (tenant_id, departure_id) REFERENCES departures (tenant_id, id)
);
CREATE UNIQUE INDEX booking_participants_one_active_per_departure
  ON booking_participants (departure_id, pilgrim_id) WHERE is_active;

CREATE TABLE booking_notes (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id  uuid NOT NULL REFERENCES tenants (id),
  booking_id uuid NOT NULL,
  author_id  uuid NOT NULL REFERENCES users (id),
  body       text NOT NULL CHECK (length(trim(body)) > 0),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, booking_id) REFERENCES bookings (tenant_id, id)
);
CREATE INDEX booking_notes_booking_idx ON booking_notes (tenant_id, booking_id, created_at);

-- ===================== Keuangan =====================
CREATE TABLE invoices (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id       uuid NOT NULL REFERENCES tenants (id),
  booking_id      uuid NOT NULL,
  number          varchar(40) NOT NULL,
  type            varchar(10) NOT NULL CHECK (type IN ('dp', 'final', 'full')),
  amount          bigint NOT NULL CHECK (amount > 0),
  paid_amount     bigint NOT NULL DEFAULT 0 CHECK (paid_amount >= 0),
  due_at          timestamptz NOT NULL,
  bank_account_id uuid NOT NULL,
  status          varchar(20) NOT NULL DEFAULT 'unpaid' CHECK (status IN ('unpaid', 'partial', 'paid', 'cancelled')),
  pdf_file_id     uuid REFERENCES files (id),
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, number),
  FOREIGN KEY (tenant_id, booking_id)      REFERENCES bookings (tenant_id, id),
  FOREIGN KEY (tenant_id, bank_account_id) REFERENCES bank_accounts (tenant_id, id)
);

CREATE TABLE payments (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id             uuid NOT NULL REFERENCES tenants (id),
  invoice_id            uuid NOT NULL,
  method                varchar(20) NOT NULL CHECK (method IN ('bank_transfer', 'cash', 'reallocation', 'gateway')),
  claimed_amount        bigint NOT NULL CHECK (claimed_amount > 0),
  verified_amount       bigint CHECK (verified_amount >= 0),
  paid_on               date NOT NULL,
  sender_bank           varchar(50),
  sender_name           varchar(150),
  proof_file_id         uuid REFERENCES files (id),
  status                varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'verified', 'rejected')),
  reject_reason         text,
  verified_by           uuid REFERENCES users (id),
  verified_at           timestamptz,
  gateway_ref           varchar(100) UNIQUE,
  split_from_payment_id uuid,
  created_by            uuid NOT NULL REFERENCES users (id),
  created_at            timestamptz NOT NULL DEFAULT now(),
  updated_at            timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, invoice_id)            REFERENCES invoices (tenant_id, id),
  FOREIGN KEY (tenant_id, split_from_payment_id) REFERENCES payments (tenant_id, id),
  CHECK (split_from_payment_id IS DISTINCT FROM id)
);
CREATE INDEX payments_pending_idx ON payments (tenant_id, status) WHERE status = 'pending';

CREATE TABLE receipts (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   uuid NOT NULL REFERENCES tenants (id),
  payment_id  uuid NOT NULL UNIQUE,
  number      varchar(40) NOT NULL,
  amount      bigint NOT NULL CHECK (amount > 0),
  pdf_file_id uuid REFERENCES files (id),
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, number),
  FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id)
);

CREATE TABLE commissions (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      uuid NOT NULL REFERENCES tenants (id),
  booking_id     uuid NOT NULL UNIQUE,
  agent_id       uuid NOT NULL REFERENCES agents (id),
  partnership_id uuid NOT NULL,
  amount         bigint NOT NULL CHECK (amount >= 0),
  status         varchar(20) NOT NULL DEFAULT 'estimated'
                 CHECK (status IN ('estimated', 'earned', 'approved', 'paid', 'cancelled')),
  earned_at      timestamptz,
  approved_at    timestamptz,
  approved_by    uuid REFERENCES users (id),
  paid_at        timestamptz,
  cancelled_at   timestamptz,
  payout_id      uuid,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, booking_id)     REFERENCES bookings (tenant_id, id),
  FOREIGN KEY (tenant_id, partnership_id) REFERENCES partnerships (tenant_id, id),
  FOREIGN KEY (tenant_id, payout_id)      REFERENCES commission_payouts (tenant_id, id)
);
CREATE INDEX commissions_agent_status_idx ON commissions (agent_id, status);
CREATE INDEX commissions_tenant_status_idx ON commissions (tenant_id, status);

CREATE TABLE refunds (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id         uuid NOT NULL REFERENCES tenants (id),
  booking_id        uuid NOT NULL,
  type              varchar(20) NOT NULL CHECK (type IN ('bank_transfer', 'reallocation')),
  amount            bigint NOT NULL CHECK (amount > 0),
  target_booking_id uuid,
  paid_on           date NOT NULL,
  proof_file_id     uuid REFERENCES files (id),
  note              text NOT NULL,
  recorded_by       uuid NOT NULL REFERENCES users (id),
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, booking_id)        REFERENCES bookings (tenant_id, id),
  FOREIGN KEY (tenant_id, target_booking_id) REFERENCES bookings (tenant_id, id),
  CHECK ((type = 'reallocation') = (target_booking_id IS NOT NULL)),
  CHECK (target_booking_id IS DISTINCT FROM booking_id)
);
CREATE INDEX refunds_booking_idx ON refunds (tenant_id, booking_id);

-- ===================== Sistem =====================
CREATE TABLE audit_logs (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id     uuid,
  actor_user_id uuid,
  actor_context varchar(30),
  action        varchar(60) NOT NULL,
  entity_type   varchar(50) NOT NULL,
  entity_id     uuid NOT NULL,
  old_values    jsonb,
  new_values    jsonb,
  reason        text,
  ip_address    inet,
  user_agent    text,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_logs_tenant_created_idx ON audit_logs (tenant_id, created_at);
CREATE INDEX audit_logs_entity_idx ON audit_logs (entity_type, entity_id);

CREATE TABLE notifications (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id    uuid NOT NULL REFERENCES users (id),
  tenant_id  uuid REFERENCES tenants (id),
  event      varchar(60) NOT NULL,
  channel    varchar(20) NOT NULL CHECK (channel IN ('in_app', 'email', 'whatsapp', 'push')),
  payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
  status     varchar(20) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'failed')),
  sent_at    timestamptz,
  read_at    timestamptz,
  error      text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE document_sequences (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   uuid REFERENCES tenants (id),
  doc_type    varchar(20) NOT NULL CHECK (doc_type IN ('booking', 'invoice', 'receipt')),
  period      varchar(6) NOT NULL,
  last_number integer NOT NULL DEFAULT 0,
  UNIQUE NULLS NOT DISTINCT (tenant_id, doc_type, period)
);

CREATE TABLE support_sessions (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  admin_user_id uuid NOT NULL REFERENCES users (id),
  tenant_id     uuid NOT NULL REFERENCES tenants (id),
  reason        text NOT NULL,
  ticket_no     varchar(50) NOT NULL,
  started_at    timestamptz NOT NULL DEFAULT now(),
  expires_at    timestamptz NOT NULL,
  ended_at      timestamptz,
  CHECK (expires_at > started_at AND expires_at <= started_at + interval '60 minutes'),
  CHECK (ended_at IS NULL OR ended_at >= started_at)
);
CREATE INDEX support_sessions_open_idx ON support_sessions (admin_user_id, tenant_id) WHERE ended_at IS NULL;

-- FK yang ditunda untuk memutus ketergantungan melingkar (Database Design §14 langkah 8)
ALTER TABLE files   ADD CONSTRAINT files_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE tenants ADD CONSTRAINT tenants_logo_file_id_fkey FOREIGN KEY (logo_file_id) REFERENCES files (id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE IF EXISTS tenants DROP CONSTRAINT IF EXISTS tenants_logo_file_id_fkey;
ALTER TABLE IF EXISTS files DROP CONSTRAINT IF EXISTS files_tenant_id_fkey;
DROP TABLE IF EXISTS support_sessions, document_sequences, notifications, audit_logs, refunds, commissions,
  receipts, payments, invoices, booking_notes, booking_participants, bookings, commission_payouts,
  pilgrim_document_reviews, pilgrim_documents, pilgrims, prospects, referral_links, commission_schemes,
  partnerships, agents CASCADE;
SQL);
    }
};
