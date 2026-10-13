<?php

namespace App\Services\SmartCleaning;

/**
 * Workload Balancing dengan algoritma Greedy. Fungsi murni tanpa query.
 *
 * Tugas diproses menurut urutan prioritas dan hanya jatuh ke staf hadir yang tidak akan melewati batasnya
 * (beban + poin <= batas). Pemilihannya:
 *   - aturan lama (bawaan): beban saat ini paling kecil; bila seri, tugas paling sedikit hari itu, lalu urutan staf;
 *   - aturan rasio ($rasio = true): (beban + poin) / batas paling kecil; bila seri, urutan staf. Staf dengan batas lebih
 *     kecil mendapat kamar lebih sedikit secara proporsional.
 * Urutan staf (kunci "urutan") berasal dari PembagianTugasService::urutkanStaf(): HK murni dulu, lalu multi-role, urut nama.
 * Tugas yang tidak muat di siapa pun masuk daftar "Belum terbagi". Rasio akhir (beban / batas) tiap staf ikut dikembalikan.
 */
class GreedyService
{
    /** Toleransi pembulatan desimal saat membandingkan beban dengan batas. */
    private const EPS = 1e-9;

    /**
     * @param  list<array{kunci: int|string, poin: float}>  $tugas  urut peringkat prioritas; kunci lain ikut dikembalikan
     * @param  list<array{id: int, nama: string, urutan: int, batas: float, beban: float, jumlah: int, hadir?: bool}>  $staf
     *                                                                                                                        beban dan jumlah = tugas yang sudah ada hari itu
     * @return array{penugasan: array<int, list<array>>, beban: array<int, float>, jumlah: array<int, int>, rasio: array<int, float>, belum: list<array>}
     */
    public function bagi(array $tugas, array $staf, bool $rasio = false): array
    {
        $staf = array_values(array_filter($staf, fn ($s) => $s['hadir'] ?? true));
        $beban = $jumlah = $penugasan = [];
        foreach ($staf as $s) {
            $beban[$s['id']] = round((float) $s['beban'], 2);
            $jumlah[$s['id']] = (int) $s['jumlah'];
            $penugasan[$s['id']] = [];
        }

        $belum = [];
        foreach ($tugas as $t) {
            $poin = (float) $t['poin'];
            $calon = array_filter($staf, fn ($s) => (float) $s['batas'] + self::EPS >= $beban[$s['id']] + $poin);
            if ($calon === []) {
                $belum[] = $t;

                continue;
            }
            $kunci = $rasio
                ? fn ($s) => [round(($beban[$s['id']] + $poin) / (float) $s['batas'], 9), $s['urutan'], $s['id']]
                : fn ($s) => [$beban[$s['id']], $jumlah[$s['id']], $s['urutan'], $s['id']];
            usort($calon, fn ($a, $b) => $kunci($a) <=> $kunci($b));
            $pilih = $calon[0]['id'];

            $penugasan[$pilih][] = $t;
            $beban[$pilih] = round($beban[$pilih] + $poin, 2);
            $jumlah[$pilih]++;
        }

        $akhir = [];
        foreach ($staf as $s) {
            $akhir[$s['id']] = (float) $s['batas'] > 0 ? round($beban[$s['id']] / (float) $s['batas'], 4) : 0.0;
        }

        return ['penugasan' => $penugasan, 'beban' => $beban, 'jumlah' => $jumlah, 'rasio' => $akhir, 'belum' => $belum];
    }
}
