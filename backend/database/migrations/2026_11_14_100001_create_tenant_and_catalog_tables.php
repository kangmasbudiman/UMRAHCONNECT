<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database Design v1.2 — migrasi langkah 2–3: tenant (PPIU) dan katalog paket.
 * Semua tabel milik tenant punya UNIQUE (tenant_id, id) untuk FK komposit (isolasi tenant, §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE tenants (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code                varchar(10) NOT NULL UNIQUE,
  slug                varchar(100) NOT NULL UNIQUE,
  legal_name          varchar(200) NOT NULL,
  brand_name          varchar(200) NOT NULL,
  ppiu_license_no     varchar(100) NOT NULL UNIQUE,
  license_expires_on  date NOT NULL,
  nib                 varchar(30),
  npwp                varchar(30),
  address             text,
  city                varchar(100),
  province            varchar(100),
  phone               varchar(20),
  whatsapp            varchar(20),
  email               citext,
  logo_file_id        uuid,
  verification_status varchar(30) NOT NULL DEFAULT 'draft' CHECK (verification_status IN
                        ('draft', 'pending_review', 'needs_revision', 'verified', 'rejected', 'suspended')),
  verified_at         timestamptz,
  verified_by         uuid REFERENCES users (id),
  status_note         text,
  settings            jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  deleted_at          timestamptz
);

CREATE TABLE tenant_members (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id  uuid NOT NULL REFERENCES tenants (id),
  user_id    uuid NOT NULL REFERENCES users (id),
  role       varchar(20) NOT NULL CHECK (role IN ('owner', 'admin', 'finance')),
  status     varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('invited', 'active', 'deactivated')),
  invited_by uuid REFERENCES users (id),
  invited_at timestamptz,
  joined_at  timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, user_id)
);
CREATE UNIQUE INDEX tenant_members_one_active_owner ON tenant_members (tenant_id)
  WHERE role = 'owner' AND status = 'active';

CREATE TABLE tenant_documents (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   uuid NOT NULL REFERENCES tenants (id),
  type        varchar(30) NOT NULL CHECK (type IN ('akta', 'nib', 'npwp', 'ppiu_license', 'pic_ktp', 'other')),
  file_id     uuid NOT NULL REFERENCES files (id),
  status      varchar(20) NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted', 'accepted', 'rejected')),
  review_note text,
  reviewed_by uuid REFERENCES users (id),
  reviewed_at timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id)
);

CREATE TABLE bank_accounts (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      uuid NOT NULL REFERENCES tenants (id),
  bank_name      varchar(50) NOT NULL,
  account_no     varchar(30) NOT NULL,
  account_holder varchar(150) NOT NULL,
  status         varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'verified', 'inactive')),
  is_default     boolean NOT NULL DEFAULT false,
  verified_by    uuid REFERENCES users (id),
  verified_at    timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id)
);
CREATE UNIQUE INDEX bank_accounts_one_default ON bank_accounts (tenant_id) WHERE is_default;

CREATE TABLE consents (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     uuid NOT NULL REFERENCES users (id),
  document    varchar(30) NOT NULL CHECK (document IN ('terms', 'privacy', 'partnership_terms')),
  version     varchar(20) NOT NULL,
  tenant_id   uuid REFERENCES tenants (id),
  accepted_at timestamptz NOT NULL DEFAULT now(),
  ip_address  inet,
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE packages (
  id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id                uuid NOT NULL REFERENCES tenants (id),
  slug                     varchar(150) NOT NULL,
  name                     varchar(200) NOT NULL,
  duration_days            smallint NOT NULL CHECK (duration_days > 0),
  airline                  varchar(100) NOT NULL,
  hotel_makkah             varchar(150),
  hotel_makkah_stars       smallint CHECK (hotel_makkah_stars BETWEEN 1 AND 5),
  hotel_makkah_distance_m  integer CHECK (hotel_makkah_distance_m >= 0),
  hotel_madinah            varchar(150),
  hotel_madinah_stars      smallint CHECK (hotel_madinah_stars BETWEEN 1 AND 5),
  hotel_madinah_distance_m integer CHECK (hotel_madinah_distance_m >= 0),
  description              text,
  itinerary                jsonb NOT NULL DEFAULT '[]'::jsonb,
  facilities               jsonb NOT NULL DEFAULT '[]'::jsonb,
  includes                 jsonb NOT NULL DEFAULT '[]'::jsonb,
  excludes                 jsonb NOT NULL DEFAULT '[]'::jsonb,
  document_requirements    text,
  payment_terms            text,
  cancellation_terms       text,
  status                   varchar(20) NOT NULL DEFAULT 'draft'
                           CHECK (status IN ('draft', 'published', 'archived', 'taken_down')),
  taken_down_reason        text,
  published_at             timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now(),
  deleted_at               timestamptz,
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, slug)
);
CREATE INDEX packages_status_airline_idx ON packages (status, airline);
CREATE INDEX packages_name_trgm_idx ON packages USING gin (name gin_trgm_ops);
CREATE INDEX packages_hotel_makkah_trgm_idx ON packages USING gin (hotel_makkah gin_trgm_ops);
CREATE INDEX packages_hotel_madinah_trgm_idx ON packages USING gin (hotel_madinah gin_trgm_ops);

CREATE TABLE package_images (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id  uuid NOT NULL,
  package_id uuid NOT NULL,
  file_id    uuid NOT NULL REFERENCES files (id),
  sort_order smallint NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, package_id) REFERENCES packages (tenant_id, id) ON DELETE CASCADE
);

CREATE TABLE departures (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id             uuid NOT NULL REFERENCES tenants (id),
  package_id            uuid NOT NULL,
  departure_date        date NOT NULL,
  return_date           date NOT NULL,
  departure_city        varchar(100) NOT NULL,
  quota                 integer NOT NULL CHECK (quota > 0),
  seats_held            integer NOT NULL DEFAULT 0 CHECK (seats_held >= 0),
  seats_confirmed       integer NOT NULL DEFAULT 0 CHECK (seats_confirmed >= 0),
  dp_min_per_pax_amount bigint  NOT NULL CHECK (dp_min_per_pax_amount > 0),
  dp_deadline_hours     smallint NOT NULL DEFAULT 48,
  final_payment_due_on  date NOT NULL,
  sales_status          varchar(20) NOT NULL DEFAULT 'open' CHECK (sales_status IN ('open', 'closed', 'full')),
  status                varchar(20) NOT NULL DEFAULT 'scheduled'
                        CHECK (status IN ('scheduled', 'departed', 'completed', 'cancelled')),
  created_at            timestamptz NOT NULL DEFAULT now(),
  updated_at            timestamptz NOT NULL DEFAULT now(),
  deleted_at            timestamptz,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, package_id) REFERENCES packages (tenant_id, id),
  CHECK (return_date >= departure_date),
  CHECK (seats_held + seats_confirmed <= quota)
);
CREATE INDEX departures_sales_idx ON departures (sales_status, departure_date) WHERE deleted_at IS NULL;

CREATE TABLE departure_prices (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    uuid NOT NULL,
  departure_id uuid NOT NULL,
  room_type    varchar(10) NOT NULL CHECK (room_type IN ('quad', 'triple', 'double')),
  price_amount bigint NOT NULL CHECK (price_amount > 0),
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  UNIQUE (departure_id, room_type),
  FOREIGN KEY (tenant_id, departure_id) REFERENCES departures (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX departure_prices_room_price_idx ON departure_prices (room_type, price_amount);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS departure_prices, departures, package_images, packages, consents, bank_accounts, tenant_documents, tenant_members, tenants CASCADE;');
    }
};
