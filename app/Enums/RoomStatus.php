<?php

namespace App\Enums;

/** Status operasional kamar. Diganti hanya lewat App\Services\RoomStatusService. */
enum RoomStatus: string
{
    case Available = 'available';
    case OccupiedClean = 'occupied_clean';
    case OccupiedDirty = 'occupied_dirty';
    case VacantDirty = 'vacant_dirty';
    case Cleaning = 'cleaning';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Siap',
            self::OccupiedClean => 'Terisi',
            self::OccupiedDirty => 'Terisi, perlu dibersihkan',
            self::VacantDirty => 'Kosong, kotor',
            self::Cleaning => 'Sedang dibersihkan',
            self::Maintenance => 'Perbaikan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => '#4C8055',
            self::OccupiedClean => '#7A8699',
            self::OccupiedDirty => '#C9793A',
            self::VacantDirty => '#B8433A',
            self::Cleaning => '#5B7FA6',
            self::Maintenance => '#6C6B50',
        };
    }

    /** Kamar masuk antrean bersih (kriteria C3 Smart Cleaning memakai dirty_since). */
    public function isDirty(): bool
    {
        return in_array($this, [self::OccupiedDirty, self::VacantDirty], true);
    }

    public function isOccupied(): bool
    {
        return in_array($this, [self::OccupiedClean, self::OccupiedDirty], true);
    }

    /**
     * Perpindahan status yang diizinkan beserta peran yang boleh melakukannya.
     * Perpindahan di luar tabel ini selalu ditolak RoomStatusService. Admin tidak ikut (hanya data master).
     *
     * @return array<string, list<string>> [status tujuan => kode peran]
     */
    public function allowedTransitions(): array
    {
        $fo = 'front_office';
        $hk = 'housekeeping';

        return match ($this) {
            self::Available => [
                'occupied_clean' => [$fo],              // tamu check-in (manual)
                'vacant_dirty' => [$fo, $hk],           // perlu dibersihkan ulang
                'maintenance' => [$fo, $hk],            // ditemukan kerusakan
            ],
            self::OccupiedClean => [
                'occupied_dirty' => [$fo, $hk],         // bersih harian tamu menginap
                'vacant_dirty' => [$fo],                // tamu check-out
            ],
            self::OccupiedDirty => [
                'cleaning' => [$hk],                    // mulai dibersihkan
                'vacant_dirty' => [$fo],                // check-out sebelum dibersihkan
            ],
            self::VacantDirty => [
                'cleaning' => [$hk],
                'maintenance' => [$fo, $hk],
            ],
            self::Cleaning => [
                'available' => [$hk],                   // selesai, kamar kosong
                'occupied_clean' => [$hk],              // selesai, tamu masih menginap
            ],
            self::Maintenance => [
                'vacant_dirty' => [$fo],                // selesai diperbaiki, perlu dibersihkan
                'available' => [$fo],                   // selesai diperbaiki, sudah bersih
            ],
        };
    }

    /**
     * Boleh pindah ke $to? $roles null berarti proses sistem (hanya tabel yang diperiksa).
     * Staf yang merangkap peran mendapat gabungan haknya.
     *
     * @param  list<string>|null  $roles
     */
    public function canTransitionTo(self $to, ?array $roles = null): bool
    {
        $boleh = $this->allowedTransitions()[$to->value] ?? null;

        return $boleh !== null && ($roles === null || array_intersect($boleh, $roles) !== []);
    }

    /**
     * Status tujuan yang boleh dipilih pengguna dengan peran $roles.
     *
     * @param  list<string>  $roles
     * @return list<self>
     */
    public function transitionsFor(array $roles): array
    {
        return array_values(array_filter(self::cases(), fn (self $to) => $this->canTransitionTo($to, $roles)));
    }
}
