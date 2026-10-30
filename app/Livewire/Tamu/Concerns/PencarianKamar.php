<?php

namespace App\Livewire\Tamu\Concerns;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Reservasi\TambahanKamar;
use App\Support\PencarianInap;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Tanggal menginap dan tamu yang dibawa di URL (?masuk=&keluar=&tamu=), dipakai halaman daftar kamar, detail tipe kamar,
 * dan pemesan. Di daftar dan detail, tamu berupa total dewasa-anak ("4-2"); di halaman pemesan berupa susunan per kamar
 * ("2-2_2-0"). Format dijelaskan di PencarianInap.
 */
trait PencarianKamar
{
    #[Url(except: '')]
    public string $masuk = '';

    #[Url(except: '')]
    public string $keluar = '';

    #[Url(except: '')]
    public string $tamu = '';

    /** Simpanan sementara dalam satu permintaan (properti privat tidak dibawa Livewire antar permintaan). */
    private ?Collection $memoTipe = null;

    private array $memoSisa = [];

    private ?int $memoExtra = null;

    /** Dipanggil tombol Cek Ketersediaan; daftar diperbarui tanpa pindah halaman. */
    public function cek(string $masuk, string $keluar, string $tamu): void
    {
        $this->masuk = $masuk;
        $this->keluar = $keluar;
        $this->tamu = PencarianInap::teks(PencarianInap::susunan($tamu));
        if (method_exists($this, 'setelahCek')) {
            $this->setelahCek();
        }
    }

    protected function pencarian(): PencarianInap
    {
        return PencarianInap::dari($this->masuk, $this->keluar, $this->tamu);
    }

    /**
     * Tipe yang punya kamar aktif, dari harga termurah. Jenis kasur diambil dari kamar-kamar aktifnya, urut Room::BED_TYPES:
     * kodeKasur = kode (twin_single, double), kasur = label (Twin Single, Double Bed),
     * kapasitas = dewasa dan anak terbesar dari jenis kasurnya (RoomType::kapasitasTerbesar), misalnya Family 4 dewasa + 2 anak.
     */
    protected function tipeKamar(): Collection
    {
        return $this->memoTipe ??= RoomType::query()
            ->whereHas('rooms', fn ($q) => $q->where('is_active', true))
            ->with(['facilities' => fn ($q) => $q->orderBy('facilities.id'), 'rooms' => fn ($q) => $q->where('is_active', true)->select('id', 'room_type_id', 'bed_type')])
            ->orderBy('base_price')->orderBy('id')
            ->get()
            ->each(function (RoomType $t) {
                $kode = $t->rooms->pluck('bed_type')->unique()->sortBy(fn ($b) => array_search($b, array_keys(Room::BED_TYPES), true))->values();
                $t->setAttribute('kodeKasur', $kode->all());
                $t->setAttribute('kasur', $kode->map(fn ($b) => Room::labelKasur($b))->all());
                $t->setAttribute('kapasitas', $t->kapasitasTerbesar($kode->all()));
            });
    }

    /** Susunan tamu per kamar dari pencarian ("2-2_2-0"), dipakai sebagai pembagian awal dan isi otomatis. */
    protected function susunanTamu(): array
    {
        return PencarianInap::susunan($this->tamu);
    }

    /** Total dewasa dan anak pencarian (semua kamar dijumlahkan, PencarianInap::total). */
    protected function jumlahTamu(): array
    {
        return PencarianInap::total($this->tamu);
    }

    /** Extra bed yang bisa ditambahkan per kamar saat ini (0 bila layanannya tidak ditawarkan). */
    protected function extraTersedia(): int
    {
        return $this->memoExtra ??= app(TambahanKamar::class)->extraTersedia();
    }

    /**
     * Kecocokan satu jenis kasur dengan penghuni kamar-kamar di pencarian (RoomType::kecocokan): yang terbaik dari semua
     * kamar, jadi jenis kasur dianggap muat bila muat untuk minimal satu kamar.
     */
    protected function cocokKasur(RoomType $tipe, string $kasur): string
    {
        return collect($this->susunanTamu())
            ->map(fn ($s) => $tipe->kecocokan($kasur, $s['dewasa'], $s['anak'], $this->extraTersedia()))
            ->sortBy(fn ($c) => RoomType::URUT_KECOCOKAN[$c])->first() ?? RoomType::TIDAK_MUAT;
    }

    /** Kecocokan satu tipe: yang terbaik dari jenis kasurnya (cocokKasur). */
    protected function cocokTipe(RoomType $tipe): string
    {
        return collect($tipe->kodeKasur)->map(fn ($b) => $this->cocokKasur($tipe, $b))
            ->sortBy(fn ($c) => RoomType::URUT_KECOCOKAN[$c])->first() ?? RoomType::TIDAK_MUAT;
    }

    /**
     * Kalimat saran bila ada kamar di pencarian yang hanya muat di sebagian tipe (kamar dengan penghuni terbanyak dulu):
     * "Untuk 4 dewasa + 2 anak dalam satu kamar, pilih Family Room. Atau tambah jumlah kamar." Null bila semua tipe muat.
     */
    protected function saranTipe(): ?string
    {
        $tipe = $this->tipeKamar();
        foreach (collect($this->susunanTamu())->sortByDesc(fn ($s) => [$s['dewasa'], $s['anak']]) as $s) {
            $muat = $tipe->filter(fn ($t) => collect($t->kodeKasur)
                ->contains(fn ($b) => $t->kecocokan($b, $s['dewasa'], $s['anak'], $this->extraTersedia()) !== RoomType::TIDAK_MUAT));
            $teks = RoomType::teksBatas($s).' dalam satu kamar';
            if ($muat->isEmpty()) {
                return "Belum ada kamar yang muat untuk {$teks}. Silakan tambah jumlah kamar.";
            }
            if ($muat->count() < $tipe->count()) {
                return "Untuk {$teks}, pilih ".$muat->pluck('name')->join(', ', ' atau ').'. Atau tambah jumlah kamar.';
            }
        }

        return null;
    }

    /**
     * Sisa kamar untuk tanggal pencarian: per tipe (KetersediaanKamar::sisa) dan per jenis kasur (sisaKasur).
     * Null bila tanggal belum dipilih atau tidak sah.
     *
     * @return array{tipe: array<int, int>, kasur: array<int, array<string, int>>}|null
     */
    protected function sisaKamar(Collection $tipe, PencarianInap $cari): ?array
    {
        if (! $cari->aktif() || $tipe->isEmpty()) {
            return null;
        }
        $id = $tipe->pluck('id')->sort()->values()->all();
        $kunci = $cari->masuk->toDateString().'|'.$cari->keluar->toDateString().'|'.implode(',', $id);
        if (! isset($this->memoSisa[$kunci])) {
            $k = app(KetersediaanKamar::class);
            $this->memoSisa[$kunci] = ['tipe' => $k->sisa($cari->masuk, $cari->keluar, $id), 'kasur' => $k->sisaKasur($cari->masuk, $cari->keluar, $id)];
        }

        return $this->memoSisa[$kunci];
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
