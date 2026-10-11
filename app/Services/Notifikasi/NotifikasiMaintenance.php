<?php

namespace App\Services\Notifikasi;

use App\Models\MaintenanceRequest;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Notifications\PesanReservasi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pemberitahuan laporan kerusakan lewat WhatsApp dan email:
 * - Manager: laporan prioritas Tinggi atau yang membuat kamar tidak bisa dipakai (laporan lain cukup terlihat di Penanganan).
 * - Front Office: kamar yang tidak bisa dipakai sedang ditempati tamu, atau ditunggu tamu yang datang hari ini/besok,
 *   supaya tamunya dipindahkan ke kamar lain.
 * Pelapor tidak dikirimi pemberitahuan atas laporannya sendiri. Kegagalan kirim hanya dicatat di log.
 */
class NotifikasiMaintenance
{
    public function __construct(private WhatsAppService $wa) {}

    /** Perlu diketahui Manager saat itu juga. */
    public static function penting(MaintenanceRequest $l): bool
    {
        return $l->priority === 'high' || (bool) $l->blocks_room;
    }

    /**
     * Tamu yang terdampak kamar rusak: sedang menginap, atau dijadwalkan masuk hari ini/besok.
     */
    public static function tamuTerdampak(Room $kamar): ?ReservationRoom
    {
        return $kamar->pemesananBerjalan(['checked_in', 'booked'])
            ->first(fn (ReservationRoom $p) => $p->status === 'checked_in' || $p->check_in_date->lte(today()->addDay()));
    }

    /** @return Collection<int, User> pengguna aktif berperan $peran, selain $kecuali */
    private static function penerima(string $peran, User $kecuali): Collection
    {
        return User::where('is_active', true)->whereKeyNot($kecuali->id)
            ->whereHas('roles', fn ($q) => $q->where('code', $peran))->get();
    }

    public function teksManager(MaintenanceRequest $l, bool $foDiberiTahu, ?ReservationRoom $tamu): string
    {
        $l->loadMissing('reporter', 'unit.room', 'room');
        $teks = "Laporan kerusakan {$l->request_code} (prioritas ".MaintenanceRequest::PRIORITAS[$l->priority].'), '.Str::lower($l->labelStatus())
            ."\nLokasi: {$l->lokasi()}"
            ."\nKerusakan: ".Str::limit($l->damage_description, 300);
        if ($l->blocks_room) {
            $teks .= "\nKamar tidak bisa dipakai.";
        }
        if ($foDiberiTahu) {
            $teks .= ' Ada tamu'.($tamu ? " {$tamu->reservation->reservation_code}" : '').'; Front Office sudah diminta memindahkan kamarnya.';
        }

        return $teks."\nPelapor: {$l->reporter?->name}, ".$l->reported_at->translatedFormat('j M Y, H.i').' WITA'
            ."\n\nTangani di: ".route('staf.maintenance.index');
    }

    /** Tautan Reservasi: langsung ke reservasi tamu bila diketahui. */
    private static function urlReservasi(?ReservationRoom $tamu): string
    {
        return $tamu ? route('staf.reservasi', ['cari' => $tamu->reservation->reservation_code, 'status' => '']) : route('staf.reservasi');
    }

    /** $tamu null: kamar terisi tetapi tidak tercatat di reservasi (misalnya check-in manual dari Papan Status). */
    public function teksFrontOffice(MaintenanceRequest $l, Room $kamar, ?ReservationRoom $tamu): string
    {
        $teks = "Mohon pindahkan tamu: kamar {$kamar->room_number} tidak bisa dipakai karena kerusakan ({$l->request_code}: ".Str::limit($l->damage_description, 120).').';
        if ($tamu) {
            $tamu->loadMissing('reservation.customer');
            $keadaan = $tamu->status === 'checked_in'
                ? 'sedang menginap sampai '.$tamu->check_out_date->translatedFormat('j M Y')
                : 'dijadwalkan masuk '.($tamu->check_in_date->lte(today()) ? 'hari ini' : 'besok').' ('.$tamu->rentang().')';
            $teks .= "\nTamu: {$tamu->reservation->customer?->full_name} ({$tamu->reservation->reservation_code}), {$keadaan}.";
        } else {
            $teks .= "\nKamar sedang terisi tamu.";
        }

        return $teks."\nPindahkan ke kamar lain dengan tipe yang sama lewat menu Reservasi: ".self::urlReservasi($tamu);
    }

    /**
     * Kirim pemberitahuan untuk laporan baru.
     *
     * @return string|null kalimat untuk pelapor tentang pemindahan tamu (null bila tidak ada tamu yang perlu dipindah)
     */
    public function laporanBaru(MaintenanceRequest $l, User $pelapor): ?string
    {
        $kamar = $l->kamar();
        $tamu = $l->blocks_room && $kamar ? self::tamuTerdampak($kamar) : null;
        $perluPindah = $l->blocks_room && $kamar && ($tamu || $kamar->fresh()->status->isOccupied());
        $foDiberiTahu = false;

        if ($perluPindah) {
            $teks = $this->teksFrontOffice($l, $kamar, $tamu);
            foreach (self::penerima('front_office', $pelapor) as $fo) {
                $this->kirim($fo, $teks, "Pindahkan tamu dari kamar {$kamar->room_number} ({$l->request_code})", 'Buka Reservasi', self::urlReservasi($tamu));
                $foDiberiTahu = true;
            }
        }

        if (self::penting($l)) {
            $teks = $this->teksManager($l, $foDiberiTahu, $tamu);
            foreach (self::penerima('manager', $pelapor) as $manager) {
                $this->kirim($manager, $teks, "Laporan kerusakan {$l->request_code}: {$l->lokasi()}", 'Buka Penanganan', route('staf.maintenance.index'));
            }
        }

        return match (true) {
            $foDiberiTahu => 'Front Office sudah diberi tahu lewat WhatsApp dan email untuk memindahkan tamu.',
            // Ada tamu, tetapi tidak ada akun FO lain yang bisa dikirimi
            $perluPindah => 'Koordinasikan pindah kamar dengan Front Office.',
            default => null,
        };
    }

    /** WhatsApp dan email; baris email mengikuti baris teks WhatsApp (tautan diganti tombol). */
    private function kirim(User $ke, string $teks, string $subjek, string $tombol, string $url): void
    {
        try {
            $this->wa->kirim($ke->phone, $teks);
        } catch (Throwable $e) {
            Log::warning('Pemberitahuan maintenance via WhatsApp gagal', ['user' => $ke->id, 'pesan' => $e->getMessage()]);
        }
        try {
            $baris = collect(explode("\n", $teks))->filter()->reject(fn ($b) => str_contains($b, 'http'))->values()->all();
            $ke->notify(new PesanReservasi($subjek, 'Halo '.Str::before($ke->name, ' ').',', $baris, $tombol, $url));
        } catch (Throwable $e) {
            Log::warning('Pemberitahuan maintenance via email gagal', ['user' => $ke->id, 'pesan' => $e->getMessage()]);
        }
    }
}
