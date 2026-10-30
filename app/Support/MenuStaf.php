<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Menu sidebar staf dan siapa yang melihatnya.
 *
 * Kode peran: admin, manager, front_office, fnb, housekeeping.
 * Menu yang belum punya 'route' diarahkan ke halaman "segera" lewat slug-nya.
 * Menu dengan kunci 'gate' tampil sesuai Gate (lihat AppServiceProvider), bukan daftar 'peran'.
 * Menu dengan kunci 'params' memakai route yang sama dengan query string tertentu dan aktif hanya bila query string
 * itu cocok. Papan Status Kamar satu menu untuk tampilan papan dan kalender (tombol Papan/Kalender di halamannya).
 * Staf yang merangkap peran melihat gabungan menunya.
 */
class MenuStaf
{
    private const M = 'manager';

    private const FO = 'front_office';

    private const FB = 'fnb';

    private const HK = 'housekeeping';

    private const A = 'admin';

    private const SEMUA = [self::A, self::M, self::FO, self::FB, self::HK];

    /** @return list<array{label: string, ikon: string, route?: string, peran?: list<string>, gate?: string, anak?: list<array>}> */
    public static function definisi(): array
    {
        return [
            ['label' => 'Dashboard', 'ikon' => 'dashboard', 'route' => 'staf.dashboard', 'peran' => self::SEMUA],

            ['label' => 'Reservasi', 'ikon' => 'reservasi', 'anak' => [
                ['label' => 'Daftar Reservasi', 'route' => 'staf.reservasi', 'gate' => 'view-reservations'],
                // FO melihat pengajuan, tetapi hanya Manager yang dapat menyetujui atau menolak
                ['label' => 'Pengajuan Instansi', 'route' => 'staf.pengajuan-instansi', 'gate' => 'view-reservations'],
                ['label' => 'Reservasi Langsung', 'route' => 'staf.reservasi-langsung', 'gate' => 'manage-reservations'],
                ['label' => 'Check-in', 'route' => 'staf.check-in', 'gate' => 'view-reservations'],
                ['label' => 'Check-out', 'route' => 'staf.check-out', 'gate' => 'view-reservations'],
            ]],

            ['label' => 'Kamar', 'ikon' => 'kamar', 'anak' => [
                // Papan dan Kalender Ketersediaan (?tampilan=kalender) satu halaman, satu menu
                ['label' => 'Papan Status Kamar', 'route' => 'staf.papan-status', 'gate' => 'view-rooms'],
                ['label' => 'Riwayat Status', 'route' => 'staf.kamar.riwayat-status', 'gate' => 'view-reservations'],
            ]],

            ['label' => 'Housekeeping', 'ikon' => 'housekeeping', 'anak' => [
                ['label' => 'Prioritas Pembersihan', 'route' => 'staf.housekeeping.prioritas', 'gate' => 'view-cleaning-priority'],
                ['label' => 'Pembagian Tugas', 'route' => 'staf.housekeeping.pembagian', 'gate' => 'manage-housekeeping'],
                ['label' => 'Ketersediaan Staf', 'route' => 'staf.housekeeping.ketersediaan', 'gate' => 'manage-housekeeping'],
                ['label' => 'Tugas Saya', 'route' => 'staf.housekeeping.tugas-saya', 'gate' => 'do-housekeeping-task'],
                ['label' => 'Bobot Kriteria', 'route' => 'staf.housekeeping.bobot', 'gate' => 'view-cleaning-priority'],
            ]],

            ['label' => 'Layanan', 'ikon' => 'layanan', 'anak' => [
                ['label' => 'Kasir', 'route' => 'staf.fnb.kasir', 'gate' => 'take-fnb-order'],
                ['label' => 'Papan Pesanan', 'route' => 'staf.fnb.papan', 'gate' => 'view-fnb'],
                ['label' => 'Riwayat Pesanan', 'route' => 'staf.fnb.riwayat', 'gate' => 'view-fnb'],
                ['label' => 'Ketersediaan Menu', 'route' => 'staf.fnb.ketersediaan', 'gate' => 'view-fnb'],
                ['label' => 'Laundry', 'route' => 'staf.laundry', 'gate' => 'view-laundry'],
            ]],

            ['label' => 'Aula', 'ikon' => 'aula', 'anak' => [
                ['label' => 'Pemesanan Aula', 'route' => 'staf.aula-pemesanan', 'gate' => 'view-halls'],
                ['label' => 'Kalender Aula', 'route' => 'staf.aula-kalender', 'gate' => 'view-halls'],
            ]],

            ['label' => 'Keuangan', 'ikon' => 'keuangan', 'anak' => [
                ['label' => 'Nota', 'route' => 'staf.keuangan.nota', 'gate' => 'view-finance'],
                ['label' => 'Pembayaran', 'route' => 'staf.keuangan.pembayaran', 'gate' => 'view-finance'],
                ['label' => 'Piutang Instansi', 'route' => 'staf.keuangan.piutang', 'gate' => 'manage-receivables'],
                ['label' => 'Tagihan Bulanan', 'route' => 'staf.keuangan.tagihan-bulanan', 'gate' => 'manage-receivables'],
                ['label' => 'Pengingat Penagihan', 'route' => 'staf.keuangan.pengingat', 'gate' => 'manage-receivables'],
            ]],

            // Semua staf operasional boleh melaporkan kerusakan; penanganannya diputuskan Manager
            ['label' => 'Maintenance', 'ikon' => 'maintenance', 'anak' => [
                ['label' => 'Lapor Kerusakan', 'route' => 'staf.maintenance.lapor', 'gate' => 'report-damage'],
                ['label' => 'Laporan Kerusakan', 'route' => 'staf.maintenance.saya', 'gate' => 'report-damage'],
                ['label' => 'Penanganan', 'route' => 'staf.maintenance.index', 'gate' => 'manage-maintenance'],
                ['label' => 'Jadwal Rutin', 'route' => 'staf.maintenance.jadwal', 'gate' => 'manage-maintenance'],
                ['label' => 'Riwayat', 'route' => 'staf.maintenance.riwayat', 'gate' => 'manage-maintenance'],
            ]],

            ['label' => 'Pelanggan', 'ikon' => 'pelanggan', 'anak' => [
                ['label' => 'Daftar Pelanggan', 'route' => 'staf.pelanggan', 'gate' => 'view-customers'],
                ['label' => 'Instansi', 'route' => 'staf.pelanggan.instansi', 'gate' => 'manage-institutions'],
                ['label' => 'Riwayat Tamu', 'route' => 'staf.pelanggan.riwayat', 'gate' => 'manage-institutions'],
            ]],

            ['label' => 'Laporan', 'ikon' => 'laporan', 'anak' => [
                ['label' => 'Pendapatan', 'route' => 'staf.laporan.pendapatan', 'gate' => 'view-reports'],
                ['label' => 'Hunian', 'route' => 'staf.laporan.hunian', 'gate' => 'view-reports'],
                ['label' => 'Pelanggan', 'route' => 'staf.laporan.pelanggan', 'gate' => 'view-reports'],
                ['label' => 'Operasional', 'route' => 'staf.laporan.operasional', 'gate' => 'view-reports'],
            ]],

            ['label' => 'Data Master', 'ikon' => 'master', 'anak' => [
                ['label' => 'Tipe Kamar', 'route' => 'staf.tipe-kamar.index', 'gate' => 'manage-rooms'],
                ['label' => 'Kamar', 'route' => 'staf.kamar.index', 'gate' => 'manage-rooms'],
                ['label' => 'Fasilitas', 'route' => 'staf.fasilitas.index', 'peran' => [self::A]],
                ['label' => 'Unit Fasilitas', 'route' => 'staf.unit-fasilitas.index', 'peran' => [self::A]],
                ['label' => 'Layanan dan Menu', 'route' => 'staf.layanan.index', 'peran' => [self::A]],
                ['label' => 'Aula', 'route' => 'staf.aula.index', 'peran' => [self::A]],
                ['label' => 'Kriteria Pembersihan', 'route' => 'staf.kriteria.index', 'peran' => [self::A]],
            ]],

            ['label' => 'Pengguna dan Akses', 'ikon' => 'akses', 'anak' => [
                ['label' => 'Pengguna', 'route' => 'staf.pengguna.index', 'peran' => [self::A]],
                ['label' => 'Peran', 'route' => 'staf.peran.index', 'peran' => [self::A]],
            ]],

            ['label' => 'Pengaturan', 'ikon' => 'pengaturan', 'route' => 'staf.pengaturan', 'peran' => [self::A]],
        ];
    }

