<?php

namespace Tests\Unit;

use App\Services\SmartCleaning\GreedyService;
use App\Services\SmartCleaning\SawService;
use PHPUnit\Framework\TestCase;

/** Modul 6: SAW dan Greedy sebagai fungsi murni (tanpa basis data). */
class SawGreedyTest extends TestCase
{
    private const KRITERIA = [
        ['id' => 1, 'code' => 'C1', 'attribute_type' => 'cost'],
        ['id' => 2, 'code' => 'C2', 'attribute_type' => 'benefit'],
        ['id' => 3, 'code' => 'C3', 'attribute_type' => 'benefit'],
    ];

    /** Contoh data 6 Okt 2026: kamar A–D dengan nilai mentah C1 (menit), C2, C3 (menit). */
    private function contoh(): array
    {
        return [
            ['kunci' => 'B', 'nomor' => 'B', 'nilai' => [1 => 1440, 2 => 3, 3 => 165]],
            ['kunci' => 'D', 'nomor' => 'D', 'nilai' => [1 => 1440, 2 => 2, 3 => 30]],
            ['kunci' => 'A', 'nomor' => 'A', 'nilai' => [1 => 240, 2 => 3, 3 => 45]],
            ['kunci' => 'C', 'nomor' => 'C', 'nilai' => [1 => 1440, 2 => 3, 3 => 20]],
        ];
    }

    public function test_saw_sesuai_tabel_contoh(): void
    {
        // Bobot hasil hitung ulang matriks AHP (C1:C2 = 3, C1:C3 = 5, C2:C3 = 2) tanpa pembulatan 6 desimal
        $hasil = (new SawService)->hitung($this->contoh(), self::KRITERIA, [1 => 0.6479468599, 2 => 0.2298711755, 3 => 0.1221819646]);

        $this->assertSame(['A', 'B', 'C', 'D'], array_column($hasil, 'kunci'));
        $this->assertSame([1, 2, 3, 4], array_column($hasil, 'peringkat'));
        foreach (['A' => 0.91114039, 'B' => 0.46004428, 'C' => 0.35267225, 'D' => 0.28345350] as $kamar => $v) {
            $this->assertEqualsWithDelta($v, collect($hasil)->firstWhere('kunci', $kamar)['v'], 1e-8, "V kamar {$kamar}");
        }

        // Normalisasi: cost min/x, benefit x/max
        $a = $hasil[0];
        $this->assertSame([1 => 1.0, 2 => 1.0, 3 => round(45 / 165, 8)], $a['normal']);
        $this->assertEqualsWithDelta(round(0.6479468599 * 240 / 1440, 8), $hasil[1]['terbobot'][1], 1e-12);
    }

    public function test_saw_dengan_bobot_tersimpan_peringkatnya_sama(): void
    {
        $hasil = (new SawService)->hitung($this->contoh(), self::KRITERIA, [1 => 0.647947, 2 => 0.229871, 3 => 0.122182]);

        $this->assertSame(['A', 'B', 'C', 'D'], array_column($hasil, 'kunci'));
        $this->assertSame(0.91114036, $hasil[0]['v']);
    }

    public function test_saw_seri_c1_lebih_kecil_dulu_lalu_nomor_kamar(): void
    {
        $kriteria = [['id' => 1, 'code' => 'C1', 'attribute_type' => 'cost'], ['id' => 2, 'code' => 'C2', 'attribute_type' => 'benefit']];
        $hasil = (new SawService)->hitung([
            ['kunci' => 'Y', 'nomor' => '201', 'nilai' => [1 => 200, 2 => 2]],   // 0,5·0,5 + 0,5·1 = 0,75
            ['kunci' => 'X', 'nomor' => '110', 'nilai' => [1 => 100, 2 => 1]],   // 0,5·1 + 0,5·0,5 = 0,75
            ['kunci' => 'Z', 'nomor' => '105', 'nilai' => [1 => 100, 2 => 1]],   // sama dengan X, nomor lebih kecil
        ], $kriteria, [1 => 0.5, 2 => 0.5]);

        $this->assertSame([0.75, 0.75, 0.75], array_column($hasil, 'v'));
        $this->assertSame(['Z', 'X', 'Y'], array_column($hasil, 'kunci'));
    }

