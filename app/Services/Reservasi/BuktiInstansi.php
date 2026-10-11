<?php

namespace App\Services\Reservasi;

use App\Models\Reservation;
use App\Support\TokenAkses;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Bukti pemesanan instansi: kode bukti = token akses reservasi (LK7Q-2M9X), tanpa kolom baru.
 * PIC meneruskan kode ini kepada staf yang ditugaskan menginap; resepsionis mengetiknya di halaman Check-in,
 * sehingga hanya orang yang menerima kode dari PIC yang bisa memakai pemesanan itu.
 */
class BuktiInstansi
{
    /** Tautan unduh PDF berlaku 90 hari, sama dengan nota. */
    public const MASA_BERLAKU_HARI = 90;

    public const STATUS_BERLAKU = ['confirmed', 'checked_in'];

    /** Reservasi instansi yang sudah disetujui (atau sedang menginap) dan punya token. */
    public static function berlaku(Reservation $r): bool
    {
        return $r->access_token && in_array($r->status, self::STATUS_BERLAKU, true) && $r->instansi();
    }

    /** PDF selalu dibuat dari data terbaru (kamar, tanggal, instansi). */
    public function isi(Reservation $r): string
    {
        $r->loadMissing('rooms.roomType', 'customer', 'institution', 'request');

        return Pdf::setOption(['isFontSubsettingEnabled' => true])
            ->loadView('pdf.bukti-instansi', ['r' => $r, 'jamCheckIn' => ReservasiService::jamCheckIn()])
            ->setPaper('a4')->output();
    }

    public function namaBerkas(Reservation $r): string
    {
        return 'Bukti-'.$r->reservation_code.'.pdf';
    }

    public function tautan(Reservation $r): string
    {
        return URL::temporarySignedRoute('bukti-instansi.unduh', now()->addDays(self::MASA_BERLAKU_HARI), ['kode' => $r->reservation_code]);
    }

    /** "5 kamar" atau "3 Deluxe, 2 Standar". */
    public static function ringkasKamar(Reservation $r): string
    {
        $kamar = $r->kamarBerlaku();
        if ($kamar->isEmpty()) {
            return ($r->request?->room_count ?? 0).' kamar';
        }

        return $kamar->countBy(fn ($k) => $k->roomType->name)->map(fn ($n, $t) => "{$n} {$t}")->join(', ');
    }

    /**
     * Cocokkan kode bukti yang diketik ("lk7q-2m9x", "LK7Q2M9X"). Bila $untuk diisi (verifikasi dari baris daftar),
     * kode harus milik reservasi itu.
     *
     * @return array{sah: bool, bisaCheckIn: bool, pesan: string, reservasi: ?Reservation}
     */
    public function verifikasi(string $kodeBukti, ?Reservation $untuk = null): array
    {
        $kode = TokenAkses::rapikan($kodeBukti);
        $r = strlen($kode) === 8 ? Reservation::where('access_token', $kode)->first() : null;

        if (! $r || ! $r->instansi()) {
            return ['sah' => false, 'bisaCheckIn' => false, 'pesan' => 'Bukti tidak dikenal. Minta PIC mengirim ulang bukti.', 'reservasi' => null];
        }
        if ($untuk && $untuk->id !== $r->id) {
            return ['sah' => false, 'bisaCheckIn' => false, 'pesan' => "Kode bukti ini bukan untuk {$untuk->reservation_code}.", 'reservasi' => null];
        }
        $r->loadMissing('rooms.roomType', 'institution', 'customer', 'request');

        if ($r->status === 'checked_in') {
            $waktu = $r->rooms->whereNotNull('actual_check_in_at')->min('actual_check_in_at');

            return ['sah' => false, 'bisaCheckIn' => false, 'reservasi' => $r,
                'pesan' => "{$r->reservation_code} sudah check-in".($waktu ? ' pada '.$waktu->translatedFormat('j M Y H.i') : '').'.'];
        }
        if ($r->status !== 'confirmed') {
            return ['sah' => false, 'bisaCheckIn' => false, 'reservasi' => $r,
                'pesan' => "Reservasi {$r->reservation_code} berstatus ".Str::lower($r->label()).'. Bukti tidak berlaku.'];
        }

        $masuk = $r->masuk();
        if ($masuk && $masuk->isAfter(today())) {
            return ['sah' => true, 'bisaCheckIn' => false, 'reservasi' => $r,
                'pesan' => 'Bukti sah, tetapi jadwal datang '.$masuk->translatedFormat('j M Y').'.'];
        }

        return ['sah' => true, 'bisaCheckIn' => true, 'reservasi' => $r,
            'pesan' => 'Bukti sah: '.collect([$r->reservation_code, $r->institution?->name, $r->kamarBerlaku()->count().' kamar',
                ReservasiService::rentang($masuk, $r->keluar())])->filter()->implode(' · ')];
    }
}
