<?php

namespace App\Services\SmartCleaning;

/**
 * Simple Additive Weighting (SAW) untuk prioritas pembersihan. Fungsi murni tanpa query, supaya hitungannya
 * bisa dicocokkan dengan hitungan manual.
 *
 * Normalisasi benefit: r = x / max(x); cost: r = min(x) / x. Nilai terbobot = w · r, preferensi V = Σ w · r.
 * Peringkat menurut V menurun; bila V sama, nilai kriteria cost pertama (C1) yang lebih kecil dulu, lalu nomor kamar.
 */
class SawService
{
    /** Presisi kolom normalized_value, weighted_value, dan preference_score. */
    public const DESIMAL = 8;

    /**
     * @param  list<array{kunci: int|string, nomor: string, nilai: array<int, float>}>  $alternatif  nilai mentah per id kriteria
     * @param  list<array{id: int, code: string, attribute_type: string}>  $kriteria  urut kode
     * @param  array<int, float>  $bobot  id kriteria => bobot
     * @return list<array{kunci: int|string, nomor: string, nilai: array<int, float>, normal: array<int, float>, terbobot: array<int, float>, v: float, peringkat: int}>
     */
    public function hitung(array $alternatif, array $kriteria, array $bobot): array
    {
        if ($alternatif === []) {
            return [];
        }

        $batas = [];
        foreach ($kriteria as $k) {
            $kolom = array_map(fn ($a) => (float) $a['nilai'][$k['id']], $alternatif);
            $batas[$k['id']] = $k['attribute_type'] === 'cost' ? min($kolom) : max($kolom);
        }

        $hasil = [];
        foreach ($alternatif as $a) {
            $normal = $terbobot = [];
            $v = 0.0;
            foreach ($kriteria as $k) {
                $x = (float) $a['nilai'][$k['id']];
                $r = match (true) {
                    $k['attribute_type'] === 'cost' => $x > 0 ? $batas[$k['id']] / $x : 0.0,
                    default => $batas[$k['id']] > 0 ? $x / $batas[$k['id']] : 0.0,
                };
                $w = (float) ($bobot[$k['id']] ?? 0);
                $normal[$k['id']] = round($r, self::DESIMAL);
                $terbobot[$k['id']] = round($w * $r, self::DESIMAL);
                $v += $w * $r;
            }
            $hasil[] = $a + ['normal' => $normal, 'terbobot' => $terbobot, 'v' => round($v, self::DESIMAL)];
        }

        $cost = collect($kriteria)->firstWhere('attribute_type', 'cost')['id'] ?? null;
        usort($hasil, fn ($p, $q) => [$q['v'], $cost !== null ? $p['nilai'][$cost] : 0, $p['nomor']]
            <=> [$p['v'], $cost !== null ? $q['nilai'][$cost] : 0, $q['nomor']]);

        foreach ($hasil as $i => &$h) {
            $h['peringkat'] = $i + 1;
        }
        unset($h);

        return $hasil;
    }
}
