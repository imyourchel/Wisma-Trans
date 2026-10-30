<?php

namespace App\Enums;

/** Status operasional kamar. Diganti hanya lewat App\Services\Kamar\RoomStatusService. */
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

    /**
     * Satu sumber warna status, nada kalem agar serasi dengan tema krem: utama (garis, titik, angka; ≥ 3:1 di atas latar),
     * latar (kartu), badge (latar label, sedikit lebih tua dari latar), teks (keterangan dan label; ≥ 4.5:1 di atas latar dan badge).
     *
     * @return array{utama: string, latar: string, teks: string, badge: string}
     */
    public function warna(): array
    {
        return match ($this) {
            self::Available => ['utama' => '#2E9E55', 'latar' => '#E6F5EB', 'teks' => '#1F6B3A', 'badge' => '#C8EBD3'],
            self::OccupiedClean => ['utama' => '#3D7BD9', 'latar' => '#E8F0FC', 'teks' => '#24508F', 'badge' => '#CFE0F8'],
            self::OccupiedDirty => ['utama' => '#D2711C', 'latar' => '#FCEEE0', 'teks' => '#8A4510', 'badge' => '#F8DBBF'],
            self::VacantDirty => ['utama' => '#D9534A', 'latar' => '#FCEAE8', 'teks' => '#93271F', 'badge' => '#F7D1CD'],
            self::Cleaning => ['utama' => '#8360D1', 'latar' => '#F0EBFB', 'teks' => '#55349A', 'badge' => '#DFD4F6'],
            self::Maintenance => ['utama' => '#6A7587', 'latar' => '#EDEFF2', 'teks' => '#353D4A', 'badge' => '#D9DDE4'],
        };
    }

    /** Warna utama (titik dan garis status). */
    public function color(): string
    {
        return $this->warna()['utama'];
    }

    /** Variabel CSS untuk kelas .wt-st-* di app.css: style="{{ $status->gaya() }}". */
    public function gaya(): string
    {
        $w = $this->warna();

        return "--st: {$w['utama']}; --st-bg: {$w['latar']}; --st-tx: {$w['teks']}; --st-badge: {$w['badge']};";
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
