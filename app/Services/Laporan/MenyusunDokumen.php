<?php

namespace App\Services\Laporan;

/**
 * Bentuk dokumen laporan yang sama untuk halaman web, PDF, dan CSV:
 *   judul, ringkasan (kartu dengan pembanding), grafik (seri bertumpuk), tabel (kolom berformat), catatan.
 * Format nilai: rp, persen, angka, desimal, durasi (detik), teks.
 */
trait MenyusunDokumen
{
    /** Kartu ringkasan; perubahan dibanding periode sebelumnya dihitung di tampilan. */
    protected function kartu(string $label, float|int|null $nilai, float|int|null $sebelum, string $format, ?string $ket = null): array
    {
        return ['label' => $label, 'nilai' => $nilai, 'sebelum' => $sebelum, 'format' => $format, 'ket' => $ket];
    }

    protected function rp(float|int|null $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    /** Rata-rata atau pembagian yang aman dari nol. */
    protected static function bagi(float|int $a, float|int $b): float
    {
        return $b > 0 ? $a / $b : 0.0;
    }
}
