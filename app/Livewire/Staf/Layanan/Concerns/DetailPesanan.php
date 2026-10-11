<?php

namespace App\Livewire\Staf\Layanan\Concerns;

use App\Models\ServiceOrder;
use App\Services\Layanan\PesananMakananService;
use App\Services\Notifikasi\NotifikasiLayanan;
use App\Services\Notifikasi\WhatsAppService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Detail pesanan makanan (modal) yang sama di Papan Pesanan dan Riwayat Pesanan:
 * isi pesanan, rincian harga, riwayat waktu, kirim ulang WhatsApp, dan pembatalan.
 */
trait DetailPesanan
{
    #[Locked]
    public ?int $selectedId = null;

    public bool $formBatal = false;

    public string $alasan = '';

    public const RELASI = ['items.service', 'customer', 'reservationRoom.room', 'invoice.payments'];

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
        $this->resetValidation();
    }

    public function bukaBatal(): void
    {
        Gate::authorize('manage-fnb');
        $this->formBatal = true;
        $this->alasan = '';
        $this->resetValidation();
    }

    public function batalkan(PesananMakananService $layanan): void
    {
        Gate::authorize('manage-fnb');
        $this->alasan = trim($this->alasan);
        $this->validate(['alasan' => 'required|string|max:150'], [
            'alasan.required' => 'Isi alasan pembatalan.',
            'alasan.max' => 'Alasan maksimal 150 karakter.',
        ]);
        $o = $this->ambil((int) $this->selectedId);
        $layanan->batalkan($o, $this->alasan);
        $this->close();
        $this->dispatch('toast', teks: "{$o->order_code} dibatalkan.");
    }

    protected function ambil(int $id): ServiceOrder
    {
        return PesananMakananService::query()->with(self::RELASI)->findOrFail($id);
    }

    /** @return array{o: ?ServiceOrder, waSiap: ?string, bolehBatal: bool, bernota: ?string} */
    protected function dataDetail(): array
    {
        $o = $this->selectedId ? PesananMakananService::query()->with(self::RELASI)->find($this->selectedId) : null;
        // Tagihan kamar yang sudah digabung ke nota saat check-out tidak dibatalkan dari sini
        $bernota = $o && $o->payment_mode === 'charge_to_room' && $o->invoice_id;

        return [
            'o' => $o,
            'waSiap' => $o && $o->order_type === 'outside_guest' && $o->status === 'ready'
                ? WhatsAppService::tautan($o->customer?->phone, app(NotifikasiLayanan::class)->teksMakanan($o)) : null,
            'bolehBatal' => $o && $o->status === 'pending' && ! $bernota && Gate::allows('manage-fnb'),
            'bernota' => $bernota ? PesananMakananService::sudahBernota($o) : null,
        ];
    }
}
