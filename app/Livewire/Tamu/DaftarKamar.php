<?php

namespace App\Livewire\Tamu;

use App\Livewire\Tamu\Concerns\PencarianKamar;
use Illuminate\View\View;
use Livewire\Component;

/** Halaman /kamar: bandingkan tipe kamar dan cek ketersediaan untuk tanggal menginap. */
class DaftarKamar extends Component
{
    use PencarianKamar;

    public function render(): View
    {
        $cari = $this->pencarian();
        $semua = $this->tipeKamar();
        $status = $this->statusPerTipe($semua, $cari);
        $kombinasi = $this->saranKombinasi($semua, $status, $cari);
        $bisaDipesan = collect($status)->contains(fn ($s) => in_array($s['jenis'], ['tersedia', 'kurang', 'sebagian'], true));

        return view('livewire.tamu.daftar-kamar', [
            'cari' => $cari,
            'tipe' => $this->urutkan($semua, $status),
            'status' => $status,
            'kombinasi' => $kombinasi,
            // Tipe yang tidak muat disembunyikan; tamu bisa menampilkannya lagi
            'tersembunyi' => collect($status)->where('jenis', 'tidak-muat')->count(),
            'semuaPenuh' => $status !== [] && collect($status)->every(fn ($s) => $s['jenis'] === 'penuh'),
            // Tidak ada tipe yang bisa dipesan, dan sebagian karena susunan tamu melebihi kapasitas
            'tidakMuat' => $status !== [] && ! $bisaDipesan && collect($status)->contains(fn ($s) => $s['jenis'] === 'tidak-muat'),
            'maksTamu' => (int) $semua->max('max_capacity') ?: 4,
            'ketentuan' => $this->ketentuan(),
        ])->layout('components.layouts.guest', ['title' => 'Kamar']);
    }
}
