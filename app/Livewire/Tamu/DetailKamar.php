<?php

namespace App\Livewire\Tamu;

use App\Livewire\Tamu\Concerns\PencarianKamar;
use App\Models\RoomType;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Halaman /kamar/{kode}: detail satu tipe kamar dan kotak pemesanan. Kode tidak peka huruf besar. */
class DetailKamar extends Component
{
    use PencarianKamar;

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
        $status = $this->statusPerTipe($semua, $cari);

        $bisa = fn ($id) => in_array($status[$id]['jenis'] ?? 'tersedia', ['tersedia', 'kurang', 'sebagian'], true);

        // Tipe lain: bila tipe ini penuh atau tidak muat, yang masih bisa dipesan di tanggal yang sama didahulukan
        $lain = $semua->where('id', '!=', $tipe->id)
            ->sortBy(fn ($t) => [$bisa($t->id) ? 0 : 1, (float) $t->base_price])
            ->take(3)->values();

        return view('livewire.tamu.detail-kamar', [
            'cari' => $cari,
            'tipe' => $tipe,
            'status' => $status,
            'ini' => $status[$tipe->id] ?? null,
            'lain' => $lain,
            'saranLain' => collect($status)->except($tipe->id)->keys()->filter($bisa)
                ->map(fn ($id) => $semua->firstWhere('id', $id))->sortBy('base_price')->values(),
            'ketentuan' => $this->ketentuan(),
        ])->layout('components.layouts.guest', ['title' => $tipe->name]);
    }
}
