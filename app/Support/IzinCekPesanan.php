<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Izin LIHAT SAJA dari Cek Pesanan, disimpan di session (bukan token): setelah kode pesanan dan nomor WhatsApp cocok,
 * tamu boleh membuka halaman pesanan itu selama 30 menit lewat route cek.lihat. Izin ini cukup untuk melihat dan
 * membayar, tetapi TIDAK untuk membatalkan atau mengubah tanggal (aksi itu tetap mensyaratkan token atau akun pemilik).
 *
 * Juga batas percobaan per kode pesanan (dari perangkat mana pun): 5 kali gagal, lalu kode itu terkunci 15 menit.
 */
final class IzinCekPesanan
{
    public const SESSION = 'cek_pesanan.izin';

    public const MENIT = 30;

    public const MAKS_GAGAL = 5;

    public const DETIK_KUNCI = 15 * 60;

    /** jenis: reservasi, makanan, atau laundry; kode: kode pesanan asli (RSV-0016, ORD/2026/10/0084). */
    public static function beri(string $jenis, string $kode): void
    {
        $sekarang = now()->timestamp;
        $izin = array_filter((array) session(self::SESSION, []), fn ($t) => (int) $t > $sekarang);
        $izin[$jenis.'|'.$kode] = now()->addMinutes(self::MENIT)->timestamp;
        session([self::SESSION => $izin]);
    }

    public static function berlaku(string $jenis, string $kode): bool
    {
        return (int) (((array) session(self::SESSION, []))[$jenis.'|'.$kode] ?? 0) > now()->timestamp;
    }

    /** "081234567890" → "0812****7890". */
    public static function samarkan(?string $hp): string
    {
        $hp = Phone::normalize((string) $hp);

        return strlen($hp) >= 8 ? substr($hp, 0, 4).'****'.substr($hp, -4) : '—';
    }

    /* ---------------- Batas percobaan per kode ---------------- */

    private static function kunciGagal(string $kode): string
    {
        return 'cek-kode:'.$kode;
    }

    private static function kunciTerkunci(string $kode): string
    {
        return 'cek-kode-terkunci:'.$kode;
    }

    /** Detik tersisa bila kode sedang terkunci; 0 bila boleh dicoba. */
    public static function sisaKunci(string $kode): int
    {
        return RateLimiter::tooManyAttempts(self::kunciTerkunci($kode), 1) ? RateLimiter::availableIn(self::kunciTerkunci($kode)) : 0;
    }

    /** Catat satu percobaan gagal; percobaan gagal ke-5 dalam 15 menit mengunci kode itu 15 menit. */
    public static function gagal(string $kode): void
    {
        RateLimiter::hit(self::kunciGagal($kode), self::DETIK_KUNCI);
        if (RateLimiter::attempts(self::kunciGagal($kode)) >= self::MAKS_GAGAL) {
            RateLimiter::hit(self::kunciTerkunci($kode), self::DETIK_KUNCI);
            RateLimiter::clear(self::kunciGagal($kode));
        }
    }

    public static function berhasil(string $kode): void
    {
        RateLimiter::clear(self::kunciGagal($kode));
    }
}