    /**
     * Menu yang boleh dilihat pengguna, lengkap dengan url dan penanda aktif.
     *
     * @return list<array{label: string, ikon: string, url?: string, aktif: bool, anak: list<array{label: string, url: string, aktif: bool}>}>
     */
    public static function untuk(User $user): array
    {
        $peran = $user->loadMissing('roles')->roles->pluck('code')->all();
        $boleh = fn (array $item) => isset($item['gate'])
            ? $user->can($item['gate'])
            : array_intersect($item['peran'] ?? [], $peran) !== [];

        $hasil = [];
        foreach (self::definisi() as $grup) {
            if (! isset($grup['anak'])) {
                if ($boleh($grup)) {
                    $hasil[] = $grup + ['url' => self::url($grup), 'aktif' => self::aktif($grup), 'anak' => []];
                }

                continue;
            }

            $anak = collect($grup['anak'])->filter($boleh)
                ->map(fn ($item) => ['label' => $item['label'], 'url' => self::url($item), 'aktif' => self::aktif($item)])
                ->values()->all();

            if ($anak) {
                $hasil[] = ['label' => $grup['label'], 'ikon' => $grup['ikon'], 'aktif' => collect($anak)->contains('aktif', true), 'anak' => $anak];
            }
        }

        return $hasil;
    }

