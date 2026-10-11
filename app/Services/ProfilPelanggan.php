<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Institution;
use App\Models\Reservation;
use App\Support\SegmenPelanggan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Profil satu pelanggan (CRM): segmen, kunjungan, kamar dan layanan yang sering dipakai, belanja pribadi, dan
 * rekomendasi untuk transaksi berikutnya. Semua nilai dihitung saat query (tidak disimpan), lalu di-cache
 * 10 menit; cache dihapus saat check-out (CheckOutService).
 *
 * Kunjungan = reservasi berstatus checked_out. Jumlah kunjungan dan malam menghitung semua kunjungan, tetapi pola
 * kebiasaan (kamar, tipe, menu, laundry, lama menginap) hanya dari kunjungan pribadi: reservasi B2C, atau reservasi
 * instansi dengan satu kamar (PIC menginap sendiri untuk dinas). Pesanan rombongan tercatat atas nama PIC, jadi
 * disaring menurut kunjungannya; polanya dihitung per instansi di untukInstansi().
 *
 * Malam menghitung semua baris kamar (termasuk kamar lama yang dipindah); kamar dan tipe favorit hanya dari kamar
 * terakhir (baris checked_out), karena tamu biasanya pindah akibat masalah di kamar lamanya.
 */
class ProfilPelanggan
{
    public const MENIT_CACHE = 10;

    /** Rekomendasi hanya bila minimal sekian kunjungan pribadi (atau rombongan, untuk instansi). */
    public const MIN_KUNJUNGAN = 2;

    /** Ambang pola: dipakai di sekurang-kurangnya separuh kunjungan. */
    public const AMBANG = 0.5;

    public const MIN_PESAN_MENU = 2;

    public const MIN_RATA_MALAM = 3;

    public const CATATAN_INSTANSI = 'Belanja atas nama instansi tercatat di halaman Instansi.';

    public static function kunci(int $customerId): string
    {
        return 'profil-pelanggan:'.$customerId;
    }

    public static function kunciInstansi(int $institutionId): string
    {
        return 'profil-instansi:'.$institutionId;
    }

    public static function lupakan(int $customerId, ?int $institutionId = null): void
    {
        Cache::forget(self::kunci($customerId));
        if ($institutionId) {
            Cache::forget(self::kunciInstansi($institutionId));
        }
    }

    /* ---------- Subquery (alias tabel customers $c / reservations $r), dipakai juga oleh Daftar Pelanggan ---------- */

    public static function sqlKunjungan(string $c = 'customers'): string
    {
        return "(SELECT COUNT(*) FROM reservations rk WHERE rk.customer_id = {$c}.id AND rk.status = 'checked_out')";
    }

    public static function sqlMalam(string $c = 'customers'): string
    {
        return '(SELECT COALESCE(SUM(DATEDIFF(rrm.check_out_date, rrm.check_in_date)), 0) FROM reservation_rooms rrm'
            ." JOIN reservations rm ON rm.id = rrm.reservation_id WHERE rm.customer_id = {$c}.id AND rm.status = 'checked_out'"
            ." AND rrm.status IN ('checked_out', 'moved'))";
    }

    public static function sqlTerakhir(string $c = 'customers'): string
    {
        return '(SELECT MAX(rrt.check_out_date) FROM reservation_rooms rrt JOIN reservations rt ON rt.id = rrt.reservation_id'
            ." WHERE rt.customer_id = {$c}.id AND rt.status = 'checked_out' AND rrt.status IN ('checked_out', 'moved'))";
    }

    /**
     * Belanja pribadi: pembayaran berhasil dikurangi refund berhasil, dari nota pelanggan yang tidak batal dan tanpa
     * institution_id. Nota instansi (dibayar per nota maupun lewat tagihan bulanan) masuk ke pendapatan instansi.
     */
    public static function sqlBelanja(string $c = 'customers'): string
    {
        return "(SELECT COALESCE(SUM(CASE WHEN pb.transaction_type = 'refund' THEN -pb.amount ELSE pb.amount END), 0) FROM payments pb"
            ." JOIN invoices ib ON ib.id = pb.invoice_id WHERE ib.customer_id = {$c}.id AND ib.institution_id IS NULL"
            ." AND ib.status <> 'cancelled' AND pb.status = 'success')";
    }

