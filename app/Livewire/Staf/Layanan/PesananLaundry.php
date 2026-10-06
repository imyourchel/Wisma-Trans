<?php

namespace App\Livewire\Staf\Layanan;

use App\Models\ReservationRoom;
use App\Models\ServiceOrder;
use App\Rules\NomorHp;
use App\Services\LaundryService;
use App\Services\NotifikasiLayanan;
use App\Services\WhatsAppService;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pesanan Laundry untuk Housekeeping dan Front Office (Manager melihat): terima cucian, timbang,
 * tandai siap, serahkan, dan catat pembayaran tunai. Tamu diberi kabar lewat WhatsApp di tiap tahap.
 */
class PesananLaundry extends Component
{
    /** aktif | selesai | batal */
    #[Url(except: 'aktif')]
    public string $tab = 'aktif';

    #[Url(except: '')]
    public string $cari = '';

    #[Locked]
    public ?int $selectedId = null;

    /** timbang | batal */
    public string $aksi = '';

    public string $berat = '';

    public string $alasan = '';

    /* Formulir terima laundry */
    public bool $formTerima = false;

    /** kamar | luar */
    public string $jenisBaru = 'kamar';

    public string $kamarBaru = '';

    public string $tagihBaru = 'kamar';

    public string $namaBaru = '';

    public string $hpBaru = '';

    public string $beratBaru = '';

    public string $catatanBaru = '';

    public function mount(): void
    {
        Gate::authorize('view-laundry');
    }

    public function open(int $id): void
    {
        $this->selectedId = $this->pesanan()->findOrFail($id)->id;
        $this->tutupAksi();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->tutupAksi();
    }

    public function tutupAksi(): void
    {
        $this->reset('aksi', 'berat', 'alasan');
        $this->resetValidation();
    }

    public function mulai(string $aksi): void
    {
        Gate::authorize('manage-laundry');
        $this->tutupAksi();
        $this->aksi = in_array($aksi, ['timbang', 'batal'], true) ? $aksi : '';
    }

    public function timbang(LaundryService $laundry, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('manage-laundry');
        $this->berat = str_replace(',', '.', trim($this->berat));
        $this->validate(['berat' => 'required|numeric|min:0.1|max:100'], ['berat.*' => 'Isi berat hasil timbang, misalnya 2,5.']);
        $order = $laundry->timbang($this->terpilih(), (float) $this->berat, Auth::user());
        $notifikasi->laundry($order);
        $this->selesai("Cucian {$order->order_code} ditimbang.");
    }

    public function siap(LaundryService $laundry, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('manage-laundry');
        $order = $this->terpilih();
        $laundry->siap($order);
        $notifikasi->laundry($order->fresh());
        $this->selesai("Cucian {$order->order_code} siap.");
    }

    public function serahkan(LaundryService $laundry): void
    {
        Gate::authorize('manage-laundry');
        $order = $this->terpilih();
        $laundry->serahkan($order);
        $this->selesai("Cucian {$order->order_code} sudah diserahkan.");
    }

    public function bayarTunai(LaundryService $laundry): void
    {
        Gate::authorize('manage-laundry');
        $order = $this->terpilih();
        $laundry->bayarTunai($order, Auth::user());
        $this->dispatch('toast', teks: "Pembayaran tunai {$order->order_code} dicatat.");
    }

    public function batalkan(LaundryService $laundry): void
    {
        Gate::authorize('manage-laundry');
        $this->validate(['alasan' => 'required|string|max:150'], ['alasan.required' => 'Isi alasan pembatalan.']);
        $order = $this->terpilih();
        $laundry->batalkan($order, $this->alasan);
        $this->selesai("Pesanan {$order->order_code} dibatalkan.");
    }

    public function bukaTerima(): void
    {
        Gate::authorize('manage-laundry');
        $this->reset('jenisBaru', 'kamarBaru', 'tagihBaru', 'namaBaru', 'hpBaru', 'beratBaru', 'catatanBaru');
        $this->resetValidation();
        $this->formTerima = true;
    }

