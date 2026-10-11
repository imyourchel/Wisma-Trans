<?php

namespace App\Services\SmartCleaning;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Nilai mentah kriteria Smart Cleaning per kamar pada waktu $sekarang. Satu-satunya tempat pemetaan
 * kode kriteria ke cara menghitungnya; label kriteria diambil dari cleaning_criteria.name.
 *
 * C1 Waktu menuju check-in tamu berikutnya (cost, menit 1–1440)
 * C2 Status kamar (benefit): kosong kotor 3, terisi kotor 2
 * C3 Durasi kamar sudah kotor (benefit, menit sejak rooms.dirty_since, minimal 1)
 */
class NilaiKriteria
{
    /** Tanpa kedatangan hari ini: satu hari penuh. */
    public const C1_MAKS = 1440;

    public const C2 = ['vacant_dirty' => 3, 'occupied_dirty' => 2];

    /** Kode kriteria yang dikenal kelas ini. */
    public const KODE = ['C1', 'C2', 'C3'];

    /**
     * Kandidat: kamar aktif kosong kotor atau terisi kotor yang tidak sedang dibersihkan (tugas in_progress hari ini).
     *
     * @return Collection<int, Room>
     */
    public function kandidat(Carbon $sekarang): Collection
    {
        return Room::active()->with('roomType')
            ->whereIn('status', [RoomStatus::VacantDirty->value, RoomStatus::OccupiedDirty->value])
            ->whereNotExists(fn ($q) => $q->from('housekeeping_tasks as t')->whereColumn('t.room_id', 'rooms.id')
                ->whereDate('t.task_date', $sekarang->toDateString())->where('t.status', 'in_progress'))
            ->orderBy('room_number')->get();
    }

    /**
     * Nilai mentah dan keterangan tiap kandidat.
     *
     * @return array<int, array{room: Room, nilai: array<string, float>, keterangan: array<string, string>}> kunci id kamar
     */
    public function hitung(Carbon $sekarang, ?Collection $kandidat = null): array
    {
        $kandidat ??= $this->kandidat($sekarang);
        $c1 = $this->waktuCheckIn($sekarang);

        $hasil = [];
        foreach ($kandidat as $room) {
            [$menit, $ket] = $this->c1($room, $c1);
            $kotor = $this->c3($room, $sekarang);
            $hasil[$room->id] = [
                'room' => $room,
                'nilai' => ['C1' => (float) $menit, 'C2' => (float) self::C2[$room->status->value], 'C3' => (float) $kotor],
                'keterangan' => ['C1' => $ket, 'C2' => $room->status->label(), 'C3' => self::durasi($kotor)],
            ];
        }

        return $hasil;
    }

    /** Nilai mentah satu kriteria dari hasil hitung(); kode yang tidak dikenal ditolak supaya tidak diam-diam bernilai 0. */
    public static function nilai(string $kode, array $baris): float
    {
        if (! in_array($kode, self::KODE, true)) {
            throw new InvalidArgumentException("Kriteria {$kode} belum punya cara hitung.");
        }

        return $baris['nilai'][$kode];
    }

    /** 165 menit → "2 jam 45 menit". */
    public static function durasi(int $menit): string
    {
        $jam = intdiv($menit, 60);
        $sisa = $menit % 60;

        return match (true) {
            $jam > 0 && $sisa > 0 => "{$jam} jam {$sisa} menit",
            $jam > 0 => "{$jam} jam",
            default => "{$menit} menit",
        };
    }

    /** Jam check-in sebuah tanggal, misalnya 2026-10-06 14:00. */
    public static function jamCheckIn(string $tanggal): Carbon
    {
        return Carbon::parse($tanggal.' '.substr((string) Setting::get('check_in_time', '14:00'), 0, 5));
    }

    /** Menit dari $sekarang sampai $jam, dibatasi 1–1440 (jam yang sudah lewat = 1, tamu sudah menunggu). */
    private static function menitSampai(Carbon $sekarang, Carbon $jam): int
    {
        return (int) max(1, min(self::C1_MAKS, ceil($sekarang->diffInMinutes($jam, false))));
    }

