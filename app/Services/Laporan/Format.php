<?php

namespace App\Services\Laporan;

use App\Livewire\Staf\Kamar\RiwayatStatus;

/** Format nilai laporan untuk tampilan (web, PDF) dan CSV. */
final class Format
{
    public static function tampil(mixed $nilai, string $format): string
    {
        if ($nilai === null) {
            return '—';
        }

        return match ($format) {
            'rp' => ($nilai < 0 ? '−' : '').'Rp'.number_format(abs((float) $nilai), 0, ',', '.'),
            'persen' => number_format((float) $nilai, 1, ',', '.').'%',
            'angka' => number_format((float) $nilai, 0, ',', '.'),
            'desimal' => rtrim(rtrim(number_format((float) $nilai, 1, ',', '.'), '0'), ','),
            'durasi' => (float) $nilai > 0 ? RiwayatStatus::lama((int) round((float) $nilai)) : '—',
            default => (string) $nilai,
        };
    }

    /**
     * Nilai CSV untuk Excel berbahasa Indonesia: rupiah dan angka sebagai bilangan bulat tanpa pemisah ribuan,
     * persen dan desimal dengan koma desimal, durasi dalam menit.
     */
    public static function csv(mixed $nilai, string $format): string
    {
        if ($nilai === null) {
            return '';
        }

        return match ($format) {
            'rp', 'angka' => (string) (int) round((float) $nilai),
            'persen', 'desimal' => str_replace('.', ',', (string) round((float) $nilai, 1)),
            'durasi' => (string) (int) round((float) $nilai / 60),
            default => (string) $nilai,
        };
    }

    /** Perubahan terhadap periode sebelumnya: arah dan persen (null bila pembanding nol atau tidak ada). */
    public static function perubahan(float|int|null $nilai, float|int|null $sebelum): array
    {
        if ($nilai === null || $sebelum === null) {
            return ['arah' => null, 'persen' => null];
        }
        $arah = $nilai <=> $sebelum;

        return [
            'arah' => $arah > 0 ? 'naik' : ($arah < 0 ? 'turun' : 'tetap'),
            'persen' => (float) $sebelum != 0.0 ? ($nilai - $sebelum) / abs($sebelum) * 100 : null,
        ];
    }

    /** "▲ 12,5%", "▼ 3,0%", "= 0%", atau "▲ baru" bila pembanding nol. */
    public static function teksPerubahan(float|int|null $nilai, float|int|null $sebelum): ?string
    {
        $u = self::perubahan($nilai, $sebelum);

        return match ($u['arah']) {
            null => null,
            'tetap' => '= 0%',
            default => ($u['arah'] === 'naik' ? '▲ ' : '▼ ').($u['persen'] === null ? 'dari 0' : number_format(abs($u['persen']), 1, ',', '.').'%'),
        };
    }
}
