<?php

namespace App\Livewire\Staf\Layanan;

use App\Models\ServiceOrder;
use App\Services\NotifikasiLayanan;
use App\Services\PesananMakananService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Papan pesanan dapur untuk F&B (Manager memantau). Kolom: antre → dimasak → siap. Diperbarui otomatis;
 * pesanan yang menunggu lebih dari fnb_max_wait_minutes diberi tanda terlambat.
 */
class PapanPesanan extends Component
{
    #[Locked]
    public ?int $selectedId = null;

    public bool $formBatal = false;

    public string $alasan = '';

    public function mount(): void
    {
        Gate::authorize('view-fnb');
    }

    public function open(int $id): void
    {
        $this->selectedId = PesananMakananService::query()->findOrFail($id)->id;
        $this->reset('formBatal', 'alasan');
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->reset('formBatal', 'alasan');
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

    public function batalkan(PesananMakananService $layanan): void
    {
        Gate::authorize('manage-fnb');
        $this->validate(['alasan' => 'required|string|max:150'], ['alasan.required' => 'Isi alasan pembatalan.']);
        $o = $this->ambil((int) $this->selectedId);
        $layanan->batalkan($o, trim($this->alasan));
        $this->close();
        $this->dispatch('toast', teks: "{$o->order_code} dibatalkan.");
    }

    private function ambil(int $id): ServiceOrder
    {
        return PesananMakananService::query()->with('invoice.payments', 'items.service')->findOrFail($id);
    }

    public function render(): View
    {
        $relasi = ['items.service', 'customer', 'reservationRoom.room', 'invoice.payments'];
        $dapur = PesananMakananService::diDapur()->with($relasi)->orderBy('order_date')->get()
            ->sortBy(fn ($o) => PesananMakananService::mulaiAntre($o)->timestamp)->values();
        $menungguBayar = PesananMakananService::query()->with($relasi)->where('status', 'pending')->where('payment_mode', 'pay_now')
            ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['paid', 'cancelled']))
            ->where('order_date', '>=', now()->subDay())->orderByDesc('order_date')->get()
            ->reject(fn ($o) => $o->batalkanBilaKedaluwarsa()->status === 'cancelled')->values();
        $o = $this->selectedId ? PesananMakananService::query()->with($relasi)->find($this->selectedId) : null;

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
            'o' => $o,
            'waSiap' => $o && $o->order_type === 'outside_guest' && $o->status === 'ready'
                ? WhatsAppService::tautan($o->customer?->phone, app(NotifikasiLayanan::class)->teksMakanan($o)) : null,
            'bolehKelola' => Gate::allows('manage-fnb'),
        ])->layout('components.layouts.staf', ['title' => 'Papan Pesanan']);
    }
}
