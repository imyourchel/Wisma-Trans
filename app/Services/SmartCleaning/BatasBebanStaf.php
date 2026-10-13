<?php

namespace App\Services\SmartCleaning;

use App\Models\Setting;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Batas beban efektif staf Housekeeping pada satu tanggal; satu-satunya tempat aturan ini. Dicek berurutan:
 *   1. diisi manager di Ketersediaan Staf (staff_availabilities.max_workload),
 *   2. multi-role (HK + F&B dan/atau Front Office) → settings hk_max_workload_multirole,
 *   3. HK murni                                    → settings hk_max_workload.
 * Peran dibaca dari user_roles; Admin, Manager, dan Customer tidak dihitung sebagai peran operasional. Angka batas
 * otomatis hanya ada di settings (diubah lewat halaman Pengaturan), tidak di kode.
 */
class BatasBebanStaf
{
    /** Kunci settings per jenis batas otomatis. */
    public const SETTING = [
        'murni' => 'hk_max_workload',
        'multirole' => 'hk_max_workload_multirole',
    ];

    /** Peran operasional selain Housekeeping yang membuat staf menjadi multi-role, urut tampilan. */
    public const PERAN_LAIN = ['fnb' => 'F&B', 'front_office' => 'Front Office'];

    /** Batas otomatis dari settings; tanpa angka cadangan supaya file SQL yang belum dijalankan langsung ketahuan. */
    public static function otomatis(string $jenis): float
    {
        $nilai = Setting::get(self::SETTING[$jenis]);
        if ($nilai === null || $nilai === '') {
            throw new RuntimeException('Pengaturan '.self::SETTING[$jenis].' belum ada. Jalankan database/sql/2026-10-13-batas-beban-hk.sql.');
        }

        return (float) str_replace(',', '.', (string) $nilai);
    }

    /** Peran operasional lain yang dimiliki staf, misalnya ['F&B', 'Front Office']; kosong berarti HK murni. */
    public static function peranLain(User $staf): array
    {
        $staf->loadMissing('roles');
        $kode = $staf->roles->pluck('code');

        return collect(self::PERAN_LAIN)->filter(fn ($label, $c) => $kode->contains($c))->values()->all();
    }

    /**
     * Batas otomatis staf (dipakai bila manager tidak mengisi angka), menurut perannya.
     *
     * @return array{batas: float, jenis: string, sumber: string, singkat: string}
     */
    public static function otomatisUntuk(User $staf): array
    {
        $lain = self::peranLain($staf);

        return $lain !== []
            ? ['batas' => self::otomatis('multirole'), 'jenis' => 'multirole', 'sumber' => 'Multi-role (HK + '.implode(' + ', $lain).')', 'singkat' => 'multi-role']
            : ['batas' => self::otomatis('murni'), 'jenis' => 'murni', 'sumber' => 'HK murni', 'singkat' => 'HK murni'];
    }

    /**
     * Batas efektif satu staf dari baris ketersediaannya (boleh null = tidak ada baris).
     *
     * @return array{batas: float, jenis: string, sumber: string, singkat: string}
     */
    public static function dari(User $staf, ?StaffAvailability $k): array
    {
        if ($k && $k->max_workload !== null) {
            return ['batas' => (float) $k->max_workload, 'jenis' => 'manual', 'sumber' => 'Manual', 'singkat' => 'manual'];
        }

        return self::otomatisUntuk($staf);
    }

    /** Nilai max_workload yang disimpan: angka yang sama dengan batas otomatis staf itu disimpan NULL (bukan manual). */
    public static function untukDisimpan(User $staf, ?float $isian): ?float
    {
        return $isian === null || abs($isian - self::otomatisUntuk($staf)['batas']) < 1e-9 ? null : $isian;
    }

    /**
     * Batas efektif satu staf pada tanggal itu.
     *
     * @return array{0: float, 1: string} batas dan sumbernya
     */
    public static function untuk(User $staf, Carbon $tanggal): array
    {
        $k = StaffAvailability::where('user_id', $staf->id)->whereDate('work_date', $tanggal->toDateString())->first();
        $b = self::dari($staf, $k);

        return [$b['batas'], $b['sumber']];
    }

    /**
     * Batas efektif banyak staf sekaligus (tanpa query per staf).
     *
     * @param  Collection<int, User>  $staf  sebaiknya sudah memuat roles
     * @param  Collection<int, StaffAvailability>  $ketersediaan  kunci user_id
     * @return array<int, array{batas: float, jenis: string, sumber: string, singkat: string}>
     */
    public static function untukBanyak(Collection $staf, Collection $ketersediaan): array
    {
        return $staf->mapWithKeys(fn (User $u) => [$u->id => self::dari($u, $ketersediaan[$u->id] ?? null)])->all();
    }
}