    /**
     * Data kedatangan hari ini: kamar yang sudah ditetapkan ke tamu berikutnya, dan tipe yang kekurangan kamar Siap.
     *
     * @return array{ditetapkan: array<int, array{menit: int, kode: string, jam: string}>, kurang: array<int, array{menit: int, kurang: int, tipe: string}>}
     */
    private function waktuCheckIn(Carbon $sekarang): array
    {
        $hari = $sekarang->toDateString();
        $dasar = fn () => DB::table('reservation_rooms as rr')->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->where('rr.status', 'booked')->whereNotIn('r.status', ['cancelled', 'rejected', 'no_show'])
            ->whereDate('rr.check_in_date', '<=', $hari)->whereDate('rr.check_out_date', '>', $hari);

        // Kamar yang sudah ditetapkan ke tamu yang datang hari ini (atau terlambat datang)
        $ditetapkan = [];
        foreach ($dasar()->whereNotNull('rr.room_id')->orderBy('rr.check_in_date')->get(['rr.room_id', 'rr.check_in_date', 'r.reservation_code']) as $b) {
            if (isset($ditetapkan[$b->room_id])) {
                continue;
            }
            $jam = self::jamCheckIn(substr((string) $b->check_in_date, 0, 10));
            $ditetapkan[(int) $b->room_id] = ['menit' => self::menitSampai($sekarang, $jam), 'kode' => $b->reservation_code, 'jam' => $jam->format('H.i')];
        }

        // Tipe yang kebutuhannya (baris tanpa nomor kamar) melebihi kamar Siap yang belum ditetapkan ke tamu lain
        $kurang = [];
        $butuh = $dasar()->whereNull('rr.room_id')->groupBy('rr.room_type_id')
            ->selectRaw('rr.room_type_id, COUNT(*) n, MIN(rr.check_in_date) awal')->get();
        if ($butuh->isNotEmpty()) {
            $siap = Room::active()->where('status', RoomStatus::Available->value)->whereNotIn('id', array_keys($ditetapkan))
                ->selectRaw('room_type_id, COUNT(*) n')->groupBy('room_type_id')->pluck('n', 'room_type_id');
            $nama = RoomType::pluck('name', 'id');
            foreach ($butuh as $b) {
                $selisih = (int) $b->n - (int) ($siap[$b->room_type_id] ?? 0);
                if ($selisih > 0) {
                    $kurang[(int) $b->room_type_id] = [
                        'menit' => self::menitSampai($sekarang, self::jamCheckIn(substr((string) $b->awal, 0, 10))),
                        'kurang' => $selisih,
                        'tipe' => (string) ($nama[$b->room_type_id] ?? 'kamar'),
                    ];
                }
            }
        }

        return ['ditetapkan' => $ditetapkan, 'kurang' => $kurang];
    }

    /** @return array{0: int, 1: string} menit dan keterangan */
    private function c1(Room $room, array $data): array
    {
        if ($room->status === RoomStatus::OccupiedDirty) {
            return [self::C1_MAKS, 'Tamu masih menginap'];
        }
        if ($d = $data['ditetapkan'][$room->id] ?? null) {
            return [$d['menit'], "Tamu berikutnya {$d['kode']} check-in {$d['jam']}"];
        }
        if ($k = $data['kurang'][$room->room_type_id] ?? null) {
            return [$k['menit'], "Tipe {$k['tipe']} kurang {$k['kurang']} kamar untuk tamu hari ini"];
        }

        return [self::C1_MAKS, 'Tidak ada tamu berikutnya hari ini'];
    }

    private function c3(Room $room, Carbon $sekarang): int
    {
        if (! $room->dirty_since || $room->dirty_since->gt($sekarang)) {
            return 1;
        }

        return max(1, (int) floor($room->dirty_since->diffInMinutes($sekarang)));
    }
}
