<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wilayah administratif Indonesia (provinsi → kab/kota → kecamatan → kelurahan/desa) untuk skala nasional.
 * Sumber: Kepmendagri No 300.2.2-2138 Tahun 2025 via github.com/cahyadsn/wilayah (lisensi MIT),
 * disimpan di database/data/regions.csv.gz. Data dimuat di migrasi agar tes dan produksi selalu punya wilayah.
 *
 * Alamat menyimpan keempat kode (bukan teks bebas) agar bisa difilter per wilayah; kolom teks lama
 * (tenants.city/province, agents.domicile_city) tetap diisi nama wilayah untuk tampilan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE regions (
  code        varchar(13) PRIMARY KEY,
  name        varchar(100) NOT NULL,
  level       smallint NOT NULL CHECK (level BETWEEN 1 AND 4),
  parent_code varchar(13)
);
SQL);

        $this->loadRegions();

        // Batasan dipasang setelah data masuk (lebih cepat, dan COPY memuat induk provinsi sebagai string kosong).
        DB::unprepared(<<<'SQL'
UPDATE regions SET parent_code = NULL WHERE parent_code = '';
ALTER TABLE regions
  ADD CONSTRAINT regions_parent_fk FOREIGN KEY (parent_code) REFERENCES regions (code),
  ADD CONSTRAINT regions_parent_level_chk CHECK ((level = 1) = (parent_code IS NULL));
CREATE INDEX regions_parent_idx ON regions (parent_code, name);
CREATE INDEX regions_level_name_idx ON regions (level, lower(name));

ALTER TABLE tenants
  ADD COLUMN province_code varchar(13) REFERENCES regions (code),
  ADD COLUMN regency_code  varchar(13) REFERENCES regions (code),
  ADD COLUMN district_code varchar(13) REFERENCES regions (code),
  ADD COLUMN village_code  varchar(13) REFERENCES regions (code),
  ADD COLUMN postal_code   varchar(5);
CREATE INDEX tenants_region_idx ON tenants (province_code, regency_code);

ALTER TABLE agents
  ADD COLUMN province_code varchar(13) REFERENCES regions (code),
  ADD COLUMN regency_code  varchar(13) REFERENCES regions (code),
  ADD COLUMN district_code varchar(13) REFERENCES regions (code),
  ADD COLUMN village_code  varchar(13) REFERENCES regions (code);
CREATE INDEX agents_region_idx ON agents (province_code, regency_code);

-- Alamat jamaah (manifes PPIU): baris jalan dienkripsi seperti NIK; kode wilayah polos untuk laporan per daerah.
ALTER TABLE pilgrims
  ADD COLUMN address_enc   text,
  ADD COLUMN province_code varchar(13) REFERENCES regions (code),
  ADD COLUMN regency_code  varchar(13) REFERENCES regions (code),
  ADD COLUMN district_code varchar(13) REFERENCES regions (code),
  ADD COLUMN village_code  varchar(13) REFERENCES regions (code),
  ADD COLUMN postal_code   varchar(5);

-- Data lama (pilot Jambi): cocokkan teks kota/provinsi ke kode resmi bila namanya sama persis.
UPDATE tenants t SET province_code = p.code
  FROM regions p WHERE p.level = 1 AND lower(p.name) = lower(trim(t.province));
UPDATE tenants t SET regency_code = r.code, province_code = r.parent_code
  FROM regions r WHERE r.level = 2 AND lower(r.name) = lower(trim(t.city))
   AND (t.province_code IS NULL OR r.parent_code = t.province_code);
UPDATE agents a SET regency_code = r.code, province_code = r.parent_code
  FROM regions r WHERE r.level = 2 AND lower(r.name) = lower(trim(a.domicile_city))
   AND r.code IN (SELECT code FROM regions WHERE level = 2 AND lower(name) = lower(trim(a.domicile_city)) LIMIT 1);
SQL);
    }

    private function loadRegions(): void
    {
        $path = database_path('data/regions.csv.gz');
        $fh = gzopen($path, 'rb');
        if (! $fh) {
            throw new RuntimeException("Data wilayah tidak ditemukan: {$path}");
        }
        gzgets($fh); // header
        $rows = [];
        while (($line = gzgets($fh)) !== false) {
            $r = str_getcsv(rtrim($line, "\r\n"), ',', '"', '');
            if (count($r) !== 4) {
                continue;
            }
            [$code, $name, $level, $parent] = $r;
            // Format teks COPY dengan tab sebagai pemisah; parent_code provinsi kosong, diubah ke NULL setelah dimuat.
            $rows[] = $code."\t".str_replace(["\t", '\\'], [' ', ''], $name)."\t".$level."\t".$parent;
        }
        gzclose($fh);

        $pdo = DB::connection()->getPdo();
        $pdo->pgsqlCopyFromArray('regions', $rows, "\t", '\\N', 'code,name,level,parent_code');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE pilgrims DROP COLUMN IF EXISTS address_enc, DROP COLUMN IF EXISTS province_code, DROP COLUMN IF EXISTS regency_code,
  DROP COLUMN IF EXISTS district_code, DROP COLUMN IF EXISTS village_code, DROP COLUMN IF EXISTS postal_code;
ALTER TABLE agents DROP COLUMN IF EXISTS province_code, DROP COLUMN IF EXISTS regency_code,
  DROP COLUMN IF EXISTS district_code, DROP COLUMN IF EXISTS village_code;
ALTER TABLE tenants DROP COLUMN IF EXISTS province_code, DROP COLUMN IF EXISTS regency_code,
  DROP COLUMN IF EXISTS district_code, DROP COLUMN IF EXISTS village_code, DROP COLUMN IF EXISTS postal_code;
DROP TABLE IF EXISTS regions;
SQL);
    }
};