    /** Kunjungan pribadi: reservasi B2C (SegmenPelanggan), atau reservasi instansi dengan satu kamar. Bernilai 1 atau 0. */
    public static function sqlPribadi(string $r = 'r'): string
    {
        return '(CASE WHEN ('.SegmenPelanggan::ekspresiReservasi($r).') = 0'
            ." OR (SELECT COUNT(*) FROM reservation_rooms rkp WHERE rkp.reservation_id = {$r}.id AND rkp.status NOT IN ('moved', 'cancelled')) = 1"
            .' THEN 1 ELSE 0 END)';
    }

    public static function pribadi(Reservation $r): bool
    {
        return ! $r->instansi() || $r->kamarBerlaku()->count() === 1;
    }

    /* ---------- Profil pelanggan ---------- */

    /**
     * @return array{
     *     segmen: array{b2b: bool, label: string, instansi: int, pribadi: int, rincian: string},
     *     kunjungan: array{jumlah: int, malam: int, pertama: ?string, terakhir: ?string, pribadi: int, rombongan: int, rata: float},
     *     kamar: array{nomor: list<array{id: int, nomor: string, tipe: int, jumlah: int}>, tipe: list<array{id: int, nama: string, jumlah: int}>, favorit: ?array{id: int, nomor: string, tipe: int}},
     *     layanan: array{menu: list<array{nama: string, pesanan: int, porsi: float}>, laundry_kg: float, laundry_pesanan: int, laundry_kunjungan: int, aula: int},
     *     nilai: array{belanja: float},
     *     rekomendasi: list<string>,
     *     catatan: ?string
     * }
     */
    public function untuk(Customer $c): array
    {
        return Cache::remember(self::kunci($c->id), now()->addMinutes(self::MENIT_CACHE), fn () => $this->susun($c->id));
    }

    private function susun(int $id): array
    {
        $dasar = DB::table('customers as c')->where('c.id', $id)
            ->selectRaw(SegmenPelanggan::ekspresiPelanggan('c').' AS b2b, '.self::sqlBelanja('c').' AS belanja')->first();

        $kunjungan = $this->kunjungan(fn ($q) => $q->where('r.customer_id', $id));
        $pribadi = $kunjungan->where('pribadi', 1)->values();
        $n = $pribadi->count();
        $rata = $n > 0 ? $pribadi->sum('malam') / $n : 0.0;

        // Pola hanya dari kunjungan pribadi, ditambah pesanan tamu luar atas nama pelanggan ini
        $kamar = $this->kamar($pribadi->pluck('id'));
        $layanan = [...$this->layanan($pribadi->pluck('id'), $id),
            'aula' => DB::table('hall_bookings')->where('customer_id', $id)->whereIn('status', ['confirmed', 'completed'])->count()];

        $b2b = (bool) ($dasar->b2b ?? false);
        $instansi = $kunjungan->where('b2b', 1)->count();
        $rombongan = $kunjungan->count() - $n;
        $favorit = null;
        $saran = [];
        if ($n >= self::MIN_KUNJUNGAN) {
            $pola = fn (int $jumlah) => $jumlah / $n >= self::AMBANG;
            if (($k = $kamar['nomor'][0] ?? null) && $pola($k['jumlah'])) {
                $saran[] = "Siapkan Kamar {$k['nomor']} (dipakai {$k['jumlah']} dari {$n} kunjungan)";
                $favorit = ['id' => $k['id'], 'nomor' => $k['nomor'], 'tipe' => $k['tipe']];
            }
            if (($t = $kamar['tipe'][0] ?? null) && $pola($t['jumlah'])) {
                $saran[] = "Tawarkan tipe {$t['nama']}";
            }
            if ($layanan['laundry_kunjungan'] > 0 && $pola($layanan['laundry_kunjungan'])) {
                $saran[] = 'Tawarkan laundry saat check-in';
            }
            if (($m = $layanan['menu'][0] ?? null) && $m['pesanan'] >= self::MIN_PESAN_MENU) {
                $saran[] = "Menu favorit: {$m['nama']}";
            }
            if ($rata >= self::MIN_RATA_MALAM) {
                $saran[] = 'Biasanya menginap '.(int) round($rata).' malam';
            }
        }

        return [
            'segmen' => [
                'b2b' => $b2b,
                'label' => $b2b ? 'B2B' : 'B2C',
                'instansi' => $instansi,
                'pribadi' => $kunjungan->count() - $instansi,
                'rincian' => self::rincianSegmen($b2b, $instansi, $kunjungan->count() - $instansi),
            ],
            'kunjungan' => [
                'jumlah' => $kunjungan->count(),
                'malam' => (int) $kunjungan->sum('malam'),
                'pertama' => $kunjungan->min('masuk'),
                'terakhir' => $kunjungan->max('keluar'),
                'pribadi' => $n,
                'rombongan' => $rombongan,
                'rata' => round($rata, 1),
            ],
            'kamar' => [...$kamar, 'favorit' => $favorit],
            'layanan' => $layanan,
            'nilai' => ['belanja' => (float) ($dasar->belanja ?? 0)],
            'rekomendasi' => $saran,
            'catatan' => $rombongan > 0
                ? "Rekomendasi dihitung dari {$n} kunjungan pribadi; {$rombongan} kunjungan rombongan dihitung di halaman Instansi."
                : null,
        ];
    }

