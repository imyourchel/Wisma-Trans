<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat mengganti status kamar (Papan Status, Check-in, Smart Cleaning, dan seterusnya).
 * Jangan memakai $room->update(['status' => ...]) langsung.
 */
class RoomStatusService
{
    /**
     * @param  User|null  $by  pengubah; bila kosong memakai pengguna yang sedang masuk.
     *                         Tanpa pengguna sama sekali dianggap proses sistem: hanya tabel perpindahan yang diperiksa.
     */
    public function change(Room $room, RoomStatus $newStatus, ?string $note = null, ?User $by = null): void
    {
        $note = filled($note) ? trim($note) : null;
        $by ??= auth()->user();

        DB::transaction(function () use ($room, $newStatus, $note, $by) {
            // Kunci baris supaya dua staf yang menyimpan bersamaan tidak saling menimpa
            $kamar = Room::whereKey($room->getKey())->lockForUpdate()->firstOrFail();
            $lama = $kamar->status;

            if ($lama === $newStatus) {
                return;
            }

            if (! $kamar->is_active) {
                throw ValidationException::withMessages(['status' => "Kamar {$kamar->room_number} nonaktif, statusnya tidak bisa diganti."]);
            }

            $peran = $by?->loadMissing('roles')->roles->pluck('code')->all();
            if (! $lama->canTransitionTo($newStatus, $peran)) {
                throw ValidationException::withMessages(['status' => "Perubahan status dari {$lama->label()} ke {$newStatus->label()} tidak diizinkan."]);
            }

            if ($newStatus === RoomStatus::Maintenance && $note === null) {
                throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk status Perbaikan.']);
            }

            $kamar->status = $newStatus;
            if (! $newStatus->isDirty()) {
                $kamar->dirty_since = null;
            } elseif (! $kamar->dirty_since) {
                // Dari occupied_dirty ke vacant_dirty waktu lama dipertahankan (kriteria C3 Smart Cleaning)
                $kamar->dirty_since = now();
            }
            $kamar->save();

            RoomStatusLog::create([
                'room_id' => $kamar->id,
                'status' => $newStatus,
                'changed_by' => $by?->id,
                'changed_at' => now(),
                'note' => $note,
            ]);

            $room->setRawAttributes($kamar->getAttributes(), true);
        });
    }

    /**
     * Saran tujuan untuk kamar yang sedang dibersihkan, dari status kotor terakhir di riwayat:
     * sebelumnya terisi → Terisi; sebelumnya kosong → Siap.
     */
    public function suggestedTarget(Room $room): ?RoomStatus
    {
        if ($room->status !== RoomStatus::Cleaning) {
            return null;
        }

        $kotor = $room->statusLogs()
            ->whereIn('status', [RoomStatus::OccupiedDirty->value, RoomStatus::VacantDirty->value])
            ->orderByDesc('changed_at')->orderByDesc('id')
            ->first()?->status;

        return match ($kotor) {
            RoomStatus::OccupiedDirty => RoomStatus::OccupiedClean,
            RoomStatus::VacantDirty => RoomStatus::Available,
            default => null,
        };
    }
}
