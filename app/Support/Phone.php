<?php

namespace App\Support;

class Phone
{
    /**
     * Nomor HP Indonesia setelah dinormalisasi: 08 + kode operator yang aktif
     * (Telkomsel, Indosat, XL/Axis, Smartfren, Tri), total 10–13 digit.
     * Tanpa pembatas agar bisa dipakai juga oleh JavaScript di formulir.
     */
    public const MOBILE_PATTERN = '^08(1[1-9]|2[1-3]|3[1238]|5[1-35-9]|7[78]|8[1-9]|9[5-9])[0-9]{6,9}$';

    /** Samakan format nomor ke bentuk lokal 08xxxxxxxxxx, seperti yang tersimpan di basis data. */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /** Seperti normalize(), tetapi isian berisi huruf dibiarkan apa adanya supaya gagal validasi. */
    public static function normalizeInput(string $phone): string
    {
        return preg_match('/[^\d\s+\-().]/', $phone) ? trim($phone) : self::normalize($phone);
    }

    /** Nomor yang sudah dinormalisasi berbentuk nomor HP wajar, bukan asal ketik seperti 081111111111. */
    public static function isValidMobile(string $phone): bool
    {
        return preg_match('/'.self::MOBILE_PATTERN.'/', $phone) === 1
            && preg_match('/(\d)\1{6,}/', substr($phone, 4)) !== 1;
    }
}
