<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Nomor dokumen berurutan. Panggil di dalam transaksi basis data agar tidak bentrok. */
class Nomor
{
    /** Contoh: berikut('service_orders', 'order_code', 'ORD/2026/09/') menghasilkan ORD/2026/09/0132. */
    public static function berikut(string $tabel, string $kolom, string $awalan, int $panjang = 4): string
    {
        $terakhir = DB::table($tabel)->where($kolom, 'like', $awalan.'%')->lockForUpdate()->pluck($kolom)
            ->map(fn ($v) => (int) substr($v, strlen($awalan)))->max() ?? 0;

        return $awalan.str_pad((string) ($terakhir + 1), $panjang, '0', STR_PAD_LEFT);
    }

    public static function pesanan(): string
    {
        return self::berikut('service_orders', 'order_code', 'ORD/'.now()->format('Y/m').'/');
    }

    /** RSV-0016 */
    public static function reservasi(): string
    {
        return self::berikut('reservations', 'reservation_code', 'RSV-');
    }

    public static function nota(): string
    {
        return self::berikut('invoices', 'invoice_number', 'INV/'.now()->format('Y/m').'/');
    }

    /** MNT-0006: laporan kerusakan dan catatan perawatan rutin memakai urutan yang sama. */
    public static function maintenance(): string
    {
        return self::berikut('maintenance_requests', 'request_code', 'MNT-');
    }

    public static function pembayaran(): string
    {
        return self::berikut('payments', 'payment_code', 'PAY-');
    }
}
