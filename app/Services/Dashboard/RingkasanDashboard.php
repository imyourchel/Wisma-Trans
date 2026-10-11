<?php

namespace App\Services\Dashboard;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Keuangan\PiutangService;
use App\Services\Laporan\Format;
use App\Services\Laporan\LaporanHunian;
use App\Services\Laporan\LaporanPendapatan;
use App\Services\Laporan\Periode;
use App\Services\Reservasi\FolioService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Kartu Dashboard Manager dan Front Office. Tidak ada perhitungan baru: okupansi, ADR, dan pendapatan diambil dari
 * LaporanHunian dan LaporanPendapatan (preset Periode yang sama dengan halaman Laporan), piutang dari PiutangService,
 * kedatangan dari daftar Check-in (Reservation::menungguCheckIn), dan batas terlambat dari FolioService::batasKeluar,
 * sama dengan halaman Check-out.
 *
 * Kartu: label, nilai (teks siap tampil), ket, url, awas, dan opsional sebelum/format untuk ▲/▼ (Format::perubahan).
 */
class RingkasanDashboard
{
    /** Bagian "Bulan ini" di-cache, karena Dashboard memuat ulang tiap 60 detik. */
    public const MENIT_CACHE_BULAN = 5;

    public function __construct(private LaporanHunian $hunian, private LaporanPendapatan $pendapatan) {}

    public static function kunciBulan(?CarbonInterface $hari = null): string
    {
        return 'dashboard-bulan:'.($hari ?? today())->toDateString();
    }

    /** Dipanggil saat check-out dan saat pembayaran dicatat, supaya pendapatan bulan ini langsung berubah. */
    public static function lupakanBulan(): void
    {
        Cache::forget(self::kunciBulan());
    }

    /* ---------- Arus tamu hari ini (bersama untuk Manager dan FO) ---------- */

    /**
     * @return array{menunggu: Collection<int, Reservation>, dariKemarin: int, akanDatang: int,
     *     keberangkatan: Collection<int, ReservationRoom>, terlambat: Collection<int, ReservationRoom>, sudahMasuk: int, sudahKeluar: int}
     */
    public function arusHariIni(): array
    {
        $hari = today()->toDateString();
        $menunggu = Reservation::menungguCheckIn()->with(['rooms' => fn ($q) => $q->where('status', 'booked')])->get(['id', 'status']);
        $malamIni = fn (ReservationRoom $b) => $b->check_in_date->toDateString() <= $hari && $b->check_out_date->toDateString() > $hari;

        $menginap = ReservationRoom::where('status', 'checked_in')->whereDate('check_out_date', '<=', $hari)->get(['id', 'reservation_id', 'check_out_date']);
        $lewat = fn (ReservationRoom $b) => now()->gt(FolioService::batasKeluar($b));

        return [
            'menunggu' => $menunggu,
            // Dijadwalkan masuk hari sebelumnya dan belum datang: calon no_show yang perlu dihubungi
            'dariKemarin' => $menunggu->filter(fn ($r) => $r->rooms->filter($malamIni)->min(fn ($b) => $b->check_in_date->toDateString()) < $hari)->count(),
            'akanDatang' => $menunggu->sum(fn ($r) => $r->rooms->filter($malamIni)->count()),
            'keberangkatan' => $menginap->reject($lewat)->filter(fn ($b) => $b->check_out_date->toDateString() === $hari)->values(),
            'terlambat' => $menginap->filter($lewat)->values(),
            'sudahMasuk' => ReservationRoom::whereNull('moved_from_id')->whereDate('actual_check_in_at', $hari)->distinct()->count('reservation_id'),
            'sudahKeluar' => ReservationRoom::where('status', 'checked_out')->whereDate('actual_check_out_at', $hari)->count(),
        ];
    }

    /** "3 kamar (2 reservasi)": kartu menghitung kamar, halaman Check-out mengelompokkan per reservasi. */
    public static function kamarReservasi(Collection $baris): string
    {
        return $baris->count().' kamar ('.$baris->pluck('reservation_id')->unique()->count().' reservasi)';
    }

    /* ---------- Manager ---------- */

    /** @return array{hariIni: list<array>, bulanIni: list<array>} */
    public function manager(): array
    {
        return [
            'hariIni' => $this->hariIni(),
            'bulanIni' => Cache::remember(self::kunciBulan(), now()->addMinutes(self::MENIT_CACHE_BULAN), fn () => $this->bulanIni()),
        ];
    }

