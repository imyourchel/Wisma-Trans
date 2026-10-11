<?php

namespace App\Services\Maintenance;

use App\Enums\RoomStatus;
use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceSchedule;
use App\Models\Room;
use App\Models\User;
use App\Services\Kamar\RoomStatusService;
use App\Services\SmartCleaning\TugasHousekeepingService;
use App\Support\Nomor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Modul 7 Maintenance: laporan kerusakan, penanganannya, dan perawatan rutin.
 * Status kamar hanya diganti lewat RoomStatusService dengan sumber 'maintenance' dan catatan berisi kode MNT.
 */
class MaintenanceService
{
    public function __construct(private RoomStatusService $statusKamar) {}

    /** Staf internal yang bisa ditugasi memperbaiki: akun aktif dengan peran operasional. */
    public static function stafPengerja()
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('code', ['manager', 'front_office', 'fnb', 'housekeeping']))
            ->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Laporan kerusakan baru. $unit null berarti "Lainnya / tidak ada di daftar": untuk kamar, $kamar diisi.
     * Bila $tidakBisaDipakai dan kamar kosong (siap atau kotor), kamar ditutup (maintenance). Kamar terisi tidak diubah.
     *
     * @param  list<string>  $foto  path berkas di disk public
     * @return array{0: MaintenanceRequest, 1: ?string} laporan dan peringatan (bila status kamar tidak diubah)
     */
    public function lapor(User $oleh, ?FacilityUnit $unit, ?Room $kamar, ?string $area, string $deskripsi, string $prioritas, bool $tidakBisaDipakai, array $foto = []): array
    {
        return DB::transaction(function () use ($oleh, $unit, $kamar, $area, $deskripsi, $prioritas, $tidakBisaDipakai, $foto) {
            $deskripsi = trim($deskripsi);
            // Lainnya di area umum: nama area ikut dicatat supaya lokasinya tidak hilang
            if (! $unit && ! $kamar && $area) {
                $deskripsi = "Area {$area}: {$deskripsi}";
            }

            $laporan = MaintenanceRequest::create([
                'request_code' => Nomor::maintenance(),
                'maintenance_type' => 'corrective',
                'facility_unit_id' => $unit?->id,
                'room_id' => $unit ? null : $kamar?->id,
                'damage_description' => $deskripsi,
                'photos' => $foto ?: null,
                'priority' => $prioritas,
                // Dicatat juga bila kamar sudah Perbaikan atau terisi, supaya kamar ditahan sampai kerusakan ini selesai
                ...(MaintenanceRequest::adaKolomMenahan() ? ['blocks_room' => $tidakBisaDipakai && ($unit?->room ?? $kamar) !== null] : []),
                'reported_by' => $oleh->id,
                'reported_at' => now(),
                'status' => 'reported',
            ]);

            $peringatan = null;
            $kamarTerkait = $unit?->room ?? $kamar;
            if ($tidakBisaDipakai && $kamarTerkait) {
                $peringatan = $this->tutupKamar($laporan, $kamarTerkait, $oleh);
            }

            return [$laporan, $peringatan];
        });
    }

    /**
     * Kamar kosong ditutup untuk perbaikan; kamar terisi atau sedang dibersihkan dibiarkan dengan peringatan.
     * Kamar yang ditunggu tamu hari ini atau besok tetap ditutup, dengan peringatan supaya FO memindahkan tamunya.
     */
    private function tutupKamar(MaintenanceRequest $laporan, Room $kamar, User $oleh): ?string
    {
        $kamar->refresh();
        if (in_array($kamar->status, [RoomStatus::Available, RoomStatus::VacantDirty], true)) {
            $this->statusKamar->change($kamar, RoomStatus::Maintenance, $laporan->request_code.': '.Str::limit($laporan->damage_description, 120), $oleh, 'maintenance');
            // Tugas pembersihan kamar ini yang belum dimulai dibatalkan
            app(TugasHousekeepingService::class)->sinkron($kamar, RoomStatus::Maintenance, $oleh);

            $tamu = $kamar->pemesananBerjalan(['booked'])
                ->first(fn ($p) => $p->check_in_date->lte(today()->addDay()));

            return $tamu
                ? "Kamar {$kamar->room_number} dijadwalkan untuk tamu {$tamu->reservation->reservation_code} "
                    .($tamu->check_in_date->lte(today()) ? 'hari ini' : 'besok').'.'
                : null;
        }

        return match ($kamar->status) {
            RoomStatus::Maintenance => null,
            RoomStatus::OccupiedClean, RoomStatus::OccupiedDirty => 'Kamar sedang terisi. Status kamar tidak diubah.',
            // Saat ditandai selesai dibersihkan, RoomStatusService menahannya menjadi Perbaikan (laporan ini blocks_room)
            default => "Kamar {$kamar->room_number} sedang dibersihkan. Setelah selesai dibersihkan, kamar langsung ditandai Perbaikan.",
        };
    }

    /** reported → in_progress, dikerjakan staf internal ($staf) atau vendor luar ($vendor). */
    public function kerjakan(MaintenanceRequest $laporan, ?User $staf, ?string $vendor): void
    {
        $this->pastikanStatus($laporan, ['reported'], 'Hanya laporan yang belum dikerjakan yang bisa mulai dikerjakan.');
        $vendor = filled($vendor) ? trim($vendor) : null;
        if (($staf === null) === ($vendor === null)) {
            throw ValidationException::withMessages(['pengerja' => 'Pilih staf internal atau isi nama vendor.']);
        }

        $laporan->update([
            'status' => 'in_progress',
            'handled_by' => $staf?->id,
            'vendor_name' => $vendor,
            'started_at' => now(),
        ]);
    }

    /**
     * Kode laporan lain (baru atau sedang dikerjakan) di kamar yang sama, lewat room_id maupun unit di kamar itu.
     * $menahan true: hanya laporan yang membuat kamar tidak bisa dipakai (blocks_room), dipakai untuk menahan kamar;
     * false: hanya laporan yang tidak menahan kamar, untuk informasi.
     *
     * @return list<string>
     */
    public function laporanTerbukaLain(MaintenanceRequest $laporan, ?bool $menahan = null): array
    {
        $kamar = $laporan->kamar();
        if (! $kamar || ($menahan === false && ! MaintenanceRequest::adaKolomMenahan())) {
            return [];
        }

        return $kamar->maintenanceRequests()->whereIn('status', ['reported', 'in_progress'])->whereKeyNot($laporan->id)
            ->when($menahan !== null && MaintenanceRequest::adaKolomMenahan(), fn ($q) => $q->where('blocks_room', $menahan))
            ->orderBy('reported_at')->orderBy('id')->pluck('request_code')->all();
    }

    /** "Kamar 105 tetap Perbaikan karena masih ada laporan MNT-0008 yang belum selesai." */
    public static function pesanTetapPerbaikan(Room $kamar, array $kode): string
    {
        return "Kamar {$kamar->room_number} tetap Perbaikan karena masih ada laporan ".implode(', ', $kode).' yang belum selesai.';
    }

    /**
     * in_progress → completed. Bila kamar terkait sedang maintenance, $statusKamar wajib: vacant_dirty (perlu dibersihkan)
     * atau available (siap dipakai). Bila masih ada laporan lain yang membuat kamar tidak bisa dipakai, kamar tetap Perbaikan;
     * laporan kecil (tanpa centang "tidak bisa dipakai") tidak menahan kamar.
     *
     * @return ?string peringatan bila kamar tetap Perbaikan
     */
    public function selesaikan(MaintenanceRequest $laporan, string $catatan, ?float $biaya, ?string $statusKamar, User $oleh): ?string
    {
        $this->pastikanStatus($laporan, ['in_progress'], 'Hanya laporan yang sedang dikerjakan yang bisa diselesaikan.');
        if ($biaya !== null && $biaya < 0) {
            throw ValidationException::withMessages(['cost' => 'Biaya tidak boleh negatif.']);
        }
        $kamar = $laporan->kamar();
        $tujuan = null;
        $lain = $this->laporanTerbukaLain($laporan, menahan: true);
        $tetap = $kamar && $lain && $kamar->fresh()->status === RoomStatus::Maintenance;
        if ($kamar && ! $tetap && $kamar->fresh()->status === RoomStatus::Maintenance) {
            $tujuan = RoomStatus::tryFrom((string) $statusKamar);
            if (! in_array($tujuan, [RoomStatus::VacantDirty, RoomStatus::Available], true)) {
                throw ValidationException::withMessages(['status_kamar' => 'Pilih status kamar setelah perbaikan.']);
            }
        }

        DB::transaction(function () use ($laporan, $catatan, $biaya, $kamar, $tujuan, $oleh) {
            $laporan->update([
                'status' => 'completed',
                'completed_at' => now(),
                'cost' => $biaya,
                'resolution_note' => trim($catatan),
            ]);
            if ($tujuan) {
                $this->statusKamar->change($kamar, $tujuan, $laporan->request_code.' selesai: '.Str::limit(trim($catatan), 120), $oleh, 'maintenance');
            }
        });

        return $tetap ? self::pesanTetapPerbaikan($kamar, $lain) : null;
    }

    /**
     * reported/in_progress → cancelled. Kamar yang ditutup oleh laporan kerusakan kamar itu dibuka kembali (available),
     * kecuali masih ada laporan lain yang membuat kamar itu tidak bisa dipakai.
     *
     * @return ?string peringatan bila kamar tetap Perbaikan
     */
    public function batalkan(MaintenanceRequest $laporan, string $alasan, User $oleh): ?string
    {
        $this->pastikanStatus($laporan, ['reported', 'in_progress'], 'Laporan yang sudah selesai atau dibatalkan tidak bisa diubah.');

        return DB::transaction(function () use ($laporan, $alasan, $oleh) {
            $laporan->update(['status' => 'cancelled', 'resolution_note' => trim($alasan)]);
            $kamar = $laporan->kamar();
            if (! $kamar || $kamar->fresh()->status !== RoomStatus::Maintenance) {
                return null;
            }
            if ($lain = $this->laporanTerbukaLain($laporan, menahan: true)) {
                return self::pesanTetapPerbaikan($kamar, $lain);
            }
            if ($this->kamarDitutupLaporan($kamar)) {
                $this->statusKamar->change($kamar, RoomStatus::Available, $laporan->request_code.' dibatalkan: '.Str::limit(trim($alasan), 120), $oleh, 'maintenance');
            }

            return null;
        });
    }

    /**
     * Kamar ditutup oleh salah satu laporan kerusakan kamar itu sendiri (bukan ditutup manual dari Papan Status).
     * Catatan dari laporan berawalan "MNT-0006: ..."; data lama ditulis manual, misalnya "AC tidak dingin, lihat MNT-0003".
     * Contoh: MNT-0007 menutup kamar, MNT-0008 menyusul; MNT-0007 selesai lebih dulu, lalu MNT-0008 dibatalkan → kamar dibuka.
     */
    private function kamarDitutupLaporan(Room $kamar): bool
    {
        $log = $kamar->statusLogs()->where('status', RoomStatus::Maintenance->value)->orderByDesc('changed_at')->orderByDesc('id')->first();

        return $log && preg_match_all('/MNT-\d+/', (string) $log->note, $kode)
            && $kamar->maintenanceRequests()->whereIn('request_code', $kode[0])->exists();
    }

    /**
     * Persetujuan Manager untuk jalur cepat, seperti otorisasi pembatalan di kasir: email dan kata sandi Manager diketik
     * di perangkat peminta. Sesi peminta tidak berganti; kredensial hanya dicocokkan. Batas 5 percobaan per menit per peminta.
     */
    public function verifikasiManager(string $email, string $sandi, User $peminta): User
    {
        $kunci = 'jalur-cepat:'.$peminta->id;
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            throw ValidationException::withMessages(['sandi_manager' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.']);
        }
        RateLimiter::hit($kunci, 60);

        $manager = User::where('email', Str::lower(trim($email)))->where('is_active', true)->first();
        if (! $manager || ! $manager->password || ! Hash::check($sandi, $manager->password)) {
            throw ValidationException::withMessages(['sandi_manager' => 'Email atau kata sandi Manager salah.']);
        }
        if (! $manager->hasRole('manager')) {
            throw ValidationException::withMessages(['sandi_manager' => 'Akun ini bukan Manager.']);
        }
        RateLimiter::clear($kunci);

        return $manager;
    }

    /**
     * Jalur cepat: kamar Perbaikan dibuka (Siap) dengan menutup semua laporan penahannya (blocks_room) yang masih terbuka,
     * atas persetujuan $manager (handled_by); vendor yang sedang mengerjakan tetap tercatat. Biaya dilengkapi Manager nanti (Lengkapi). Laporan yang tidak menahan kamar
     * dibiarkan terbuka. Nama peminta hanya dicatat di log status kamar.
     *
     * @return string pesan sukses
     */
    public function selesaikanCepat(Room $room, User $manager, User $peminta): string
    {
        return DB::transaction(function () use ($room, $manager, $peminta) {
            $kamar = Room::whereKey($room->getKey())->lockForUpdate()->firstOrFail();
            if ($kamar->status !== RoomStatus::Maintenance) {
                throw ValidationException::withMessages(['sandi_manager' => 'Jalur cepat hanya untuk kamar berstatus Perbaikan.']);
            }

            $laporan = $kamar->maintenanceRequests()->whereIn('status', ['reported', 'in_progress'])
                ->when(MaintenanceRequest::adaKolomMenahan(), fn ($q) => $q->where('blocks_room', true))
                ->orderBy('reported_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($laporan as $l) {
                $l->update([
                    'status' => 'completed',
                    'handled_by' => $manager->id,
                    // Vendor yang sedang mengerjakan tetap tercatat; Manager tercatat sebagai penanggung jawab
                    'vendor_name' => $l->vendor_name,
                    'started_at' => $l->started_at ?? now(),
                    'completed_at' => now(),
                    'cost' => null,
                    'resolution_note' => MaintenanceRequest::catatanJalurCepat(),
                ]);
            }

            $kode = $laporan->pluck('request_code')->implode(', ');
            $this->statusKamar->change($kamar, RoomStatus::Available,
                trim(MaintenanceRequest::CATATAN_JALUR_CEPAT.' '.$kode)." oleh {$manager->name}, diminta {$peminta->name}", $manager, 'maintenance');
            $room->refresh();
            $namaManager = Str::before($manager->name, ' ');

            return "Kamar {$kamar->room_number} dibuka lewat jalur cepat."
                .($kode !== '' ? " {$kode} ditutup atas persetujuan {$namaManager}." : " Disetujui {$namaManager}.");
        });
    }

    /**
     * Manager melengkapi laporan jalur cepat: pengerja, biaya, dan catatan tambahan. Status dan tanggal tidak berubah;
     * awalan "Jalur cepat" pada catatan selalu dipertahankan.
     */
    public function lengkapiJalurCepat(MaintenanceRequest $laporan, ?User $staf, ?string $vendor, float $biaya, ?string $catatan): void
    {
        if (! $laporan->fresh()->isJalurCepat()) {
            throw ValidationException::withMessages(['status' => 'Hanya laporan jalur cepat yang bisa dilengkapi.']);
        }
        $vendor = filled($vendor) ? trim($vendor) : null;
        if (($staf === null) === ($vendor === null)) {
            throw ValidationException::withMessages(['pengerja' => 'Pilih staf internal atau isi nama vendor.']);
        }
        if ($biaya < 0) {
            throw ValidationException::withMessages(['cost' => 'Biaya tidak boleh negatif.']);
        }

        $laporan->update([
            'handled_by' => $staf?->id,
            'vendor_name' => $vendor,
            'cost' => $biaya,
            'resolution_note' => MaintenanceRequest::catatanJalurCepat($catatan),
        ]);
    }

    /**
     * Pelaksanaan perawatan rutin: dicatat sebagai laporan preventive yang langsung selesai,
     * lalu jadwal maju sebanyak interval dari tanggal pelaksanaan.
     */
    public function catatPelaksanaan(MaintenanceSchedule $jadwal, Carbon $tanggal, ?User $staf, ?string $vendor, ?float $biaya, ?string $catatan, User $oleh): MaintenanceRequest
    {
        if (! $jadwal->is_active) {
            throw ValidationException::withMessages(['jadwal' => 'Jadwal ini nonaktif.']);
        }
        if ($tanggal->isAfter(today())) {
            throw ValidationException::withMessages(['tanggal' => 'Tanggal perawatan tidak boleh melewati hari ini.']);
        }
        $vendor = filled($vendor) ? trim($vendor) : null;
        if (($staf === null) === ($vendor === null)) {
            throw ValidationException::withMessages(['pengerja' => 'Pilih staf internal atau isi nama vendor.']);
        }
        if ($biaya !== null && $biaya < 0) {
            throw ValidationException::withMessages(['cost' => 'Biaya tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($jadwal, $tanggal, $staf, $vendor, $biaya, $catatan, $oleh) {
            $jadwal->loadMissing('facility');
            $waktu = $tanggal->isToday() ? now() : $tanggal->copy()->setTime(9, 0);
            $laporan = MaintenanceRequest::create([
                'request_code' => Nomor::maintenance(),
                'maintenance_type' => 'preventive',
                'maintenance_schedule_id' => $jadwal->id,
                'damage_description' => "Perawatan rutin {$jadwal->facility->name} {$jadwal->area_label}",
                'priority' => 'medium',
                'reported_by' => $oleh->id,
                'reported_at' => $waktu,
                'status' => 'completed',
                'handled_by' => $staf?->id,
                'vendor_name' => $vendor,
                'started_at' => $waktu,
                'completed_at' => $waktu,
                'cost' => $biaya,
                'resolution_note' => filled($catatan) ? trim($catatan) : null,
            ]);
            $jadwal->update([
                'last_maintenance_date' => $tanggal->toDateString(),
                'next_due_date' => $tanggal->copy()->addMonthsNoOverflow($jadwal->interval_months)->toDateString(),
            ]);

            return $laporan;
        });
    }

    private function pastikanStatus(MaintenanceRequest $laporan, array $boleh, string $pesan): void
    {
        if (! in_array($laporan->fresh()->status, $boleh, true)) {
            throw ValidationException::withMessages(['status' => $pesan]);
        }
    }
}
