<?php

namespace App\Services\SmartCleaning;

use Illuminate\Support\Facades\DB;

/**
 * Bobot kriteria Smart Cleaning dengan AHP. Bobot sudah tetap dari penelitian terdahulu (sesi AHP aktif);
 * Manager tidak mengisi perbandingan berpasangan. Kelas ini hanya membaca bobot dan menghitung ulang
 * dari matriks perbandingan untuk ditampilkan dan diverifikasi.
 */
class AhpService
{
    /** Random Index Saaty menurut ukuran matriks. */
    public const RI = [1 => 0.0, 2 => 0.0, 3 => 0.58, 4 => 0.90, 5 => 1.12, 6 => 1.24, 7 => 1.32, 8 => 1.41, 9 => 1.45, 10 => 1.49];

    public function sesiAktif(): ?object
    {
        return DB::table('ahp_sessions')->where('is_active', true)->orderByDesc('calculated_at')->orderByDesc('id')->first();
    }

    /** @return array<int, float> criteria_id => bobot */
    public function bobot(int $sesiId): array
    {
        return DB::table('ahp_criteria_weights')->where('ahp_session_id', $sesiId)->pluck('weight', 'criteria_id')
            ->map(fn ($w) => (float) $w)->all();
    }

    /**
     * Matriks perbandingan berpasangan sesi itu, urut kode kriteria aktif. Segitiga atas dari ahp_comparisons,
     * segitiga bawah kebalikannya (1/nilai), diagonal 1.
     *
     * @return array{kriteria: list<int>, matriks: list<list<float>>}
     */
    public function matriks(int $sesiId): array
    {
        $kriteria = DB::table('cleaning_criteria')->where('is_active', true)->orderBy('code')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $indeks = array_flip($kriteria);
        $n = count($kriteria);
        $a = array_fill(0, $n, array_fill(0, $n, 1.0));

        foreach (DB::table('ahp_comparisons')->where('ahp_session_id', $sesiId)->get() as $c) {
            $i = $indeks[(int) $c->criteria_row_id] ?? null;
            $j = $indeks[(int) $c->criteria_column_id] ?? null;
            if ($i === null || $j === null || $i === $j || (float) $c->comparison_value <= 0) {
                continue;
            }
            $a[$i][$j] = (float) $c->comparison_value;
            $a[$j][$i] = 1 / (float) $c->comparison_value;
        }

        return ['kriteria' => $kriteria, 'matriks' => $a];
    }

    /**
     * Hitung ulang bobot dan konsistensi dari matriks perbandingan:
     * bobot = rata-rata baris matriks yang dinormalisasi per kolom; λmax = rata-rata (A·w)ᵢ / wᵢ;
     * CI = (λmax − n) / (n − 1); CR = CI / RI.
     *
     * @return array{bobot: array<int, float>, lambda_max: float, ci: float, ri: float, cr: float, matriks: list<list<float>>, kriteria: list<int>}
     */
    public function hitungDariPerbandingan(int $sesiId): array
    {
        ['kriteria' => $kriteria, 'matriks' => $a] = $this->matriks($sesiId);
        $n = count($kriteria);
        if ($n === 0) {
            return ['bobot' => [], 'lambda_max' => 0.0, 'ci' => 0.0, 'ri' => 0.0, 'cr' => 0.0, 'matriks' => [], 'kriteria' => []];
        }

        $jumlahKolom = [];
        for ($j = 0; $j < $n; $j++) {
            $jumlahKolom[$j] = array_sum(array_column($a, $j));
        }
        $w = [];
        for ($i = 0; $i < $n; $i++) {
            $s = 0.0;
            for ($j = 0; $j < $n; $j++) {
                $s += $a[$i][$j] / $jumlahKolom[$j];
            }
            $w[$i] = $s / $n;
        }

        $lambda = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $aw = 0.0;
            for ($j = 0; $j < $n; $j++) {
                $aw += $a[$i][$j] * $w[$j];
            }
            $lambda += $aw / $w[$i];
        }
        $lambda /= $n;

        $ci = $n > 1 ? ($lambda - $n) / ($n - 1) : 0.0;
        $ri = self::RI[$n] ?? 1.49;

        return [
            'bobot' => array_combine($kriteria, $w),
            'lambda_max' => $lambda,
            'ci' => $ci,
            'ri' => $ri,
            'cr' => $ri > 0 ? $ci / $ri : 0.0,
            'matriks' => $a,
            'kriteria' => $kriteria,
        ];
    }
}
