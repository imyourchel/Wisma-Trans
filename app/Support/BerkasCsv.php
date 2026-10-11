<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV untuk Excel berbahasa Indonesia (Laporan dan Riwayat Tamu): BOM UTF-8 supaya karakter terbaca benar,
 * pemisah titik koma, dan angka ditulis tanpa pemisah ribuan oleh pemanggil (lihat Format::csv).
 */
final class BerkasCsv
{
    /** @param  callable(callable(array): void): void  $isi  menerima penulis baris */
    public static function unduh(string $nama, callable $isi): StreamedResponse
    {
        return response()->streamDownload(function () use ($isi) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            $isi(fn (array $baris) => fputcsv($f, $baris, ';', '"', ''));
            fclose($f);
        }, $nama, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
