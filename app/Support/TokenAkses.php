<?php

namespace App\Support;

/**
 * Token akses 8 karakter untuk tautan cek pesanan, misalnya LK7Q2M9X (ditampilkan LK7Q-2M9X).
 * Tanpa huruf dan angka yang mudah tertukar (0/O dan 1/I) supaya mudah diketik dari WhatsApp.
 */
final class TokenAkses
{
    private const HURUF = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function baru(): string
    {
        $token = '';
        for ($i = 0; $i < 8; $i++) {
            $token .= self::HURUF[random_int(0, strlen(self::HURUF) - 1)];
        }

        return $token;
    }

    /** LK7Q2M9X menjadi LK7Q-2M9X. */
    public static function tampil(?string $token): string
    {
        return $token === null ? '' : (strlen($token) === 8 ? substr($token, 0, 4).'-'.substr($token, 4) : $token);
    }

    /** Isian tamu ("lk7q-2m9x", "LK7Q 2M9X") menjadi bentuk simpanan. */
    public static function rapikan(?string $isian): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $isian));
    }
}
