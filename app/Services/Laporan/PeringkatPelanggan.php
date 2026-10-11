<?php

namespace App\Services\Laporan;

use App\Models\Customer;
use App\Models\Institution;
use Illuminate\Support\Collection;

/**
 * Peringkat pelanggan dan instansi dalam satu periode: pendapatan diakui (baris nota dalam periode, sama dengan
 * halaman Pendapatan), jumlah kunjungan (reservasi yang menginap dalam periode), dan malam kamar.
 * Profil satu pelanggan (kamar favorit, rekomendasi) bukan di sini, melainkan di modul CRM.
 */
class PeringkatPelanggan
{
    public function __construct(private LaporanPendapatan $pendapatan, private LaporanHunian $hunian) {}

    /** @return Collection<int, array{id: int, nama: string, pendapatan: float, kunjungan: int, malam: int}> */
    public function pelanggan(Periode $p, int $jumlah = 10, ?Collection $potongan = null, ?Collection $malam = null): Collection
    {
        $potongan ??= $this->pendapatan->potongan($p);
        $malam ??= $this->hunian->malamTerisi($p);

        return $this->peringkat($potongan->groupBy('pelanggan'), $malam->groupBy('pelanggan'), $jumlah,
            fn ($ids) => Customer::withTrashed()->whereIn('id', $ids)->pluck('full_name', 'id'));
    }

    /** @return Collection<int, array{id: int, nama: string, pendapatan: float, kunjungan: int, malam: int}> */
    public function instansi(Periode $p, int $jumlah = 10, ?Collection $potongan = null, ?Collection $malam = null): Collection
    {
        $potongan ??= $this->pendapatan->potongan($p);
        $malam ??= $this->hunian->malamTerisi($p);

        return $this->peringkat($potongan->whereNotNull('instansi')->groupBy('instansi'), $malam->whereNotNull('instansi')->groupBy('instansi'), $jumlah,
            fn ($ids) => Institution::whereIn('id', $ids)->pluck('name', 'id'));
    }

    private function peringkat(Collection $uang, Collection $inap, int $jumlah, callable $nama): Collection
    {
        $ids = $uang->keys()->merge($inap->keys())->unique()->values();
        $daftar = $nama($ids);

        return $ids->map(fn ($id) => [
            'id' => (int) $id,
            'nama' => $daftar[$id] ?? '—',
            'pendapatan' => (float) ($uang[$id] ?? collect())->sum('nilai'),
            'kunjungan' => ($inap[$id] ?? collect())->pluck('reservasi')->unique()->count(),
            'malam' => ($inap[$id] ?? collect())->count(),
        ])->sortByDesc('pendapatan')->take($jumlah)->values();
    }
}
