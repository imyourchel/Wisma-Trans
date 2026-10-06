<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat mengganti status kamar (Papan Status, Check-in, Smart Cleaning, dan seterusnya).
 * Jangan memakai $room->update(['status' => ...]) langsung.
 */
class RoomStatusService
{
    /** Proses sistem yang boleh mengganti status tanpa cek peran pengubah; tabel perpindahan tetap berlaku. */
    public const SUMBER_SISTEM = ['maintenance'];

    /**
     * @param  User|null  $by  pengubah; bila kosong memakai pengguna yang sedang masuk.
     *                         Tanpa pengguna sama sekali dianggap proses sistem: hanya tabel perpindahan yang diperiksa.
     * @param  string|null  $source  'maintenance': dipanggil dari laporan kerusakan atau penanganannya. Cek peran dilewati
     *                               (pelapor bisa F&B, penanganan oleh Manager), tetapi tabel perpindahan, catatan wajib,
     *                               dan dirty_since tetap berlaku, dan log tetap mencatat $by.
     * @return string|null pesan bila kamar tidak menjadi Siap karena masih ada laporan kerusakan penahan (blocks_room):
     *                     kamar langsung ditandai Perbaikan dengan sumber 'maintenance'
     */
    public function change(Room $room, RoomStatus $newStatus, ?string $note = null, ?User $by = null, ?string $source = null): ?string
    {
        $note = filled($note) ? trim($note) : null;
        $by ??= Auth::user();
        $tanpaCekPeran = in_array($source, self::SUMBER_SISTEM, true);
        $pesan = null;

        DB::transaction(function () use ($room, &$newStatus, &$note, $by, $tanpaCekPeran, &$pesan) {
            // Kunci baris supaya dua staf yang menyimpan bersamaan tidak saling menimpa
            $kamar = Room::whereKey($room->getKey())->lockForUpdate()->firstOrFail();
            $lama = $kamar->status;

            if ($lama === $newStatus) {
                return;
            }

            if (! $kamar->is_active) {
                throw ValidationException::withMessages(['status' => "Kamar {$kamar->room_number} nonaktif, statusnya tidak bisa diganti."]);
            }

            $peran = $tanpaCekPeran ? null : $by?->loadMissing('roles')->roles->pluck('code')->all();
            if (! $lama->canTransitionTo($newStatus, $peran)) {
                throw ValidationException::withMessages(['status' => "Perubahan status dari {$lama->label()} ke {$newStatus->label()} tidak diizinkan."]);
            }

            // Kamar dengan kerusakan penahan yang belum selesai tidak boleh dijual lagi, misalnya AC dilaporkan saat
            // tamu menginap, lalu kamar dibersihkan setelah check-out. Perpindahan ke Siap sudah lolos pemeriksaan di atas;
            // tujuannya diganti Perbaikan oleh sistem (tidak lewat tabel, yang tidak memuat Sedang dibersihkan → Perbaikan).
            if ($newStatus === RoomStatus::Available && ($menahan = $kamar->laporanMenahan())->isNotEmpty()) {
                $daftar = $menahan->map(fn ($l) => $l->request_code.' ('.Str::limit($l->damage_description, 40).')')->implode(', ');
                if ($lama === RoomStatus::Maintenance) {
                    throw ValidationException::withMessages(['status' => "Kamar {$kamar->room_number} belum bisa dibuka karena masih ada laporan {$daftar} yang belum selesai. Tutup laporannya dari Penanganan Maintenance."]);
                }
                $newStatus = RoomStatus::Maintenance;
                $note = $menahan->pluck('request_code')->implode(', ').': ditahan karena kerusakan belum selesai';
                $pesan = "Kamar {$kamar->room_number} langsung ditandai Perbaikan karena masih ada laporan {$daftar} yang belum selesai.";
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

        return $pesan;
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
