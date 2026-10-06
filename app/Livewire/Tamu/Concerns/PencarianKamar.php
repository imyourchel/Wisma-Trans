<?php

namespace App\Livewire\Tamu\Concerns;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\KetersediaanKamar;
use App\Support\PencarianInap;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Tanggal menginap dan susunan tamu yang dibawa di URL (?masuk=&keluar=&tamu=), dipakai
 * halaman daftar kamar dan detail tipe kamar. Format tamu dijelaskan di PencarianInap.
 */
trait PencarianKamar
{
    #[Url(except: '')]
    public string $masuk = '';

    #[Url(except: '')]
    public string $keluar = '';

    #[Url(except: '')]
    public string $tamu = '';

    /** Dipanggil tombol Cek Ketersediaan; daftar diperbarui tanpa pindah halaman. */
    public function cek(string $masuk, string $keluar, string $tamu): void
    {
        $this->masuk = $masuk;
        $this->keluar = $keluar;
        $this->tamu = PencarianInap::teks(PencarianInap::susunan($tamu));
    }

    protected function pencarian(): PencarianInap
    {
        return PencarianInap::dari($this->masuk, $this->keluar, $this->tamu);
    }

    /** Tipe yang punya kamar aktif, dari harga termurah; kasur diambil dari kamar-kamar aktifnya. */
    protected function tipeKamar(): Collection
    {
        return RoomType::query()
            ->whereHas('rooms', fn ($q) => $q->where('is_active', true))
            ->with(['facilities' => fn ($q) => $q->orderBy('facilities.id'), 'rooms' => fn ($q) => $q->where('is_active', true)->select('id', 'room_type_id', 'bed_type')])
            ->orderBy('base_price')->orderBy('id')
            ->get()
            ->each(fn (RoomType $t) => $t->setAttribute('kasur', $t->rooms->pluck('bed_type')->unique()
                ->sortBy(fn ($b) => array_search($b, array_keys(Room::BED_TYPES), true))
                ->map(fn ($b) => Room::BED_TYPES[$b] ?? $b)->values()->all()));
    }

    /**
     * Keadaan tiap tipe untuk pencarian aktif. Penghuni per kamar (dewasa dan anak) dibandingkan dengan kapasitas tipe:
     *  - tersedia   : semua kamar muat dan sisa kamar cukup
     *  - kurang     : semua kamar muat, tetapi sisa kamar lebih sedikit dari yang diminta
     *  - sebagian   : hanya sebagian kamar yang muat (kamar lain butuh tipe lebih besar)
     *  - penuh      : ada kamar yang muat, tetapi tidak ada sisa kamar di tanggal itu
     *  - tidak-muat : tidak ada kamar yang muat di tipe ini
     * kamarDipesan = urutan kamar (0, 1, ...) yang bisa dipesan di tipe ini.
     *
     * @return array<int, array{jenis: string, sisa: int, muat: int, dipesan: int, kamarDipesan: list<int>, total: float}>
     */
    protected function statusPerTipe(Collection $tipe, PencarianInap $cari): array
    {
        if (! $cari->aktif() || $tipe->isEmpty()) {
            return [];
        }
        $sisa = app(KetersediaanKamar::class)->sisa($cari->masuk, $cari->keluar, $tipe->pluck('id')->all());
        $penghuni = $cari->penghuni();

        return $tipe->mapWithKeys(function (RoomType $t) use ($sisa, $cari, $penghuni) {
            $n = $sisa[$t->id] ?? 0;
            $muat = array_keys(array_filter($penghuni, fn ($orang) => $orang <= $t->max_capacity));
            $kamarDipesan = array_slice($muat, 0, $n);

            return [$t->id => [
                'jenis' => match (true) {
                    $muat === [] => 'tidak-muat',
                    $n === 0 => 'penuh',
                    count($muat) < $cari->jumlah => 'sebagian',
                    $n < $cari->jumlah => 'kurang',
                    default => 'tersedia',
                },
                'sisa' => $n,
                'muat' => count($muat),
                'dipesan' => count($kamarDipesan),
                'kamarDipesan' => $kamarDipesan,
                'total' => (float) $t->base_price * $cari->malam() * count($kamarDipesan),
            ]];
        })->all();
    }

