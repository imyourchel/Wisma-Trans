<?php

namespace App\Livewire\Staf\Layanan;

use App\Livewire\Staf\Layanan\Concerns\DetailPesanan;
use App\Models\Service;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Layanan\PesananMakananService;
use App\Services\Notifikasi\NotifikasiLayanan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Papan pesanan dapur untuk F&B (Manager memantau). Kolom: antre → dimasak → siap. Diperbarui otomatis;
 * pesanan yang menunggu lebih dari fnb_max_wait_minutes sejak masuk dapur diberi tanda terlambat.
 */
class PapanPesanan extends Component
{
    use DetailPesanan;

    public function mount(): void
    {
        Gate::authorize('view-fnb');
    }

    public function mulai(int $id, PesananMakananService $layanan): void
    {
        Gate::authorize('manage-fnb');
        $o = $this->ambil($id);
        $layanan->mulai($o);
        $this->dispatch('toast', teks: "{$o->order_code} mulai dimasak.");
    }

    public function siap(int $id, PesananMakananService $layanan, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('manage-fnb');
        $o = $this->ambil($id);
        $layanan->siap($o);
        $notifikasi->makanan($o->fresh());
        $this->dispatch('toast', teks: "{$o->order_code} siap.");
    }

    public function serahkan(int $id, PesananMakananService $layanan): void
    {
        Gate::authorize('manage-fnb');
        $o = $this->ambil($id);
        $layanan->serahkan($o);
        if ($this->selectedId === $o->id) {
            $this->close();
        }
        $this->dispatch('toast', teks: "{$o->order_code} sudah diserahkan.");
    }

    public function tersediaLagi(int $id, PesananMakananService $layanan): void
    {
        Gate::authorize('manage-fnb');
        $menu = Service::findOrFail($id);
        $layanan->aturKetersediaan($menu, false);
        $this->dispatch('toast', teks: "{$menu->name} tersedia lagi.");
    }

    public function render(): View
    {
        $dapur = PesananMakananService::diDapur()->with(self::RELASI)->orderBy('order_date')->get()
            ->sortBy(fn ($o) => PesananMakananService::mulaiAntre($o)->timestamp)->values();
        $menungguBayar = PesananMakananService::query()->with(self::RELASI)->where('status', 'pending')->where('payment_mode', 'pay_now')
            ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['paid', 'cancelled']))
            ->where('order_date', '>=', now()->subDay())->orderByDesc('order_date')->get()
            ->reject(fn ($o) => $o->batalkanBilaKedaluwarsa()->status === 'cancelled')->values();

        return view('livewire.staf.layanan.papan-pesanan', [
            'kolom' => [
                'pending' => $dapur->where('status', 'pending'),
                'in_progress' => $dapur->where('status', 'in_progress'),
                'ready' => $dapur->where('status', 'ready'),
            ],
            'menungguBayar' => $menungguBayar,
            'terlambat' => $dapur->filter(fn ($x) => PesananMakananService::terlambat($x))->count(),
            'batas' => PesananMakananService::batasTunggu(),
            'selesaiHariIni' => PesananMakananService::query()->where('status', 'delivered')->whereDate('updated_at', today())->count(),
            'menuHabis' => Service::where('category', 'fnb')->where('is_active', true)->where('is_sold_out', true)->orderBy('name')->get(),
            'perluRefund' => PembayaranMidtrans::jumlahPerluRefund(Auth::user()),
            'bolehKelola' => Gate::allows('manage-fnb'),
            ...$this->dataDetail(),
        ])->layout('components.layouts.staf', ['title' => 'Papan Pesanan']);
    }
}