    /** "B2B (3 kunjungan instansi, 1 kunjungan pribadi)" */
    public static function rincianSegmen(bool $b2b, int $instansi, int $pribadi): string
    {
        if (! $b2b) {
            return 'B2C (perorangan)';
        }
        if ($instansi + $pribadi === 0) {
            return 'B2B (narahubung instansi, belum ada kunjungan selesai)';
        }

        return "B2B ({$instansi} kunjungan instansi, {$pribadi} kunjungan pribadi)";
    }

    /* ---------- Pola rombongan instansi ---------- */

    /**
     * Pola semua reservasi selesai satu instansi: rata-rata kamar dan lama menginap per rombongan, tipe yang sering
     * diminta, menu terbanyak (per pesanan) beserta waktu makannya, porsi rombongan yang memakai laundry, dan bulan
     * kedatangan yang berulang.
     *
     * @return array{rombongan: int, rata_kamar: float, rata_malam: float, tipe: list<array{id: int, nama: string, jumlah: int}>,
     *     kamar_total: int, menu: list<array{nama: string, pesanan: int, porsi: float, waktu: string}>, laundry: int, bulan: list<string>, rekomendasi: list<string>}
     */
    public function untukInstansi(Institution $i): array
    {
        return Cache::remember(self::kunciInstansi($i->id), now()->addMinutes(self::MENIT_CACHE), fn () => $this->susunInstansi($i->id));
    }

    private function susunInstansi(int $id): array
    {
        $kunjungan = $this->kunjungan(fn ($q) => $q->where('r.institution_id', $id));
        $n = $kunjungan->count();
        $ids = $kunjungan->pluck('id');
        $kamar = $this->kamar($ids, true);
        $layanan = $this->layanan($ids, null, 3);
        $rataKamar = $n > 0 ? $kunjungan->sum('kamar') / $n : 0.0;
        $rataMalam = $n > 0 ? $kunjungan->sum('lama') / $n : 0.0;
        $kamarTotal = (int) $kunjungan->sum('kamar');

        // Bulan yang muncul pada sekurang-kurangnya dua rombongan
        $bulan = $kunjungan->countBy(fn ($k) => (int) substr((string) $k->masuk, 5, 2))->filter(fn ($x) => $x >= self::MIN_KUNJUNGAN)
            ->keys()->sort()->map(fn ($b) => Carbon::create(2000, $b, 1)->translatedFormat('F'))->values()->all();

        $saran = [];
        if ($n >= self::MIN_KUNJUNGAN) {
            $t = $kamar['tipe'][0] ?? null;
            $namaTipe = $t && $kamarTotal > 0 && $t['jumlah'] / $kamarTotal >= self::AMBANG ? ' '.self::namaPendek($t['nama']) : '';
            $saran[] = 'Biasanya '.max(1, (int) round($rataKamar)).' kamar'.$namaTipe.' selama '.max(1, (int) round($rataMalam)).' malam';
            if (($m = $layanan['menu'][0] ?? null) && $m['pesanan'] >= self::MIN_PESAN_MENU) {
                $saran[] = "Siapkan paket {$m['waktu']}: {$m['nama']}";
            }
            if ($layanan['laundry_kunjungan'] > 0 && $layanan['laundry_kunjungan'] / $n >= self::AMBANG) {
                $saran[] = 'Tawarkan laundry untuk rombongan';
            }
            if ($bulan) {
                $saran[] = 'Biasanya datang '.collect($bulan)->join(', ', ' dan ');
            }
        }

        return [
            'rombongan' => $n,
            'rata_kamar' => round($rataKamar, 1),
            'rata_malam' => round($rataMalam, 1),
            'tipe' => $kamar['tipe'],
            'kamar_total' => $kamarTotal,
            'menu' => $layanan['menu'],
            'laundry' => $layanan['laundry_kunjungan'],
            'bulan' => $bulan,
            'rekomendasi' => $saran,
        ];
    }