    /**
     * Rekomendasi untuk pesanan lebih dari satu kamar: tiap kamar diberi tipe termurah yang muat dan masih tersisa,
     * dimulai dari kamar dengan penghuni terbanyak (pilihannya paling sedikit). Karena tipe yang muat untuk kamar besar
     * juga muat untuk kamar kecil, cara ini menghasilkan total termurah.
     *
     * Hasil null bila tidak perlu (satu kamar, atau satu tipe saja sudah cukup dan termurah).
     *
     * @return array{lengkap: bool, kamar: list<array{no: int, orang: int, tipe: RoomType}>, kelompok: list<array{tipe: RoomType, jumlah: int}>, total: float, kurang: int}|null
     */
    protected function saranKombinasi(Collection $tipe, array $status, PencarianInap $cari): ?array
    {
        if ($status === [] || $cari->jumlah < 2) {
            return null;
        }
        $sisa = collect($status)->map(fn ($s) => $s['sisa'])->all();
        $termurah = $tipe->sortBy(fn (RoomType $t) => [(float) $t->base_price, $t->id])->values();
        $penghuni = $cari->penghuni();
        arsort($penghuni);

        $pilihan = [];
        foreach ($penghuni as $i => $orang) {
            $t = $termurah->first(fn (RoomType $t) => $t->max_capacity >= $orang && ($sisa[$t->id] ?? 0) > 0);
            if ($t) {
                $sisa[$t->id]--;
                $pilihan[$i] = $t;
            }
        }
        ksort($pilihan);
        $kurang = $cari->jumlah - count($pilihan);
        $kelompok = collect($pilihan)->groupBy(fn (RoomType $t) => $t->id)
            ->map(fn ($g) => ['tipe' => $g->first(), 'jumlah' => $g->count()])
            ->sortBy(fn ($k) => (float) $k['tipe']->base_price)->values()->all();

        // Satu tipe saja dan tipe itu memang tersedia untuk semua kamar: kartunya sudah cukup, tidak perlu rekomendasi
        if ($kurang === 0 && count($kelompok) === 1 && ($status[$kelompok[0]['tipe']->id]['jenis'] ?? null) === 'tersedia') {
            return null;
        }

        return [
            'lengkap' => $kurang === 0,
            'kamar' => collect($pilihan)->map(fn (RoomType $t, $i) => ['no' => $i + 1, 'orang' => $cari->penghuni()[$i], 'tipe' => $t])->values()->all(),
            'kelompok' => $kelompok,
            'total' => (float) collect($pilihan)->sum(fn (RoomType $t) => (float) $t->base_price * $cari->malam()),
            'kurang' => $kurang,
        ];
    }

    /** Urutan tampil saat pencarian aktif: yang bisa dipesan lebih dulu, lalu dari harga termurah. */
    protected function urutkan(Collection $tipe, array $status): Collection
    {
        if ($status === []) {
            return $tipe;
        }
        $peringkat = ['tersedia' => 0, 'kurang' => 1, 'sebagian' => 2, 'penuh' => 3, 'tidak-muat' => 4];

        return $tipe->sortBy(fn (RoomType $t) => [$peringkat[$status[$t->id]['jenis']] ?? 9, (float) $t->base_price, $t->id])->values();
    }

    /** Ketentuan dari pengaturan sistem, ditampilkan apa adanya kepada tamu. */
    protected function ketentuan(): array
    {
        return [
            'checkIn' => str_replace(':', '.', (string) Setting::get('check_in_time', '14:00')),
            'checkOut' => str_replace(':', '.', (string) Setting::get('check_out_time', '12:00')),
            'dpPersen' => (int) Setting::get('dp_min_percent', 50),
            'dpJam' => (int) Setting::get('dp_due_hours', 24),
        ];
    }
}
