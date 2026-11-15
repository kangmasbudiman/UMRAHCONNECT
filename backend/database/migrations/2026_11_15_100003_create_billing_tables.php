<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modul pendapatan platform (BRD: langganan, biaya teknologi per transaksi, promosi paket).
 * Semua tagihan ditujukan ke PPIU/agen; platform tidak pernah menagih atau memegang dana jamaah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE plans (
  code          varchar(30) PRIMARY KEY,
  audience      varchar(10) NOT NULL CHECK (audience IN ('tenant', 'agent')),
  name          varchar(60) NOT NULL,
  price_monthly bigint NOT NULL DEFAULT 0 CHECK (price_monthly >= 0),
  features      jsonb NOT NULL DEFAULT '{}'::jsonb,
  is_public     boolean NOT NULL DEFAULT true,
  sort          smallint NOT NULL DEFAULT 0,
  updated_at    timestamptz NOT NULL DEFAULT now()
);
INSERT INTO plans (code, audience, name, price_monthly, features, is_public, sort) VALUES
  ('ppiu_free', 'tenant', 'PPIU Free', 0, '{"max_members": 3, "manifest_export": false}', true, 1),
  ('ppiu_business', 'tenant', 'PPIU Business', 499000, '{"max_members": 15, "manifest_export": true}', true, 2),
  ('ppiu_enterprise', 'tenant', 'PPIU Enterprise', 0, '{"max_members": 100, "manifest_export": true}', false, 3),
  ('agent_free', 'agent', 'Agen Free', 0, '{"public_page": false}', true, 1),
  ('agent_premium', 'agent', 'Agen Premium', 39000, '{"public_page": true}', true, 2);

CREATE TABLE subscriptions (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  subscriber_type varchar(10) NOT NULL CHECK (subscriber_type IN ('tenant', 'agent')),
  subscriber_id   uuid NOT NULL,
  plan_code       varchar(30) NOT NULL REFERENCES plans (code),
  status          varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'past_due', 'cancelled')),
  price_override  bigint CHECK (price_override >= 0),
  started_at      timestamptz NOT NULL DEFAULT now(),
  ended_at        timestamptz,
  created_by      uuid REFERENCES users (id),
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX subscriptions_one_open ON subscriptions (subscriber_type, subscriber_id) WHERE status IN ('active', 'past_due');

CREATE TABLE featured_placements (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     uuid NOT NULL REFERENCES tenants (id),
  package_id    uuid NOT NULL,
  status        varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'ended', 'cancelled')),
  price_monthly bigint NOT NULL CHECK (price_monthly >= 0),
  starts_at     timestamptz NOT NULL DEFAULT now(),
  ends_at       timestamptz,
  created_by    uuid REFERENCES users (id),
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (tenant_id, package_id) REFERENCES packages (tenant_id, id)
);
CREATE UNIQUE INDEX featured_one_active_per_package ON featured_placements (package_id) WHERE status = 'active';

CREATE TABLE platform_invoices (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  number         varchar(30) NOT NULL UNIQUE,
  billable_type  varchar(10) NOT NULL CHECK (billable_type IN ('tenant', 'agent')),
  billable_id    uuid NOT NULL,
  period         char(7) NOT NULL,
  status         varchar(30) NOT NULL DEFAULT 'unpaid' CHECK (status IN ('unpaid', 'awaiting_confirmation', 'paid', 'void')),
  total          bigint NOT NULL CHECK (total >= 0),
  due_on         date NOT NULL,
  issued_at      timestamptz NOT NULL DEFAULT now(),
  paid_at        timestamptz,
  proof_file_id  uuid REFERENCES files (id),
  payer_note     varchar(500),
  review_note    varchar(500),
  reviewed_by    uuid REFERENCES users (id),
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (billable_type, billable_id, period)
);
CREATE INDEX platform_invoices_status_idx ON platform_invoices (status, due_on);

CREATE TABLE platform_invoice_items (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  invoice_id  uuid NOT NULL REFERENCES platform_invoices (id) ON DELETE CASCADE,
  type        varchar(20) NOT NULL CHECK (type IN ('subscription', 'transaction_fee', 'featured', 'adjustment')),
  description varchar(200) NOT NULL,
  quantity    integer NOT NULL DEFAULT 1,
  unit_amount bigint NOT NULL,
  amount      bigint NOT NULL,
  ref_id      uuid
);

CREATE TABLE transaction_fees (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   uuid NOT NULL REFERENCES tenants (id),
  booking_id  uuid NOT NULL UNIQUE,
  amount      bigint NOT NULL CHECK (amount >= 0),
  status      varchar(20) NOT NULL DEFAULT 'accrued' CHECK (status IN ('accrued', 'invoiced', 'waived')),
  accrued_at  timestamptz NOT NULL DEFAULT now(),
  invoice_id  uuid REFERENCES platform_invoices (id),
  FOREIGN KEY (tenant_id, booking_id) REFERENCES bookings (tenant_id, id)
);

ALTER TABLE document_sequences DROP CONSTRAINT IF EXISTS document_sequences_doc_type_check;
ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_doc_type_check CHECK (doc_type IN ('booking', 'invoice', 'receipt', 'platform_invoice'));
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS transaction_fees, platform_invoice_items, platform_invoices, featured_placements, subscriptions, plans CASCADE;
ALTER TABLE document_sequences DROP CONSTRAINT IF EXISTS document_sequences_doc_type_check;
ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_doc_type_check CHECK (doc_type IN ('booking', 'invoice', 'receipt'));
SQL);
    }
};
