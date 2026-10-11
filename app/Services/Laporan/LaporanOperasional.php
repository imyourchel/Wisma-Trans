<?php

namespace App\Services\Laporan;

use App\Livewire\Staf\Kamar\RiwayatStatus;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceRequest;
use App\Models\ReservationRoom;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operasional per hari (atau per bulan bila rentang panjang): kamar terisi, check-in/out, F&B (pesanan, batal,
 * menu terlaris, jenis penyajian), laundry (pesanan dan kg), maintenance corrective (baru, selesai, rata-rata waktu
 * perbaikan, jalur cepat; perawatan rutin terpisah), dan housekeeping (tugas selesai per staf, durasi rata-rata).
 */
class LaporanOperasional
{
    use MenyusunDokumen;

    public function __construct(private LaporanHunian $hunian) {}

    private static function rentang(Periode $p): array
    {
        return [$p->mulai->startOfDay(), $p->akhir->endOfDay()];
    }

    /** Pesanan berdasarkan kategori layanan (fnb atau laundry). */
    private function pesanan(Periode $p, string $kategori): Collection
    {
        return ServiceOrder::with('items.service')->whereBetween('order_date', self::rentang($p))
            ->whereHas('items.service', fn ($q) => $q->where('category', $kategori))
            ->get(['id', 'order_date', 'status', 'serving_type']);
    }

    public function hitung(Periode $p): array
    {
        $rentang = self::rentang($p);
        $fnb = $this->pesanan($p, 'fnb');
        $laundry = $this->pesanan($p, 'laundry');
        $fnbAktif = $fnb->where('status', '!=', 'cancelled');
        // Berat laundry dihitung setelah ditimbang (perkiraan sebelum timbang tidak ikut)
        $kg = fn (Collection $g) => (float) $g->whereIn('status', ['in_progress', 'ready', 'delivered'])->sum(fn ($o) => (float) $o->items->sum('quantity'));

        $mntBaru = MaintenanceRequest::where('maintenance_type', 'corrective')->whereBetween('reported_at', $rentang)->get(['id', 'reported_at']);
        $mntSelesai = MaintenanceRequest::where('maintenance_type', 'corrective')->where('status', 'completed')->whereBetween('completed_at', $rentang)
            ->get(['id', 'reported_at', 'completed_at', 'resolution_note', 'status']);
        $rutin = MaintenanceRequest::where('maintenance_type', 'preventive')->where('status', 'completed')->whereBetween('completed_at', $rentang)->count();

        $tugas = HousekeepingTask::with('assignees:id,housekeeping_task_id,user_id')->whereIn('status', ['completed', 'verified'])
            ->whereBetween('completed_at', $rentang)->get(['id', 'started_at', 'completed_at']);
        $durasi = fn (Collection $g) => self::bagi($g->filter(fn ($t) => $t->started_at)->sum(fn ($t) => $t->started_at->diffInSeconds($t->completed_at)), $g->filter(fn ($t) => $t->started_at)->count());
        $nama = User::whereIn('id', $tugas->flatMap(fn ($t) => $t->assignees->pluck('user_id'))->unique())->pluck('name', 'id');
        $perStaf = $tugas->flatMap(fn ($t) => $t->assignees->map(fn ($a) => ['user' => $a->user_id, 'tugas' => $t]))->groupBy('user')
            ->map(fn ($g, $id) => ['nama' => $nama[$id] ?? '—', 'tugas' => $g->count(), 'durasi' => $durasi($g->pluck('tugas'))])
            ->sortByDesc('tugas')->values();

        $terlaris = DB::table('service_order_items as soi')->join('service_orders as so', 'so.id', '=', 'soi.service_order_id')
            ->join('services as s', 's.id', '=', 'soi.service_id')
            ->where('s.category', 'fnb')->where('so.status', '!=', 'cancelled')->whereBetween('so.order_date', $rentang)
            ->groupBy('s.id', 's.name')->selectRaw('s.name, SUM(soi.quantity) AS porsi, SUM(soi.subtotal) AS nilai')
            ->orderByDesc('porsi')->orderByDesc('nilai')->limit(5)->get();

        $huni = $this->hunian->ringkas($p);
        $masuk = ReservationRoom::whereNull('moved_from_id')->whereBetween('actual_check_in_at', $rentang)->pluck('actual_check_in_at');
        $keluar = ReservationRoom::where('status', 'checked_out')->whereBetween('actual_check_out_at', $rentang)->pluck('actual_check_out_at');

        // Rekap per kelompok (hari atau bulan)
        $hitungPer = fn (Collection $tanggal) => $tanggal->countBy(fn ($t) => $p->kunci(substr((string) $t, 0, 10)));
        $kolom = [
            'terisi' => collect($huni['perHari'])->groupBy(fn ($n, $t) => $p->kunci($t), true)->map->sum(),
            'checkIn' => $hitungPer($masuk),
            'checkOut' => $hitungPer($keluar),
            'fnb' => $hitungPer($fnbAktif->pluck('order_date')),
            'fnbBatal' => $hitungPer($fnb->where('status', 'cancelled')->pluck('order_date')),
            'laundry' => $hitungPer($laundry->where('status', '!=', 'cancelled')->pluck('order_date')),
            'kg' => $laundry->groupBy(fn ($o) => $p->kunci($o->order_date->toDateString()))->map($kg),
            'mntBaru' => $hitungPer($mntBaru->pluck('reported_at')),
            'mntSelesai' => $hitungPer($mntSelesai->pluck('completed_at')),
            'hk' => $hitungPer($tugas->pluck('completed_at')),
        ];

        return [
            'harian' => collect($p->kelompok())->map(fn ($label, $k) => [$label, ...array_map(fn ($c) => $c[$k] ?? 0, $kolom)])->values()->all(),
            'fnb' => $fnbAktif->count(),
            'fnbBatal' => $fnb->where('status', 'cancelled')->count(),
            'penyajian' => collect(ServiceOrder::SERVING)->map(fn ($l, $k) => $fnbAktif->where('serving_type', $k)->count())->all(),
            'terlaris' => $terlaris,
            'laundry' => $laundry->where('status', '!=', 'cancelled')->count(),
            'kg' => $kg($laundry),
            'mntBaru' => $mntBaru->count(),
            'mntSelesai' => $mntSelesai->count(),
            // Hanya corrective; perawatan rutin dicatat langsung selesai sehingga akan menurunkan rata-rata
            'mntRata' => self::bagi($mntSelesai->sum(fn ($m) => $m->reported_at->diffInSeconds($m->completed_at)), $mntSelesai->count()),
            'jalurCepat' => $mntSelesai->filter(fn ($m) => str_starts_with((string) $m->resolution_note, MaintenanceRequest::CATATAN_JALUR_CEPAT))->count(),
            'rutin' => $rutin,
            'hkTotal' => $tugas->count(),
            'hkDurasi' => $durasi($tugas),
            'hkStaf' => $perStaf,
        ];
    }

