<?php

namespace App\Services\SmartCleaning;

use App\Enums\RoomStatus;
use App\Models\HousekeepingTask;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pelaksanaan tugas oleh staf Housekeeping: mulai (kamar → Sedang dibersihkan) dan selesai (kamar → Siap,
 * atau Terisi bila tamu masih menginap). Status kamar selalu lewat RoomStatusService atas nama staf HK.
 * sinkron() menyesuaikan tugas bila status kamar diubah dari tempat lain (Papan Status, laporan kerusakan).
 */
class TugasHousekeepingService
{
    public const CATATAN_TIDAK_DIKERJAKAN = 'Dibatalkan: tidak dikerjakan pada tanggalnya';

    public function __construct(private RoomStatusService $statusKamar) {}

    public function mulai(HousekeepingTask $t, User $hk): void
    {
        DB::transaction(function () use ($t, $hk) {
            $t = HousekeepingTask::with('room')->lockForUpdate()->findOrFail($t->id);
            $this->pastikanPenerima($t, $hk);
            if ($t->status !== 'assigned') {
                throw ValidationException::withMessages(['status' => 'Tugas ini sudah dimulai atau selesai.']);
            }
            $lain = HousekeepingTask::with('room')->where('status', 'in_progress')->whereKeyNot($t->id)
                ->whereHas('assignees', fn ($q) => $q->where('user_id', $hk->id))->first();
            if ($lain) {
                throw ValidationException::withMessages(['status' => "Selesaikan dulu kamar {$lain->room->room_number} sebelum memulai kamar lain."]);
            }

            $room = $t->room->fresh();
            if (! $room->status->isDirty()) {
                throw ValidationException::withMessages(['status' => "Kamar {$room->room_number} berstatus {$room->status->label()}, tidak bisa mulai dibersihkan."]);
            }

            $this->statusKamar->change($room, RoomStatus::Cleaning, null, $hk);
            $t->update(['status' => 'in_progress', 'started_at' => now()]);
        });
    }

    /**
     * @return ?string peringatan bila kamar ditahan menjadi Perbaikan karena laporan kerusakan yang belum selesai
     */
    public function selesai(HousekeepingTask $t, User $hk, ?string $catatan = null): ?string
    {
        return DB::transaction(function () use ($t, $hk, $catatan) {
            $t = HousekeepingTask::with('room')->lockForUpdate()->findOrFail($t->id);
            $this->pastikanPenerima($t, $hk);
            if ($t->status !== 'in_progress') {
                throw ValidationException::withMessages(['status' => 'Tugas ini belum dimulai atau sudah selesai.']);
            }

            $room = $t->room->fresh();
            $pesan = null;
            if ($room->status === RoomStatus::Cleaning) {
                $tujuan = self::tamuMenginap($room) ? RoomStatus::OccupiedClean : RoomStatus::Available;
                $catatan = filled($catatan) ? trim($catatan) : null;
                $pesan = $this->statusKamar->change($room, $tujuan, $catatan, $hk);
            }
            $t->update(['status' => 'completed', 'completed_at' => now(), 'note' => filled($catatan) ? mb_substr(trim($catatan), 0, 255) : $t->note]);

            return $pesan;
        });
    }

    /** Ada tamu yang sedang menginap di kamar ini. */
    public static function tamuMenginap(Room $room): bool
    {
        return ReservationRoom::where('room_id', $room->id)->where('status', 'checked_in')->exists();
    }

    /**
     * Status kamar diubah di luar Tugas Saya (Papan Status, laporan kerusakan, pindah kamar): tugas terbuka hari ini,
     * dan tugas in_progress dari hari sebelumnya, ikut disesuaikan. Jangan dipanggil dari dalam kelas ini supaya tidak berulang.
     */
    public function sinkron(Room $room, RoomStatus $baru, User $oleh): void
    {
        $hari = today()->toDateString();
        $tugas = HousekeepingTask::where('room_id', $room->id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereDate('task_date', $hari)->whereIn('status', HousekeepingTask::TERBUKA))
                ->orWhere(fn ($q) => $q->whereDate('task_date', '<', $hari)->where('status', 'in_progress')))
            ->orderBy('id')->get();

        match ($baru) {
            // Tamu keluar dari kamar yang terisi kotor: tugas yang belum dimulai menjadi pembersihan penuh
            RoomStatus::VacantDirty => $tugas->where('status', 'assigned')->each(fn ($t) => $this->ubahPoin($t, PembagianTugasService::poin($baru))),
            RoomStatus::Cleaning => $tugas->where('status', 'assigned')->first()?->update(['status' => 'in_progress', 'started_at' => now()]),
            RoomStatus::Available, RoomStatus::OccupiedClean => $tugas->where('status', 'in_progress')
                ->each(fn ($t) => $t->update(['status' => 'completed', 'completed_at' => now()])),
            RoomStatus::Maintenance => $tugas->each(fn ($t) => $t->status === 'assigned'
                ? $t->update(['status' => 'cancelled', 'note' => 'Dibatalkan: kamar masuk Perbaikan'])
                // Selesai dibersihkan tetapi kamar ditahan Perbaikan karena laporan kerusakan
                : $t->update(['status' => 'completed', 'completed_at' => now(), 'note' => 'Selesai dibersihkan; kamar ditahan Perbaikan'])),
            default => null,
        };
    }

    /** Poin beban tugas berubah; bagian tiap penerima ikut, dibagi rata bila dikerjakan bersama. */
    private function ubahPoin(HousekeepingTask $t, float $poin): void
    {
        if (abs((float) $t->workload_point - $poin) < 1e-9) {
            return;
        }
        $t->update(['workload_point' => $poin]);
        $penerima = $t->assignees()->get();
        $penerima->each(fn ($a) => $a->update(['workload_share' => round($poin / $penerima->count(), 2)]));
    }

    /**
     * Tugas hari sebelumnya yang belum dimulai dibatalkan (perintah terjadwal harian). Kamar yang masih kotor
     * ikut lagi di perhitungan prioritas berikutnya. Tugas in_progress dibiarkan supaya Manager menindaklanjuti.
     *
     * @return int jumlah tugas yang dibatalkan
     */
    public function batalkanTugasLama(?Carbon $hariIni = null): int
    {
        return HousekeepingTask::where('status', 'assigned')
            ->whereDate('task_date', '<', ($hariIni ?? today())->toDateString())
            ->update(['status' => 'cancelled', 'note' => self::CATATAN_TIDAK_DIKERJAKAN]);
    }

    private function pastikanPenerima(HousekeepingTask $t, User $hk): void
    {
        if (! $t->dikerjakanOleh($hk)) {
            throw ValidationException::withMessages(['status' => 'Tugas ini bukan untuk Anda.']);
        }
    }
}
