<?php

namespace App\Livewire\Tamu;

use App\Livewire\Tamu\Concerns\PencarianKamar;
use App\Livewire\Tamu\Concerns\PilihKamarTamu;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\PilihanKamar;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Halaman /kamar (langkah 1 dari 3): kartu tipe kamar (badge, deskripsi, fasilitas, keunggulan) dengan daftar jenis kasur dan
 * sisanya; jenis kasur dipilih di halaman detail (keranjang ?pilih=, bar ringkasan di bawah). Pencarian beberapa kamar mendapat
 * kartu rekomendasi yang bisa dimasukkan ke keranjang. Keranjang dilanjutkan ke PesanKamar (?tamu=&pilihan=&kasur=).
 */
class DaftarKamar extends Component
{
    use PencarianKamar, PilihKamarTamu;

    public function render(): View
    {
        $cari = $this->pencarian();
        $semua = $this->tipeKamar();
        $sisa = $this->sisaKamar($semua, $cari);
        $baris = $this->pilihanBerlaku();
        $tamu = $this->jumlahTamu();

        $tipe = $semua->map(function (RoomType $t) use ($sisa, $baris) {
            $sisaTipe = $sisa === null ? null : (int) ($sisa['tipe'][$t->id] ?? 0);
            $dipilihTipe = collect($baris)->where('kode', Str::upper($t->code))->sum('jumlah');
            $kasur = collect($t->kodeKasur)->map(function ($kode) use ($t, $sisa, $sisaTipe, $baris, $dipilihTipe) {
                $jumlah = PilihanKamar::jumlah($baris, $t->code, $kode);
                $sisaKasur = $sisa === null ? null : min((int) ($sisa['kasur'][$t->id][$kode] ?? 0), $sisaTipe);

                return [
                    'kode' => $kode,
                    'nama' => Room::labelKasur($kode),
                    'kapasitas' => PilihanKamar::teksKapasitas($t, $kode),
                    'sisa' => $sisaKasur,
                    'jumlah' => $jumlah,
                    // Batas stepper: sisa jenis kasur ini, dan sisa tipe yang dibagi bersama dengan jenis kasur lain
                    'maks' => $sisa === null ? 0 : max(0, min($sisaKasur, $sisaTipe - ($dipilihTipe - $jumlah))),
                    // Kecocokan dengan penghuni kamar di pencarian: muat, muat-extra (perlu extra bed), tidak-muat
                    'cocok' => $this->cocokKasur($t, $kode),
                ];
            })->all();
            $t->setAttribute('barisKasur', $kasur);
            $t->setAttribute('cocok', $this->cocokTipe($t));
            $t->setAttribute('sisaTipe', $sisaTipe);
            $t->setAttribute('diKeranjang', $dipilihTipe);
            $t->setAttribute('habis', $sisa !== null && collect($kasur)->every(fn ($k) => $k['sisa'] === 0));

            return $t;
        })
            // Yang muat dulu, lalu yang perlu extra bed, lalu yang tidak muat; di dalamnya yang habis di bawah, lalu termurah
            ->sortBy(fn (RoomType $t) => [RoomType::URUT_KECOCOKAN[$t->cocok], $t->habis ? 1 : 0, (float) $t->base_price, $t->id])->values();
        // Teks tombol tipe yang tidak muat: penghuni kamar terkecil di pencarian (tipe itu tidak muat bahkan untuknya)
        $terkecil = collect($this->susunanTamu())->sortBy(fn ($s) => [$s['dewasa'], $s['anak']])->first();

        return view('livewire.tamu.daftar-kamar', [
            'cari' => $cari,
            'tipe' => $tipe,
            'jumlahTamu' => $tamu,
            'semuaPenuh' => $sisa !== null && $tipe->isNotEmpty() && $tipe->every(fn ($t) => $t->habis),
            'saran' => $this->saranTipe(),
            'teksTidakMuat' => 'Tidak muat untuk '.RoomType::teksBatas($terkecil),
            'ringkasan' => $this->ringkasanPilihan(),
            'rekomendasi' => $this->rekomendasi(),
            'queryCari' => $this->queryCari(),
            'susunanTamu' => $this->susunanTamu(),
            // Batas dewasa dan anak per kamar di panel pencarian (RoomType::ATURAN_KAPASITAS)
            'batasTamu' => RoomType::batasPencarian(),
            'ketentuan' => $this->ketentuan(),
        ])->layout('components.layouts.guest', ['title' => 'Kamar']);
    }
}
