<?php

namespace App\Livewire\Tamu;

use App\Livewire\Tamu\Concerns\PencarianKamar;
use App\Livewire\Tamu\Concerns\PilihKamarTamu;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\PilihanKamar;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Halaman /kamar/{kode}: detail satu tipe kamar. Kode tidak peka huruf besar.
 * Satu kartu per jenis kasur dengan sisa kamar untuk tanggal yang dipilih; tombol "Pilih" menambah satu kamar ke pilihan
 * yang sama dengan /kamar (?pilih=, trait PilihKamarTamu) tanpa pindah halaman. Bar ringkasan, rincian pembagian tamu, dan
 * tombol LANJUTKAN sama dengan /kamar, jadi hanya ada satu jalur ke halaman pemesan.
 * Daya tampung tiap kartu = RoomType::kapasitasKasur (dewasa dan anak, aturan di RoomType::ATURAN_KAPASITAS).
 */
class DetailKamar extends Component
{
    use PencarianKamar, PilihKamarTamu;

    #[Locked]
    public int $tipeId;

    public function mount(string $kode): void
    {
        $this->tipeId = RoomType::whereRaw('UPPER(code) = ?', [Str::upper($kode)])
            ->whereHas('rooms', fn ($q) => $q->where('is_active', true))
            ->firstOrFail()->id;
    }

    public function render(): View
    {
        $cari = $this->pencarian();
        $semua = $this->tipeKamar();
        $tipe = $semua->firstWhere('id', $this->tipeId);
        abort_if($tipe === null, 404);
        $sisa = $this->sisaKamar($semua, $cari);
        $baris = $this->pilihanBerlaku();
        $sisaTipe = $sisa === null ? null : (int) ($sisa['tipe'][$tipe->id] ?? 0);
        $dipilihTipe = collect($baris)->where('kode', Str::upper($tipe->code))->sum('jumlah');
        $sisaLain = fn (RoomType $t) => $sisa === null ? null : (int) ($sisa['tipe'][$t->id] ?? 0);

        return view('livewire.tamu.detail-kamar', [
            'cari' => $cari,
            'tipe' => $tipe,
            'jumlahTamu' => $this->jumlahTamu(),
            'sisaTipe' => $sisaTipe,
            // Tipe lain: yang masih tersedia di tanggal yang sama didahulukan, lalu termurah
            'lain' => $semua->where('id', '!=', $tipe->id)
                ->sortBy(fn ($t) => [$sisaLain($t) === 0 ? 1 : 0, (float) $t->base_price])->take(3)->values()
                ->map(fn ($t) => $t->setAttribute('sisaTanggal', $sisaLain($t))),
            'ketentuan' => $this->ketentuan(),
            'kartuKasur' => collect($tipe->kodeKasur)->map(function ($kode) use ($tipe, $sisa, $sisaTipe, $baris, $dipilihTipe) {
                $jumlah = PilihanKamar::jumlah($baris, $tipe->code, $kode);
                $sisaKasur = $sisa === null ? null : min((int) ($sisa['kasur'][$tipe->id][$kode] ?? 0), $sisaTipe);

                return [
                    'kode' => $kode,
                    'nama' => Room::labelKasur($kode),
                    'info' => Room::BED_INFO[$kode] ?? '',
                    'foto' => Room::fotoKasur($kode),
                    'kapasitas' => $tipe->kapasitasKasur($kode),
                    'sisa' => $sisaKasur,
                    'jumlah' => $jumlah,
                    'maks' => $sisa === null ? 0 : max(0, min($sisaKasur, $sisaTipe - ($dipilihTipe - $jumlah))),
                    // Kecocokan dengan penghuni kamar di pencarian: muat, muat-extra (perlu extra bed), tidak-muat
                    'cocok' => $this->cocokKasur($tipe, $kode),
                ];
            })->all(),
            'teksTamuTerkecil' => RoomType::teksBatas(collect($this->susunanTamu())->sortBy(fn ($s) => [$s['dewasa'], $s['anak']])->first()),
            'ringkasan' => $this->ringkasanPilihan(),
            'urlKamar' => route('kamar', $this->queryCari()),
            'queryCari' => $this->queryCari(),
            'susunanTamu' => $this->susunanTamu(),
            // Batas dewasa dan anak per kamar di panel pencarian (RoomType::ATURAN_KAPASITAS)
            'batasTamu' => RoomType::batasPencarian(),
        ])->layout('components.layouts.guest', ['title' => $tipe->name]);
    }
}