    public function dokumen(Periode $p): array
    {
        $ini = $this->hitung($p);
        $lalu = $this->hitung($p->sebelumnya());

        return [
            'judul' => 'Laporan Operasional',
            'ringkasan' => [
                $this->kartu('Pesanan F&B', $ini['fnb'], $lalu['fnb'], 'angka', $ini['fnbBatal'].' dibatalkan'),
                $this->kartu('Pesanan laundry', $ini['laundry'], $lalu['laundry'], 'angka', rtrim(rtrim(number_format($ini['kg'], 1, ',', '.'), '0'), ',').' kg ditimbang'),
                $this->kartu('Laporan kerusakan baru', $ini['mntBaru'], $lalu['mntBaru'], 'angka'),
                $this->kartu('Kerusakan selesai', $ini['mntSelesai'], $lalu['mntSelesai'], 'angka', $ini['jalurCepat'].' lewat jalur cepat'),
                $this->kartu('Rata-rata waktu perbaikan', $ini['mntRata'], $lalu['mntRata'], 'durasi', 'Laporan kerusakan (corrective) saja'),
                $this->kartu('Perawatan rutin selesai', $ini['rutin'], $lalu['rutin'], 'angka'),
                $this->kartu('Tugas housekeeping selesai', $ini['hkTotal'], $lalu['hkTotal'], 'angka', 'Rata-rata '.RiwayatStatus::lama((int) round($ini['hkDurasi'])).' per kamar'),
            ],
            'grafik' => [[
                'judul' => 'Pesanan F&B dan laundry per '.($p->bulanan() ? 'bulan' : 'hari'),
                'format' => 'angka',
                'label' => array_values($p->kelompok()),
                'seri' => [
                    ['nama' => 'F&B', 'warna' => '#B5714A', 'nilai' => array_column($ini['harian'], 4)],
                    ['nama' => 'Laundry', 'warna' => '#5B86B8', 'nilai' => array_column($ini['harian'], 6)],
                ],
            ]],
            'tabel' => [
                [
                    'judul' => 'Rekap per '.($p->bulanan() ? 'bulan' : 'hari'),
                    'kolom' => [['Tanggal', 'teks'], ['Kamar terisi', 'angka'], ['Check-in', 'angka'], ['Check-out', 'angka'], ['F&B', 'angka'], ['F&B batal', 'angka'],
                        ['Laundry', 'angka'], ['Laundry kg', 'desimal'], ['Kerusakan baru', 'angka'], ['Kerusakan selesai', 'angka'], ['Tugas HK selesai', 'angka']],
                    'baris' => $ini['harian'],
                ],
                [
                    'judul' => '5 menu terlaris',
                    'kolom' => [['Menu', 'teks'], ['Porsi', 'angka'], ['Nilai', 'rp']],
                    'baris' => $ini['terlaris']->map(fn ($m) => [$m->name, (int) $m->porsi, (float) $m->nilai])->all(),
                ],
                [
                    'judul' => 'Pesanan F&B per jenis penyajian',
                    'kolom' => [['Penyajian', 'teks'], ['Pesanan', 'angka']],
                    'baris' => collect(ServiceOrder::SERVING)->map(fn ($l, $k) => [$l, $ini['penyajian'][$k]])->values()->all(),
                ],
                [
                    'judul' => 'Housekeeping per staf ('.$ini['hkTotal'].' tugas selesai; satu kamar bisa dikerjakan dua orang)',
                    'kolom' => [['Staf', 'teks'], ['Tugas selesai', 'angka'], ['Rata-rata durasi', 'durasi']],
                    'baris' => $ini['hkStaf']->map(fn ($s) => [$s['nama'], $s['tugas'], $s['durasi']])->all(),
                ],
            ],
            'catatan' => ['Waktu perbaikan = laporan dibuat sampai selesai, hanya laporan kerusakan; perawatan rutin dihitung terpisah.'],
        ];
    }
}
