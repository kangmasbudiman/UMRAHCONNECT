<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Pilgrim;
use App\Models\User;
use App\Support\Otp\OtpService;
use App\Support\Regions;

/** Data jamaah (FR-JMH-01): satu NIK per pengelola; NIK dan paspor terenkripsi, NIK diindeks HMAC. */
class PilgrimService
{
    public static function nikHash(string $nik): string
    {
        return Agent::nikHash($nik);
    }

    /** Buat data jamaah baru, atau perbarui yang sudah ada untuk NIK yang sama milik pengelola ini. */
    public static function upsert(User $manager, array $d): Pilgrim
    {
        $nik = preg_replace('/\D/', '', (string) ($d['nik'] ?? ''));
        if (strlen($nik) !== 16) {
            throw new ApiException(422, 'validation_failed', 'NIK terdiri dari 16 angka.', errors: ['nik' => ['NIK tidak valid.']]);
        }
        $attrs = self::attrs($d) + ['nik_enc' => $nik, 'nik_hash' => self::nikHash($nik)];
        $existing = Pilgrim::where('managed_by_user_id', $manager->id)->where('nik_hash', $attrs['nik_hash'])->first();
        if ($existing) {
            $existing->update($attrs);

            return $existing;
        }

        return Pilgrim::create($attrs + ['managed_by_user_id' => $manager->id]);
    }

    public static function attrs(array $d): array
    {
        $out = [];
        foreach (['full_name', 'birth_place', 'birth_date', 'gender', 'passport_expires_on', 'emergency_contact', 'mahram_pilgrim_id'] as $k) {
            if (array_key_exists($k, $d)) {
                $out[$k] = $d[$k];
            }
        }
        if (array_key_exists('passport_no', $d)) {
            $out['passport_no_enc'] = $d['passport_no'] ? strtoupper(trim($d['passport_no'])) : null;
        }
        if (array_key_exists('phone', $d)) {
            $out['phone'] = $d['phone'] ? OtpService::normalizePhone($d['phone']) : null;
        }
        if (isset($out['full_name'])) {
            $out['full_name'] = trim(preg_replace('/\s+/', ' ', $out['full_name']));
        }
        // Alamat (opsional): baris jalan terenkripsi + kode wilayah dari dropdown bertingkat.
        if (array_key_exists('address', $d)) {
            $out['address_enc'] = $d['address'] ? trim(preg_replace('/\s+/', ' ', $d['address'])) : null;
        }
        if (array_key_exists('postal_code', $d)) {
            $out['postal_code'] = $d['postal_code'] ?: null;
        }
        if (array_key_exists('region_code', $d)) {
            $out += $d['region_code']
                ? Regions::columns(Regions::resolveVillage($d['region_code']))
                : ['province_code' => null, 'regency_code' => null, 'district_code' => null, 'village_code' => null];
        }

        return $out;
    }

    /** Aturan validasi PilgrimInput; $prefix untuk peserta booking, mis. "participants.0.pilgrim." */
    public static function rules(string $prefix = '', bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            $prefix.'full_name' => [$r, 'string', 'min:3', 'max:150'],
            $prefix.'nik' => [$r, 'regex:/^[0-9]{16}$/'],
            $prefix.'birth_place' => [$r, 'string', 'max:100'],
            $prefix.'birth_date' => [$r, 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
            $prefix.'gender' => [$r, 'in:L,P'],
            $prefix.'passport_no' => ['nullable', 'regex:/^[A-Za-z0-9]{6,12}$/'],
            $prefix.'passport_expires_on' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'phone' => ['nullable', 'string', 'max:20'],
            $prefix.'emergency_contact' => ['nullable', 'array'],
            $prefix.'emergency_contact.name' => ['nullable', 'string', 'max:150'],
            $prefix.'emergency_contact.relation' => ['nullable', 'string', 'max:50'],
            $prefix.'emergency_contact.phone' => ['nullable', 'string', 'max:20'],
            $prefix.'mahram_pilgrim_id' => ['nullable', 'uuid'],
            $prefix.'address' => ['nullable', 'string', 'max:300'],
            $prefix.'region_code' => ['nullable', 'string', 'max:13'],
            $prefix.'postal_code' => ['nullable', 'regex:/^[0-9]{5}$/'],
        ];
    }

    public static function messages(string $prefix = ''): array
    {
        return [
            $prefix.'nik.regex' => 'NIK terdiri dari 16 angka.',
            $prefix.'birth_date.before' => 'Tanggal lahir tidak valid.',
            $prefix.'passport_no.regex' => 'Nomor paspor 6–12 huruf/angka.',
            $prefix.'postal_code.regex' => 'Kode pos terdiri dari 5 angka.',
        ];
    }
}