    /** Menu yang belum dibangun, dicari lewat slug untuk halaman "segera". */
    public static function cariSlug(string $slug): ?array
    {
        foreach (self::definisi() as $grup) {
            foreach ($grup['anak'] ?? [] as $item) {
                if (! isset($item['route']) && self::slug($item) === $slug) {
                    return $item + ['grup' => $grup['label']];
                }
            }
        }

        return null;
    }

    /** Judul grup untuk jejak navigasi di topbar, berdasarkan halaman yang sedang dibuka. */
    public static function grupAktif(): ?string
    {
        foreach (self::definisi() as $grup) {
            foreach ($grup['anak'] ?? [] as $item) {
                if (self::aktif($item)) {
                    return $grup['label'];
                }
            }
        }

        return null;
    }

    public static function slug(array $item): string
    {
        return $item['slug'] ?? Str::slug($item['label']);
    }

    private static function url(array $item): string
    {
        return isset($item['route']) ? route($item['route'], $item['params'] ?? []) : route('staf.segera', self::slug($item));
    }

    private static function aktif(array $item): bool
    {
        if (isset($item['route'])) {
            if (Route::currentRouteName() !== $item['route']) {
                return false;
            }
            $cocok = fn (array $params) => collect($params)->every(fn ($v, $k) => (string) request()->query($k) === (string) $v);
            if (isset($item['params'])) {
                return $cocok($item['params']);
            }

            // Menu tanpa params tidak aktif bila menu lain dengan route yang sama dan params yang cocok sedang dibuka
            return ! collect(self::definisi())->flatMap(fn ($g) => $g['anak'] ?? [$g])
                ->contains(fn ($m) => ($m['route'] ?? null) === $item['route'] && isset($m['params']) && $cocok($m['params']));
        }

        return Route::currentRouteName() === 'staf.segera' && request()->route('slug') === self::slug($item);
    }
}