    /** Urutan staf (PembagianTugasService::urutkanStaf) di sini sama dengan id, kecuali diberikan. */
    private function staf(int $id, string $nama, float $beban = 0, int $jumlah = 0, float $batas = 4, bool $hadir = true, ?int $urutan = null): array
    {
        $urutan ??= $id;

        return compact('id', 'nama', 'urutan', 'beban', 'jumlah', 'batas', 'hadir');
    }

    private function tugas(string ...$kamar): array
    {
        return array_map(fn ($k) => ['kunci' => $k, 'poin' => str_starts_with($k, 'T') ? 0.5 : 1.0], $kamar);
    }

    public function test_greedy_ke_beban_terkecil_dan_beban_yang_ada_ikut_dihitung(): void
    {
        $hasil = (new GreedyService)->bagi($this->tugas('101', '102', '103'), [
            $this->staf(1, 'Anto', beban: 2, jumlah: 2),   // sudah membersihkan dua kamar pagi ini
            $this->staf(2, 'Rina'),
        ]);

        $this->assertSame(['101', '102'], array_column($hasil['penugasan'][2], 'kunci'));
        // Setelah dua kamar, beban Rina 2 = Anto 2, jumlah tugas sama (2), maka urutan staf: Anto (1)
        $this->assertSame(['103'], array_column($hasil['penugasan'][1], 'kunci'));
        $this->assertSame([1 => 3.0, 2 => 2.0], $hasil['beban']);
        $this->assertSame([], $hasil['belum']);
    }

    public function test_greedy_tidak_melewati_batas_sisanya_belum_terbagi(): void
    {
        $hasil = (new GreedyService)->bagi($this->tugas('101', 'T203', '102', '103'), [
            $this->staf(1, 'Anto', beban: 3, jumlah: 3),
            $this->staf(2, 'Rina', batas: 1),
        ]);

        // Rina (0) dapat 101 → 1 = batas; T203 (0,5) ke Anto → 3,5; 102 tidak muat di Anto (4,5) maupun Rina (2)
        $this->assertSame(['101'], array_column($hasil['penugasan'][2], 'kunci'));
        $this->assertSame(['T203'], array_column($hasil['penugasan'][1], 'kunci'));
        $this->assertSame(['102', '103'], array_column($hasil['belum'], 'kunci'));
        foreach ([1 => 4, 2 => 1] as $id => $batas) {
            $this->assertLessThanOrEqual($batas, $hasil['beban'][$id]);
        }
    }

    public function test_greedy_seri_deterministik_jumlah_tugas_lalu_urutan_staf(): void
    {
        // Urutan staf, bukan nama: Rina (HK murni) di depan Anto (multi-role) walau nama Anto lebih dulu
        $staf = [$this->staf(3, 'Rina', beban: 1, jumlah: 1, urutan: 0), $this->staf(1, 'Dewi', beban: 1, jumlah: 2, urutan: 2), $this->staf(2, 'Anto', beban: 1, jumlah: 1, urutan: 1)];
        $pertama = (new GreedyService)->bagi($this->tugas('101'), $staf);
        $kedua = (new GreedyService)->bagi($this->tugas('101'), array_reverse($staf));

        // Beban sama (1): jumlah tugas paling sedikit (Rina, Anto), lalu urutan staf: Rina
        $this->assertSame(['101'], array_column($pertama['penugasan'][3], 'kunci'));
        // Urutan masukan staf tidak memengaruhi hasil
        foreach (['penugasan', 'beban', 'jumlah', 'rasio'] as $kunci) {
            ksort($pertama[$kunci]);
            ksort($kedua[$kunci]);
        }
        $this->assertSame($pertama, $kedua);
    }

    public function test_greedy_staf_tidak_hadir_tidak_menerima_tugas(): void
    {
        $hasil = (new GreedyService)->bagi($this->tugas('101', '102'), [
            $this->staf(1, 'Anto', hadir: false),
            $this->staf(2, 'Rina'),
        ]);

        $this->assertArrayNotHasKey(1, $hasil['penugasan']);
        $this->assertSame(['101', '102'], array_column($hasil['penugasan'][2], 'kunci'));
    }
}
