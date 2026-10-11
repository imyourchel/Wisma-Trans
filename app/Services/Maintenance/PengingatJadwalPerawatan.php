<?php

namespace App\Services\Maintenance;

use App\Models\MaintenanceSchedule;
use App\Models\User;
use App\Notifications\PesanReservasi;
use App\Services\Notifikasi\WhatsAppService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengingat harian perawatan rutin untuk Manager: jadwal aktif yang perawatannya terlambat atau harus dilakukan
 * dalam MaintenanceSchedule::HARI_SEGERA hari. Dikirim lewat WhatsApp dan email, paling banyak sekali sehari,
 * dan terus dikirim tiap hari sampai perawatannya dicatat (tanggal berikutnya maju) atau jadwal dinonaktifkan.
 */
class PengingatJadwalPerawatan
{
    public function __construct(private WhatsAppService $wa) {}

    /** @return Collection<int, MaintenanceSchedule> yang terlambat dulu, lalu yang paling dekat */
    public function daftar(): Collection
    {
        return MaintenanceSchedule::with('facility')
            ->where('is_active', true)
            ->whereDate('next_due_date', '<=', today()->addDays(MaintenanceSchedule::HARI_SEGERA))
            ->orderBy('next_due_date')
            ->get();
    }

    public static function judul(MaintenanceSchedule $j): string
    {
        return trim($j->facility->name.' '.$j->area_label);
    }

    /** Satu baris per jadwal, misalnya "AC Lantai 2: perawatan berikutnya 20 Sep 2026, terlambat 16 hari (CV Sejuk Teknik)". */
    public static function baris(MaintenanceSchedule $j): string
    {
        $sisa = (int) today()->diffInDays($j->next_due_date, false);
        $kapan = match (true) {
            $sisa < 0 => 'terlambat '.abs($sisa).' hari',
            $sisa === 0 => 'hari ini',
            $sisa === 1 => 'besok',
            default => "{$sisa} hari lagi",
        };

        return self::judul($j).': perawatan berikutnya '.$j->next_due_date->translatedFormat('d M Y').", {$kapan}"
            .($j->vendor_name ? " ({$j->vendor_name})" : '');
    }

    /** @param  Collection<int, MaintenanceSchedule>  $daftar */
    public function teks(Collection $daftar, string $nama): string
    {
        [$lewat, $segera] = $daftar->partition(fn (MaintenanceSchedule $j) => $j->penanda() === 'lewat');
        $teks = 'Halo '.Str::before($nama, ' ').', pengingat perawatan rutin Wisma Trans '.today()->translatedFormat('l, j F Y').'.';
        if ($lewat->isNotEmpty()) {
            $teks .= "\n\n*Terlambat dirawat*\n".$lewat->map(fn ($j) => '• '.self::baris($j))->implode("\n");
        }
        if ($segera->isNotEmpty()) {
            $teks .= "\n\n*Perlu dirawat ".MaintenanceSchedule::HARI_SEGERA." hari ke depan*\n".$segera->map(fn ($j) => '• '.self::baris($j))->implode("\n");
        }

        return $teks."\n\nSetelah dirawat, catat perawatannya di Jadwal Rutin: ".route('staf.maintenance.jadwal');
    }

    /** @return Collection<int, User> Manager aktif */
    public static function penerima(): Collection
    {
        return User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'manager'))->get();
    }

    /**
     * Kirim pengingat hari ini. Mengembalikan jumlah jadwal yang diingatkan (0 bila tidak ada atau sudah dikirim hari ini).
     * $paksa mengabaikan batas sekali sehari.
     */
    public function kirim(bool $paksa = false): int
    {
        $daftar = $this->daftar();
        if ($daftar->isEmpty()) {
            return 0;
        }
        $kunci = 'pengingat-jadwal-perawatan:'.today()->toDateString();
        if (! $paksa && ! Cache::add($kunci, true, today()->addDays(2))) {
            return 0;
        }

        foreach (self::penerima() as $manager) {
            $teks = $this->teks($daftar, $manager->name);
            try {
                $this->wa->kirim($manager->phone, $teks);
            } catch (Throwable $e) {
                Log::warning('Pengingat jadwal perawatan via WhatsApp gagal', ['user' => $manager->id, 'pesan' => $e->getMessage()]);
            }
            try {
                $manager->notify(new PesanReservasi(
                    'Pengingat perawatan rutin: '.$daftar->count().' jadwal perlu dirawat',
                    'Halo '.Str::before($manager->name, ' ').',',
                    [
                        'Perawatan rutin berikut sudah terlambat atau harus dilakukan dalam '.MaintenanceSchedule::HARI_SEGERA.' hari ke depan:',
                        ...$daftar->map(fn ($j) => '• '.self::baris($j))->all(),
                        'Setelah dirawat, catat perawatannya supaya tanggal perawatan berikutnya dihitung ulang.',
                    ],
                    'Buka Jadwal Rutin',
                    route('staf.maintenance.jadwal'),
                ));
            } catch (Throwable $e) {
                Log::warning('Pengingat jadwal perawatan via email gagal', ['user' => $manager->id, 'pesan' => $e->getMessage()]);
            }
        }

        return $daftar->count();
    }
}
