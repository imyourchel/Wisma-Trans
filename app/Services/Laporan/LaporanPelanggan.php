<?php

namespace App\Services\Laporan;

use App\Services\PiutangService;
use App\Support\SegmenPelanggan;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pelanggan: tamu baru vs berulang (berulang = pernah menginap sebelum periode), B2B vs B2C (SegmenPelanggan),
 * peringkat pelanggan dan instansi (PeringkatPelanggan), asal pemesanan, dan status piutang instansi.
 */
class LaporanPelanggan
{
    use MenyusunDokumen;

    public function __construct(private LaporanPendapatan $pendapatan, private LaporanHunian $hunian, private PeringkatPelanggan $peringkat, private PiutangService $piutang) {}

    /** @return array{tamu: int, baru: int, berulang: int} tamu yang menginap dalam periode */
    public function tamu(Periode $p, ?Collection $malam = null): array
    {
        $ids = ($malam ?? $this->hunian->malamTerisi($p))->pluck('pelanggan')->unique()->values();
        $berulang = self::jumlahBerulang($ids, $p->mulai);

        return ['tamu' => $ids->count(), 'baru' => $ids->count() - $berulang, 'berulang' => $berulang];
    }

    /**
     * Definisi tunggal tamu berulang (juga dipakai Riwayat Tamu): pelanggan yang punya kamar menginap
     * (checked_in, checked_out, moved) dengan tanggal masuk sebelum $mulai.
     */
    public static function jumlahBerulang(Collection $pelangganIds, CarbonInterface $mulai): int
    {
        return $pelangganIds->isEmpty() ? 0 : DB::table('reservations as r')->join('reservation_rooms as rr', 'rr.reservation_id', '=', 'r.id')
            ->whereIn('r.customer_id', $pelangganIds->all())->whereIn('rr.status', ['checked_in', 'checked_out', 'moved'])
            ->where('rr.check_in_date', '<', $mulai->toDateString())->distinct()->count('r.customer_id');
    }

    /** @return array{b2b: array{malam: int, pendapatan: float}, b2c: array{malam: int, pendapatan: float}} */
    public function segmen(Collection $potongan, Collection $malam): array
    {
        return collect(['b2b' => true, 'b2c' => false])->map(fn ($b2b) => [
            'malam' => $malam->where('b2b', $b2b)->count(),
            'pendapatan' => (float) $potongan->where('b2b', $b2b)->sum('nilai'),
        ])->all();
    }

    public function dokumen(Periode $p): array
    {
        $potongan = $this->pendapatan->potongan($p);
        $malam = $this->hunian->malamTerisi($p);
        $lalu = $p->sebelumnya();
        $potonganLalu = $this->pendapatan->potongan($lalu);
        $malamLalu = $this->hunian->malamTerisi($lalu);

        $tamu = $this->tamu($p, $malam);
        $tamuLalu = $this->tamu($lalu, $malamLalu);
        $seg = $this->segmen($potongan, $malam);
        $segLalu = $this->segmen($potonganLalu, $malamLalu);
        $asal = collect(SegmenPelanggan::ASAL)->map(fn ($label, $k) => [$label, $malam->where('asal', $k)->pluck('reservasi')->unique()->count(), $malam->where('asal', $k)->count()]);
        $terbuka = $this->piutang->tagihanTerbuka();
        $piutang = $this->piutang->ringkasan();

        return [
            'judul' => 'Laporan Pelanggan',
            'ringkasan' => [
                $this->kartu('Tamu menginap', $tamu['tamu'], $tamuLalu['tamu'], 'angka'),
                $this->kartu('Tamu baru', $tamu['baru'], $tamuLalu['baru'], 'angka'),
                $this->kartu('Tamu berulang', $tamu['berulang'], $tamuLalu['berulang'], 'angka', 'Pernah menginap sebelum periode ini'),
                $this->kartu('Pendapatan B2B (instansi)', $seg['b2b']['pendapatan'], $segLalu['b2b']['pendapatan'], 'rp', $seg['b2b']['malam'].' malam kamar'),
                $this->kartu('Pendapatan B2C (perorangan)', $seg['b2c']['pendapatan'], $segLalu['b2c']['pendapatan'], 'rp', $seg['b2c']['malam'].' malam kamar'),
                $this->kartu('Piutang instansi', $piutang['total'], null, 'rp', 'Lewat jatuh tempo '.$this->rp($piutang['lewat'])),
            ],
            'grafik' => [[
                'judul' => 'Malam kamar B2B dan B2C',
                'format' => 'angka',
                'label' => ['B2B (instansi)', 'B2C (perorangan)'],
                'seri' => [['nama' => 'Malam kamar', 'warna' => '#4E3423', 'nilai' => [$seg['b2b']['malam'], $seg['b2c']['malam']]]],
            ]],
            'tabel' => [
                [
                    'judul' => '10 pelanggan teratas',
                    'kolom' => [['Pelanggan', 'teks'], ['Pendapatan', 'rp'], ['Kunjungan', 'angka'], ['Malam', 'angka']],
                    'baris' => $this->peringkat->pelanggan($p, 10, $potongan, $malam)->map(fn ($x) => array_values(Arr::except($x, 'id')))->all(),
                ],
                [
                    'judul' => '10 instansi teratas',
                    'kolom' => [['Instansi', 'teks'], ['Pendapatan', 'rp'], ['Kunjungan', 'angka'], ['Malam', 'angka']],
                    'baris' => $this->peringkat->instansi($p, 10, $potongan, $malam)->map(fn ($x) => array_values(Arr::except($x, 'id')))->all(),
                ],
                [
                    'judul' => 'Asal pemesanan',
                    'kolom' => [['Asal', 'teks'], ['Reservasi', 'angka'], ['Malam kamar', 'angka']],
                    'baris' => $asal->values()->all(),
                ],
                [
                    'judul' => 'Umur piutang instansi (saat ini)',
                    'kolom' => [['Umur', 'teks'], ['Tagihan', 'angka'], ['Sisa', 'rp']],
                    'baris' => collect(PiutangService::UMUR)->map(fn ($label, $k) => [$label, $terbuka->where('kelompok', $k)->count(), (float) $terbuka->where('kelompok', $k)->sum('sisa')])->values()->all(),
                    'total' => ['Total', $terbuka->count(), $piutang['total']],
                ],
            ],
            'catatan' => [
                'Pendapatan pelanggan memakai pendapatan diakui (baris nota dalam periode), sama dengan halaman Pendapatan.',
                'B2B = nota atau reservasi instansi; selain itu B2C. Piutang instansi menunjukkan keadaan saat ini, bukan periode.',
            ],
        ];
    }
}