    /** Cucian diterima langsung oleh staf; bila beratnya sudah ditimbang, langsung dicatat. */
    public function terima(LaundryService $laundry, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('manage-laundry');
        $kamar = $this->jenisBaru === 'kamar';
        $this->beratBaru = str_replace(',', '.', trim($this->beratBaru));
        if (! $kamar) {
            $this->namaBaru = PersonName::normalize($this->namaBaru);
            $this->hpBaru = Phone::normalizeInput($this->hpBaru);
        }
        $this->validate([
            'kamarBaru' => [$kamar ? 'required' : 'nullable', Rule::in($this->kamarTerisi()->keys()->map(fn ($id) => (string) $id)->all())],
            'tagihBaru' => ['required', Rule::in(['kamar', 'sekarang'])],
            'namaBaru' => [$kamar ? 'nullable' : 'required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'hpBaru' => [$kamar ? 'nullable' : 'required', $kamar ? 'string' : new NomorHp],
            'beratBaru' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'catatanBaru' => ['nullable', 'string', 'max:200'],
        ], [
            'kamarBaru.*' => 'Pilih kamar yang sedang ditempati.',
            'namaBaru.required' => 'Isi nama tamu.',
            'namaBaru.regex' => PersonName::MESSAGE,
            'hpBaru.required' => 'Isi nomor WhatsApp tamu.',
            'beratBaru.*' => 'Isi berat dalam kg, misalnya 2,5.',
        ]);

        $staf = Auth::user();
        if ($kamar) {
            $baris = ReservationRoom::with('reservation.customer')->findOrFail((int) $this->kamarBaru);
            $pemesan = $baris->reservation->customer;
        } else {
            $baris = null;
            $pemesan = Pelanggan::dariIsian(['name' => $this->namaBaru, 'phone' => $this->hpBaru], null, 'hpBaru');
        }
        $order = $laundry->terima($pemesan, $baris, $kamar && $this->tagihBaru === 'kamar',
            $this->beratBaru !== '' ? (float) $this->beratBaru : null, trim($this->catatanBaru) ?: null, $staf);
        if ($this->beratBaru !== '') {
            $order = $laundry->timbang($order, (float) $this->beratBaru, $staf);
        }
        $notifikasi->laundry($order);

        $this->formTerima = false;
        $this->dispatch('toast', teks: "Laundry {$order->order_code} diterima.");
    }

    private function selesai(string $pesan): void
    {
        $this->tutupAksi();
        $this->dispatch('toast', teks: $pesan);
    }

    private function pesanan()
    {
        return ServiceOrder::query()->whereHas('items.service', fn ($q) => $q->where('category', 'laundry'));
    }

    private function terpilih(): ServiceOrder
    {
        abort_if($this->selectedId === null, 404);

        return $this->pesanan()->with(['items.service', 'invoice.payments', 'customer', 'reservationRoom.room'])->findOrFail($this->selectedId);
    }

    /** Kamar yang sedang ditempati: id baris reservasi => label. */
    private function kamarTerisi()
    {
        return ReservationRoom::with('room', 'reservation.customer')
            ->where('status', 'checked_in')->whereHas('reservation', fn ($q) => $q->where('status', 'checked_in'))
            ->get()->sortBy(fn ($b) => $b->room?->room_number)
            ->mapWithKeys(fn ($b) => [$b->id => 'Kamar '.$b->room?->room_number.' · '.$b->reservation->customer?->full_name]);
    }

    public function render(): View
    {
        $kata = trim($this->cari);
        $status = ['aktif' => ['pending', 'in_progress', 'ready'], 'selesai' => ['delivered'], 'batal' => ['cancelled']][$this->tab] ?? ['pending', 'in_progress', 'ready'];
        $daftar = $this->pesanan()
            ->with(['items.service', 'invoice', 'customer', 'reservationRoom.room'])
            ->whereIn('status', $status)
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('order_code', 'like', "%{$kata}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%"))
                ->orWhereHas('reservationRoom.room', fn ($r) => $r->where('room_number', $kata))))
            ->orderBy($this->tab === 'aktif' ? 'order_date' : 'updated_at', $this->tab === 'aktif' ? 'asc' : 'desc')
            ->limit(100)->get();

        $o = $this->selectedId ? $this->terpilih() : null;

        return view('livewire.staf.layanan.pesanan-laundry', [
            'daftar' => $daftar,
            'jumlah' => $this->pesanan()->whereIn('status', ['pending', 'in_progress', 'ready'])->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'o' => $o,
            'bolehKelola' => Gate::allows('manage-laundry'),
            'waUlang' => $o ? WhatsAppService::tautan($o->customer?->phone, app(NotifikasiLayanan::class)->teksLaundry($o)) : null,
            'kamarTerisi' => $this->formTerima ? $this->kamarTerisi() : collect(),
            'harga' => LaundryService::layanan()?->price,
        ])->layout('components.layouts.staf', ['title' => 'Laundry']);
    }
}
