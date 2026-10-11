<?php

namespace App\Services\SmartCleaning;

/**
 * Workload Balancing dengan algoritma Greedy. Fungsi murni tanpa query.
 *
 * Tugas diproses menurut urutan prioritas. Setiap tugas jatuh ke staf hadir dengan beban saat ini paling kecil,
 * asalkan beban + poin tidak melebihi batasnya; bila seri, staf dengan tugas paling sedikit hari itu, lalu nama.
 * Tugas yang tidak muat di siapa pun masuk daftar "Belum terbagi".
 */
class GreedyService
{
    /** Toleransi pembulatan desimal saat membandingkan beban dengan batas. */
    private const EPS = 1e-9;

    /**
     * @param  list<array{kunci: int|string, poin: float}>  $tugas  urut peringkat prioritas; kunci lain ikut dikembalikan
     * @param  list<array{id: int, nama: string, batas: float, beban: float, jumlah: int, hadir?: bool}>  $staf
     *                                                                                                           beban dan jumlah = tugas yang sudah ada hari itu
     * @return array{penugasan: array<int, list<array>>, beban: array<int, float>, jumlah: array<int, int>, belum: list<array>}
     */
    public function bagi(array $tugas, array $staf): array
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
            usort($calon, fn ($a, $b) => [$beban[$a['id']], $jumlah[$a['id']], $a['nama'], $a['id']]
                <=> [$beban[$b['id']], $jumlah[$b['id']], $b['nama'], $b['id']]);
            $pilih = $calon[0]['id'];

            $penugasan[$pilih][] = $t;
            $beban[$pilih] = round($beban[$pilih] + $poin, 2);
            $jumlah[$pilih]++;
        }

        return ['penugasan' => $penugasan, 'beban' => $beban, 'jumlah' => $jumlah, 'belum' => $belum];
    }
}
