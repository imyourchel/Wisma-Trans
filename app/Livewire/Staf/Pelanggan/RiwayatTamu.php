<?php

namespace App\Livewire\Staf\Pelanggan;

use App\Models\Institution;
use App\Models\RoomType;
use App\Services\Laporan\Format;
use App\Services\Laporan\LaporanPelanggan;
use App\Support\BerkasCsv;
use App\Support\SegmenPelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Riwayat Tamu (Manager): kunjungan selesai (reservasi checked_out) yang tanggal masuknya dalam rentang, dengan segmen
 * per kunjungan (SegmenPelanggan), kamar terakhir, malam (termasuk kamar lama yang dipindah), total nota, dan layanan
 * tambahan. Tamu berulang memakai definisi Laporan Pelanggan (punya kunjungan sebelum rentang). Unduh CSV sesuai penyaring.
 */
class RiwayatTamu extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    /** '' | b2b | b2c */
    #[Url(except: '')]
    public string $segmen = '';

    #[Url(except: '')]
    public string $instansi = '';

    #[Url(except: '')]
    public string $tipe = '';

    public function mount(): void
    {
        Gate::authorize('manage-institutions');
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['dari', 'sampai', 'segmen', 'instansi', 'tipe'], true)) {
            $this->resetPage();
        }
    }

    public function resetSaringan(): void
    {
        $this->reset('dari', 'sampai', 'segmen', 'instansi', 'tipe');
        $this->resetPage();
    }

    /** Rentang tanggal masuk; bawaan 3 bulan terakhir. @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function rentang(): array
    {
        $baca = function (string $t) {
            $d = $t !== '' ? rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $t), null, false) : null;

            return $d && $d->format('Y-m-d') === $t ? $d : null;
        };
        $sampai = $baca($this->sampai) ?? CarbonImmutable::today();
        $dari = $baca($this->dari) ?? $sampai->subMonthsNoOverflow(3)->addDay();

        return $dari->gt($sampai) ? [$sampai, $dari] : [$dari, $sampai];
    }

    /** Kunjungan selesai sesuai penyaring (alias r = reservations, k = agregat kamar, c = customers, i = institutions). */
    private function kunjungan(): Builder
    {
        [$dari, $sampai] = $this->rentang();
        $kamar = DB::table('reservation_rooms')->whereIn('status', ['checked_out', 'moved'])->groupBy('reservation_id')
            ->selectRaw('reservation_id, MIN(check_in_date) AS masuk, MAX(check_out_date) AS keluar, SUM(DATEDIFF(check_out_date, check_in_date)) AS malam');
        $b2b = SegmenPelanggan::ekspresiReservasi('r');

        return DB::table('reservations as r')->joinSub($kamar, 'k', 'k.reservation_id', '=', 'r.id')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('institutions as i', 'i.id', '=', 'r.institution_id')
            ->where('r.status', 'checked_out')
            ->whereBetween('k.masuk', [$dari->toDateString(), $sampai->toDateString()])
            ->when($this->segmen === 'b2b', fn ($q) => $q->whereRaw("({$b2b}) = 1"))
            ->when($this->segmen === 'b2c', fn ($q) => $q->whereRaw("({$b2b}) = 0"))
            ->when(ctype_digit($this->instansi), fn ($q) => $q->where('r.institution_id', (int) $this->instansi))
            ->when(ctype_digit($this->tipe), fn ($q) => $q->whereExists(fn ($e) => $e->from('reservation_rooms as rt')->whereColumn('rt.reservation_id', 'r.id')
                ->whereIn('rt.status', ['checked_out', 'moved'])->where('rt.room_type_id', (int) $this->tipe)));
    }

    private function denganKolom(Builder $q): Builder
    {
        $layanan = fn (string $kategori) => 'EXISTS (SELECT 1 FROM service_orders so JOIN reservation_rooms x ON x.id = so.reservation_room_id'
            .' JOIN service_order_items soi ON soi.service_order_id = so.id JOIN services s ON s.id = soi.service_id'
            ." WHERE x.reservation_id = r.id AND so.status <> 'cancelled' AND s.category = '{$kategori}')";

        return $q->selectRaw('r.id, r.reservation_code, r.customer_id, c.full_name, c.phone, i.name AS instansi, k.masuk, k.keluar, k.malam, '
            .SegmenPelanggan::ekspresiReservasi('r').' AS b2b, '
            ."(SELECT GROUP_CONCAT(DISTINCT rm.room_number ORDER BY rm.room_number SEPARATOR ', ') FROM reservation_rooms x JOIN rooms rm ON rm.id = x.room_id WHERE x.reservation_id = r.id AND x.status = 'checked_out') AS kamar, "
            ."(SELECT COALESCE(SUM(iv.total_amount), 0) FROM invoices iv WHERE iv.reservation_id = r.id AND iv.status <> 'cancelled') AS total_nota, "
            .$layanan('fnb').' AS makanan, '.$layanan('laundry').' AS laundry, '
            ."EXISTS (SELECT 1 FROM hall_bookings hb WHERE hb.reservation_id = r.id AND hb.status IN ('confirmed', 'completed')) AS aula")
            ->orderByDesc('k.masuk')->orderByDesc('r.id');
    }

    /** @return array{kunjungan: int, tamu: int, baru: int, berulang: int, malam: int, malam_b2b: int, malam_b2c: int} */
    public function ringkasan(): array
    {
        $semua = $this->kunjungan()->selectRaw('r.customer_id, k.malam, '.SegmenPelanggan::ekspresiReservasi('r').' AS b2b')->get();
        $ids = $semua->pluck('customer_id')->unique()->values();
        $berulang = LaporanPelanggan::jumlahBerulang($ids, $this->rentang()[0]);

        return [
            'kunjungan' => $semua->count(),
            'tamu' => $ids->count(),
            'baru' => $ids->count() - $berulang,
            'berulang' => $berulang,
            'malam' => (int) $semua->sum('malam'),
            'malam_b2b' => (int) $semua->where('b2b', 1)->sum('malam'),
            'malam_b2c' => (int) $semua->where('b2b', 0)->sum('malam'),
        ];
    }

    public function unduhCsv(): StreamedResponse
    {
        Gate::authorize('manage-institutions');
        [$dari, $sampai] = $this->rentang();
        $baris = $this->denganKolom($this->kunjungan())->get();

        return BerkasCsv::unduh('Riwayat-Tamu-'.$dari->format('Ymd').'-'.$sampai->format('Ymd').'.csv', function (callable $tulis) use ($baris, $dari, $sampai) {
            $tulis(['Riwayat Tamu']);
            $tulis(['Tanggal masuk', $dari->toDateString().' s.d. '.$sampai->toDateString()]);
            $tulis([]);
            $tulis(['Masuk', 'Keluar', 'Reservasi', 'Pelanggan', 'Segmen', 'Instansi', 'Kamar', 'Malam', 'Total nota', 'Makanan', 'Laundry', 'Aula']);
            foreach ($baris as $b) {
                $tulis([$b->masuk, $b->keluar, $b->reservation_code, $b->full_name, $b->b2b ? 'B2B' : 'B2C', $b->instansi ?? '', $b->kamar ?? '',
                    Format::csv($b->malam, 'angka'), Format::csv($b->total_nota, 'rp'), $b->makanan ? 'Ya' : 'Tidak', $b->laundry ? 'Ya' : 'Tidak', $b->aula ? 'Ya' : 'Tidak']);
            }
        });
    }

    public function render(): View
    {
        [$dari, $sampai] = $this->rentang();

        return view('livewire.staf.pelanggan.riwayat-tamu', [
            'daftar' => $this->denganKolom($this->kunjungan())->paginate(25),
            'ringkasan' => $this->ringkasan(),
            'mulai' => $dari,
            'akhir' => $sampai,
            'daftarInstansi' => Institution::orderBy('name')->pluck('name', 'id'),
            'daftarTipe' => RoomType::orderBy('base_price')->pluck('name', 'id'),
        ])->layout('components.layouts.staf', ['title' => 'Riwayat Tamu']);
    }
}