    /** "Standard Room" menjadi "Standard" untuk kalimat rekomendasi. */
    private static function namaPendek(string $tipe): string
    {
        return trim(preg_replace('/\s+(Room|Suite)$/i', '', explode('/', $tipe)[0])) ?: $tipe;
    }

    /* ---------- Bahan bersama ---------- */

    /**
     * Kunjungan selesai yang cocok dengan penyaring: masuk, keluar, malam (semua baris), lama (masuk sampai keluar),
     * jumlah kamar terakhir, segmen, dan apakah kunjungan pribadi.
     */
    private function kunjungan(callable $saring): Collection
    {
        $q = DB::table('reservations as r')->join('reservation_rooms as rr', 'rr.reservation_id', '=', 'r.id')
            ->where('r.status', 'checked_out')->whereIn('rr.status', ['checked_out', 'moved'])
            ->groupBy('r.id')
            ->selectRaw("r.id, MIN(rr.check_in_date) AS masuk, MAX(rr.check_out_date) AS keluar,
                SUM(DATEDIFF(rr.check_out_date, rr.check_in_date)) AS malam, DATEDIFF(MAX(rr.check_out_date), MIN(rr.check_in_date)) AS lama,
                SUM(rr.status = 'checked_out') AS kamar, MAX(".SegmenPelanggan::ekspresiReservasi('r').') AS b2b, MAX('.self::sqlPribadi('r').') AS pribadi');

        return $saring($q)->get()->map(function ($k) {
            foreach (['malam', 'lama', 'kamar', 'b2b', 'pribadi'] as $kolom) {
                $k->{$kolom} = (int) $k->{$kolom};
            }

            return $k;
        });
    }

    /**
     * Kamar dan tipe terakhir yang dipakai tiap kunjungan. Nomor dihitung sekali per reservasi; tipe per reservasi
     * untuk pelanggan, atau per kamar untuk rombongan ($tipePerKamar).
     */
    private function kamar(Collection $reservasiIds, bool $tipePerKamar = false): array
    {
        if ($reservasiIds->isEmpty()) {
            return ['nomor' => [], 'tipe' => []];
        }
        $baris = DB::table('reservation_rooms as rr')->join('rooms as k', 'k.id', '=', 'rr.room_id')->join('room_types as t', 't.id', '=', 'rr.room_type_id')
            ->whereIn('rr.reservation_id', $reservasiIds->all())->where('rr.status', 'checked_out')
            ->get(['rr.reservation_id AS reservasi', 'rr.id AS baris', 'k.id AS kamar', 'k.room_number AS nomor', 't.id AS tipe', 't.name AS nama_tipe', 'rr.check_out_date']);

        $urut = fn (Collection $g, callable $isi, string $hitung) => $g->map(fn (Collection $x) => [...$isi($x->first()),
            'jumlah' => $x->pluck($hitung)->unique()->count(), 'terakhir' => (string) $x->max('check_out_date')])
            ->sortBy([['jumlah', 'desc'], ['terakhir', 'desc']])->values()
            ->map(fn ($x) => array_diff_key($x, ['terakhir' => 0]))->all();

        return [
            'nomor' => $urut($baris->groupBy('kamar'), fn ($b) => ['id' => (int) $b->kamar, 'nomor' => (string) $b->nomor, 'tipe' => (int) $b->tipe], 'reservasi'),
            'tipe' => $urut($baris->groupBy('tipe'), fn ($b) => ['id' => (int) $b->tipe, 'nama' => (string) $b->nama_tipe], $tipePerKamar ? 'baris' : 'reservasi'),
        ];
    }

    /**
     * Menu (per pesanan, kategori fnb), laundry, dan waktu makan dari pesanan in-house pada kunjungan $reservasiIds,
     * ditambah pesanan tamu luar atas nama $pelangganLuar. Pesanan batal tidak dihitung.
     */
    private function layanan(Collection $reservasiIds, ?int $pelangganLuar, int $jumlahMenu = 5): array
    {
        $barisKamar = DB::table('reservation_rooms')->whereIn('reservation_id', $reservasiIds->all() ?: [0])->select('id');
        $pesanan = fn () => DB::table('service_order_items as soi')->join('service_orders as so', 'so.id', '=', 'soi.service_order_id')
            ->join('services as s', 's.id', '=', 'soi.service_id')->where('so.status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereIn('so.reservation_room_id', $barisKamar)
                ->when($pelangganLuar, fn ($q) => $q->orWhere(fn ($q) => $q->where('so.order_type', 'outside_guest')->where('so.customer_id', $pelangganLuar))));

        $menu = $pesanan()->where('s.category', 'fnb')->get(['s.id', 's.name', 'so.id AS pesanan', 'soi.quantity', 'so.order_date'])
            ->groupBy('id')->map(fn (Collection $g) => [
                'nama' => (string) $g->first()->name,
                'pesanan' => $g->pluck('pesanan')->unique()->count(),
                'porsi' => (float) $g->sum('quantity'),
                'waktu' => $g->unique('pesanan')->countBy(fn ($x) => self::waktuMakan((int) substr((string) $x->order_date, 11, 2)))->sortDesc()->keys()->first(),
            ])->sortBy([['pesanan', 'desc'], ['porsi', 'desc']])->take($jumlahMenu)->values()->all();

        // Berat dihitung setelah ditimbang, sama dengan Laporan Operasional
        $kg = (float) $pesanan()->where('s.category', 'laundry')->whereRaw("LOWER(s.unit) = 'kg'")
            ->whereIn('so.status', ['in_progress', 'ready', 'delivered'])->sum('soi.quantity');
        $laundryKunjungan = $reservasiIds->isEmpty() ? 0 : DB::table('service_orders as so')
            ->join('service_order_items as soi', 'soi.service_order_id', '=', 'so.id')->join('services as s', 's.id', '=', 'soi.service_id')
            ->join('reservation_rooms as rr', 'rr.id', '=', 'so.reservation_room_id')
            ->whereIn('rr.reservation_id', $reservasiIds->all())->where('s.category', 'laundry')->where('so.status', '!=', 'cancelled')
            ->distinct()->count('rr.reservation_id');

        return [
            'menu' => $menu,
            'laundry_kg' => $kg,
            'laundry_pesanan' => $pesanan()->where('s.category', 'laundry')->distinct()->count('so.id'),
            'laundry_kunjungan' => $laundryKunjungan,
        ];
    }

    private static function waktuMakan(int $jam): string
    {
        return match (true) {
            $jam < 10 => 'sarapan',
            $jam < 15 => 'makan siang',
            default => 'makan malam',
        };
    }

    /* ---------- Bantuan tampilan ---------- */

    /** "Tamu baru" untuk kunjungan pertama, selebihnya "Kunjungan ke-N"; "(pribadi)" bila pelanggan juga datang bersama rombongan. */
    public static function labelKunjungan(int $ke, bool $pribadi = false): string
    {
        return ($ke <= 1 ? 'Tamu baru' : "Kunjungan ke-{$ke}").($pribadi ? ' (pribadi)' : '');
    }

    /** Label kunjungan berikutnya untuk pelanggan dengan profil $p (misalnya di Reservasi Langsung). */
    public static function labelBerikutnya(array $p): string
    {
        return self::labelKunjungan($p['kunjungan']['pribadi'] + 1, $p['kunjungan']['rombongan'] > 0);
    }

    /** Urutan kunjungan pribadi reservasi ini: kunjungan pribadi selesai lain yang masuk lebih dulu, ditambah satu. */
    public function kunjunganKe(Reservation $r): int
    {
        $masuk = $r->masuk()?->toDateString() ?? today()->toDateString();

        return DB::table('reservations as x')->where('x.customer_id', $r->customer_id)->where('x.status', 'checked_out')->where('x.id', '!=', $r->id)
            ->whereRaw(self::sqlPribadi('x').' = 1')
            ->whereRaw("(SELECT MIN(y.check_in_date) FROM reservation_rooms y WHERE y.reservation_id = x.id AND y.status IN ('checked_out', 'moved')) < ?", [$masuk])
            ->count() + 1;
    }
}
