# Data wilayah

`regions.csv.gz` — kode & nama wilayah administratif Indonesia (38 provinsi, 514 kab/kota, 7.285 kecamatan, 83.762 kelurahan/desa)
sesuai **Kepmendagri No 300.2.2-2138 Tahun 2025**.

Sumber: <https://github.com/cahyadsn/wilayah> (`db/wilayah.sql`), © 2017-2025 Cahya DSN, lisensi MIT.
Dikonversi ke CSV `code,name,level,parent_code` (level 1 provinsi … 4 kelurahan/desa) dan dimuat oleh migrasi
`2026_11_15_100004_create_regions_and_address_codes`.

Pembaruan data: unduh ulang `wilayah.sql`, konversi ke format yang sama, lalu buat migrasi baru yang
menambah/mengubah baris (kode lama jangan dihapus karena dirujuk alamat PPIU, agen, dan jamaah).
