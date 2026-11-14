<?php

/*
| Pesan validasi bahasa Indonesia (UI/UX Design: pesan galat jelas, sopan, dan memberi tahu cara memperbaiki).
| Hanya aturan yang dipakai aplikasi; aturan lain jatuh ke bahasa Inggris (fallback).
*/
return [
    'accepted' => ':Attribute harus disetujui.',
    'after' => ':Attribute harus setelah :date.',
    'array' => ':Attribute tidak valid.',
    'before' => ':Attribute harus sebelum :date.',
    'between' => ['numeric' => ':Attribute harus antara :min dan :max.', 'string' => ':Attribute harus :min–:max karakter.'],
    'boolean' => ':Attribute harus ya atau tidak.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'date_format' => ':Attribute harus berformat tanggal yang benar.',
    'distinct' => ':Attribute tidak boleh dobel.',
    'email' => ':Attribute harus berupa alamat email yang valid.',
    'file' => ':Attribute harus berupa file.',
    'in' => ':Attribute yang dipilih tidak valid.',
    'integer' => ':Attribute harus berupa angka bulat.',
    'max' => ['numeric' => ':Attribute maksimal :max.', 'string' => ':Attribute maksimal :max karakter.', 'array' => ':Attribute maksimal :max item.', 'file' => 'Ukuran :attribute maksimal :max KB.'],
    'mimetypes' => ':Attribute harus berformat: :values.',
    'min' => ['numeric' => ':Attribute minimal :min.', 'string' => ':Attribute minimal :min karakter.', 'array' => ':Attribute minimal :min item.'],
    'numeric' => ':Attribute harus berupa angka.',
    'prohibited' => ':Attribute tidak boleh diisi.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':Attribute wajib diisi.',
    'required_if' => ':Attribute wajib diisi.',
    'required_with' => ':Attribute wajib diisi.',
    'required_without' => ':Attribute wajib diisi.',
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah terdaftar.',
    'uuid' => ':Attribute tidak valid.',
    'password' => ['letters' => 'Kata sandi harus mengandung huruf.', 'numbers' => 'Kata sandi harus mengandung angka.', 'mixed' => 'Kata sandi harus mengandung huruf besar dan kecil.', 'symbols' => 'Kata sandi harus mengandung simbol.'],

    'attributes' => [
        'name' => 'nama', 'email' => 'email', 'phone' => 'nomor HP', 'password' => 'kata sandi', 'identifier' => 'email atau nomor HP',
        'code' => 'kode', 'nik' => 'NIK', 'full_name' => 'nama lengkap', 'birth_place' => 'tempat lahir', 'birth_date' => 'tanggal lahir',
        'gender' => 'jenis kelamin', 'passport_no' => 'nomor paspor', 'departure_id' => 'keberangkatan', 'room_type' => 'tipe kamar',
        'participants' => 'peserta', 'reason' => 'alasan', 'note' => 'catatan', 'message' => 'pesan', 'file' => 'file',
        'participants.*.room_type' => 'tipe kamar', 'participants.*.pilgrim.full_name' => 'nama lengkap',
        'participants.*.pilgrim.nik' => 'NIK', 'participants.*.pilgrim.birth_place' => 'tempat lahir',
        'participants.*.pilgrim.birth_date' => 'tanggal lahir', 'participants.*.pilgrim.gender' => 'jenis kelamin',
        'booker.name' => 'nama pemesan', 'booker.phone' => 'nomor HP pemesan',
        'legal_name' => 'nama badan usaha', 'brand_name' => 'nama brand', 'ppiu_license_no' => 'nomor izin PPIU',
        'license_expires_on' => 'masa berlaku izin', 'address' => 'alamat', 'city' => 'kota', 'province' => 'provinsi',
        'bank_name' => 'nama bank', 'account_no' => 'nomor rekening', 'account_holder' => 'nama pemilik rekening',
        'duration_days' => 'durasi', 'airline' => 'maskapai', 'departure_date' => 'tanggal berangkat', 'return_date' => 'tanggal pulang',
        'departure_city' => 'kota keberangkatan', 'quota' => 'kuota', 'dp_min_per_pax_amount' => 'DP minimal',
        'final_payment_due_on' => 'batas pelunasan', 'domicile_city' => 'kota domisili', 'payout_bank_name' => 'bank',
        'payout_account_no' => 'nomor rekening', 'payout_account_holder' => 'nama pemilik rekening',
    ],
];