    /** @return list<array> */
    public function hariIni(): array
    {
        $p = Periode::buat('hari-ini');
        $h = $this->hunian->ringkas($p);
        $a = $this->arusHariIni();
        $proyeksi = $h['tersedia'] > 0 ? ($h['terisi'] + $a['akanDatang']) / $h['tersedia'] * 100 : 0.0;
        $hunian = route('staf.laporan.hunian', $p->query());
        $operasional = route('staf.laporan.operasional', $p->query());
        $menginap = ReservationRoom::where('status', 'checked_in');

        return [
            ['label' => 'Tamu menginap', 'nilai' => Reservation::where('status', 'checked_in')->count().' reservasi',
                'ket' => (clone $menginap)->count().' kamar', 'url' => $hunian, 'awas' => false],
            ['label' => 'Kamar terisi malam ini', 'nilai' => $h['terisi'].' / '.$h['tersedia'],
                'ket' => $h['terisi'].' terisi · +'.$a['akanDatang'].' akan datang', 'url' => $hunian, 'awas' => false, 'kunci' => 'terisi'],
            ['label' => 'Okupansi malam ini', 'nilai' => self::persen($h['okupansi']),
                'ket' => self::persen($h['okupansi']).' · '.self::persen($proyeksi).' bila semua datang', 'url' => $hunian, 'awas' => false, 'kunci' => 'okupansi'],
            ['label' => 'Kedatangan hari ini', 'nilai' => $a['sudahMasuk'].' sudah check-in',
                'ket' => $a['menunggu']->count().' reservasi belum check-in', 'url' => $operasional, 'awas' => false],
            ['label' => 'Keberangkatan hari ini', 'nilai' => $a['sudahKeluar'].' kamar sudah keluar',
                'ket' => $a['keberangkatan']->count().' kamar lagi · '.$a['terlambat']->count().' terlambat', 'url' => $operasional, 'awas' => false],
        ];
    }

    /** Dari tanggal 1 sampai hari ini, dibanding tanggal yang sama bulan lalu (Periode::sebelumnya). @return list<array> */
    public function bulanIni(): array
    {
        $p = Periode::buat('bulan-ini');
        $lalu = $p->sebelumnya();
        $uang = $this->pendapatan->diakui($p)['kategori'];
        $uangLalu = $this->pendapatan->diakui($lalu)['kategori'];
        $h = $this->hunian->ringkas($p);
        $hLalu = $this->hunian->ringkas($lalu);
        $kePendapatan = route('staf.laporan.pendapatan', $p->query());
        $keHunian = route('staf.laporan.hunian', $p->query());
        $kartu = fn (string $label, float $nilai, float $sebelum, string $format, string $url) => [
            'label' => $label, 'nilai' => $nilai, 'sebelum' => $sebelum, 'format' => $format, 'url' => $url, 'awas' => false];

        return [
            $kartu('Pendapatan kamar', $uang['kamar'], $uangLalu['kamar'], 'rp', $kePendapatan),
            $kartu('Pendapatan F&B', $uang['fnb'], $uangLalu['fnb'], 'rp', $kePendapatan),
            $kartu('Pendapatan laundry', $uang['laundry'], $uangLalu['laundry'], 'rp', $kePendapatan),
            $kartu('Okupansi rata-rata', $h['okupansi'], $hLalu['okupansi'], 'persen', $keHunian),
            $kartu('ADR', $h['adr'], $hLalu['adr'], 'rp', $keHunian),
        ];
    }

    /* ---------- Front Office ---------- */

    /** @return list<array> */
    public function frontOffice(User $user): array
    {
        $a = $this->arusHariIni();
        $uangMuka = Reservation::where('status', 'pending_payment')
            ->where(fn ($q) => $q->whereNull('dp_due_at')->orWhere('dp_due_at', '>', now()))->count();
        $refund = PembayaranMidtrans::jumlahPerluRefund($user);

        return [
            ['label' => 'Kedatangan hari ini', 'nilai' => $a['menunggu']->count().' reservasi',
                'ket' => $a['dariKemarin'] > 0 ? 'termasuk '.$a['dariKemarin'].' dari kemarin' : 'belum check-in',
                'url' => route('staf.check-in'), 'awas' => $a['dariKemarin'] > 0, 'kunci' => 'kedatangan'],
            ['label' => 'Keberangkatan hari ini', 'nilai' => self::kamarReservasi($a['keberangkatan']),
                'ket' => 'check-out sebelum pukul '.str_replace(':', '.', FolioService::jamKeluar()), 'url' => route('staf.check-out'), 'awas' => false, 'kunci' => 'keberangkatan'],
            ['label' => 'Terlambat check-out', 'nilai' => self::kamarReservasi($a['terlambat']),
                'ket' => 'lewat jam check-out, masih menginap', 'url' => route('staf.check-out').'#terlambat', 'awas' => $a['terlambat']->isNotEmpty(), 'kunci' => 'terlambat'],
            ['label' => 'Menunggu uang muka', 'nilai' => $uangMuka.' reservasi', 'ket' => 'belum lewat batas bayar',
                'url' => route('staf.reservasi', ['status' => 'pending_payment']), 'awas' => false, 'kunci' => 'uang-muka'],
            ['label' => 'Refund menunggu', 'nilai' => $refund.' pengembalian', 'ket' => 'perlu dikembalikan ke tamu',
                'url' => route('staf.keuangan.pembayaran', ['tab' => 'refund']), 'awas' => $refund > 0, 'kunci' => 'refund'],
        ];
    }

    /** Format persen yang sama dengan halaman Laporan (satu desimal). */
    private static function persen(float $n): string
    {
        return Format::tampil($n, 'persen');
    }
}
