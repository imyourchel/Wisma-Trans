<?php

namespace App\Livewire\Staf\Layanan;

use App\Models\Service;
use App\Services\Layanan\PesananMakananService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ketersediaan Menu (F&B; Manager melihat): menandai menu aktif habis atau tersedia lagi. Hanya is_sold_out yang berubah;
 * mengaktifkan atau menonaktifkan menu tetap tugas Admin di Data Master.
 */
class KetersediaanMenu extends Component
{
    /** semua | habis */
    #[Url(except: 'semua')]
    public string $saring = 'semua';

    #[Url(except: '')]
    public string $cari = '';

    public function mount(): void
    {
        Gate::authorize('view-fnb');
    }

    public function tandaiHabis(int $id, PesananMakananService $layanan): void
    {
        $this->ubah($id, true, $layanan);
    }

    public function tersediaLagi(int $id, PesananMakananService $layanan): void
    {
        $this->ubah($id, false, $layanan);
    }

    private function ubah(int $id, bool $habis, PesananMakananService $layanan): void
    {
        Gate::authorize('manage-fnb');
        $menu = Service::findOrFail($id);
        $layanan->aturKetersediaan($menu, $habis);
        $this->dispatch('toast', teks: $habis ? "{$menu->name} ditandai habis. Pesanan yang sudah masuk tetap diproses." : "{$menu->name} tersedia lagi.", peringatan: $habis);
    }

    public function render(): View
    {
        $kata = mb_strtolower(trim($this->cari));
        $menu = Service::menuKafe()->orderBy('id')->get();

        return view('livewire.staf.layanan.ketersediaan-menu', [
            'grup' => $menu->filter(fn ($m) => ($this->saring !== 'habis' || $m->habis()) && ($kata === '' || str_contains(mb_strtolower($m->name), $kata)))
                ->groupBy(fn ($m) => $m->menu_group ?: 'Lainnya'),
            'jumlah' => $menu->count(),
            'jumlahHabis' => $menu->filter->habis()->count(),
            'bolehKelola' => Gate::allows('manage-fnb'),
        ])->layout('components.layouts.staf', ['title' => 'Ketersediaan Menu']);
    }
}
