<?php

namespace App\Livewire\Staf\Layanan;

use App\Models\ReservationRoom;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Rules\NomorHp;
use App\Services\Layanan\PesananMakananService;
use App\Services\Notifikasi\NotifikasiLayanan;
use App\Services\Notifikasi\WhatsAppService;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kasir F&B (F&B dan FO): mencatat pesanan langsung untuk tamu menginap (tagihan kamar atau bayar sekarang)
 * dan tamu luar (selalu bayar sekarang), serta menerima pembayaran pesanan yang belum lunas.
 */
class Kasir extends Component
{
    public string $cari = '';

    /** id menu => ['qty' => string, 'catatan' => string, 'harga' => string] */
    public array $keranjang = [];

    /** kamar | luar */
    public string $jenis = 'kamar';

    public string $kamar = '';

    public string $penyajian = 'dine_in';

    /** kamar | sekarang */
    public string $tagih = 'kamar';

    public string $nama = '';

    public string $hp = '';

    public string $metode = 'cash';

    public string $catatan = '';

    /** Pesanan tamu luar yang baru disimpan: tautan status untuk dikirim lewat WhatsApp. */
    #[Locked]
    public ?array $terakhir = null;

    #[Locked]
    public ?int $bayarId = null;

    public string $metodeBayar = 'cash';

    public function mount(): void
    {
        Gate::authorize('take-fnb-order');
    }

    public function updatedJenis(): void
    {
        $this->resetValidation();
        if ($this->jenis === 'luar') {
            $this->tagih = 'sekarang';
            if ($this->penyajian === 'room_service') {
                $this->penyajian = 'dine_in';
            }
        } else {
            $this->tagih = 'kamar';
        }
    }

    public function tambah(int $id): void
    {
        Gate::authorize('take-fnb-order');
        $m = Service::menuKafe()->findOrFail($id);
        if ($m->habis()) {
            $this->addError('keranjang', "{$m->name} sedang tidak tersedia.");

            return;
        }
        $baris = $this->keranjang[$id] ?? null;
        $this->keranjang[$id] = $baris
            ? ['qty' => (string) min(20, (int) $baris['qty'] + 1)] + $baris
            : ['qty' => '1', 'catatan' => '', 'harga' => ''];
        $this->resetValidation('keranjang');
    }

    public function hapus(int $id): void
    {
        unset($this->keranjang[$id]);
    }

    public function simpan(PesananMakananService $layanan, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('take-fnb-order');
        $kamar = $this->jenis === 'kamar';
        if (! $kamar) {
            $this->nama = PersonName::normalize($this->nama);
            $this->hp = Phone::normalizeInput($this->hp);
        }
        foreach ($this->keranjang as $id => $b) {
            $this->keranjang[$id]['harga'] = str_replace(['.', ' '], '', trim((string) ($b['harga'] ?? '')));
        }
        $this->validate([
            'keranjang' => ['required', 'array', 'min:1', 'max:30'],
            'keranjang.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'keranjang.*.catatan' => ['nullable', 'string', 'max:150'],
            'keranjang.*.harga' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'kamar' => [$kamar ? 'required' : 'nullable'],
            'penyajian' => ['required', Rule::in(array_keys(ServiceOrder::SERVING))],
            'tagih' => ['required', Rule::in(['kamar', 'sekarang'])],
            'nama' => [$kamar ? 'nullable' : 'required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'hp' => [$kamar ? 'nullable' : 'required', $kamar ? 'string' : new NomorHp],
            'catatan' => ['nullable', 'string', 'max:255'],
        ], [
            'keranjang.required' => 'Pilih minimal satu menu.',
            'keranjang.min' => 'Pilih minimal satu menu.',
            'keranjang.*.qty.*' => 'Jumlah 1–20.',
            'keranjang.*.catatan.max' => 'Catatan maksimal 150 karakter.',
            'keranjang.*.harga.*' => 'Isi harga per porsi dalam rupiah.',
            'kamar.required' => 'Pilih kamar yang sedang ditempati.',
            'nama.required' => 'Isi nama tamu.',
            'nama.min' => 'Minimal 3 huruf.',
            'nama.regex' => PersonName::MESSAGE,
            'hp.required' => 'Isi nomor WhatsApp tamu.',
            'catatan.max' => 'Catatan maksimal 255 karakter.',
        ]);

        $baris = $kamar ? ReservationRoom::with('reservation.customer')->find((int) $this->kamar) : null;
        if ($kamar && ! $baris) {
            $this->addError('kamar', 'Kamar ini tidak sedang ditempati.');

            return;
        }
        $pemesan = $kamar ? null : Pelanggan::dariIsian(['name' => $this->nama, 'phone' => $this->hp], null, 'hp');

        $order = $layanan->buatDariKasir(
            collect($this->keranjang)->map(fn ($b, $id) => [
                'id' => (int) $id, 'qty' => (int) $b['qty'],
                'harga' => ($b['harga'] ?? '') !== '' ? (float) $b['harga'] : null, 'catatan' => $b['catatan'] ?? null,
            ])->values()->all(),
            $baris, $pemesan, $this->penyajian, $kamar && $this->tagih === 'kamar',
            $kamar && $this->tagih === 'kamar' ? null : $this->metode, trim($this->catatan) ?: null, Auth::user(),
        );

        $this->terakhir = $order->order_type === 'outside_guest' ? [
            'kode' => $order->order_code,
            'url' => $order->urlStatus(),
            'wa' => WhatsAppService::tautan($order->customer?->phone, "Halo {$order->customer?->full_name}, pesanan {$order->order_code} sudah kami terima. Pantau pesanan: {$order->urlStatus()}"),
        ] : null;
        $this->reset('keranjang', 'catatan', 'nama', 'hp', 'kamar');
        $this->resetValidation();
        $this->dispatch('toast', teks: "Pesanan {$order->order_code} masuk dapur.");
    }

    public function mulaiBayar(int $id): void
    {
        Gate::authorize('take-fnb-order');
        $this->bayarId = $this->belumDibayar()->firstWhere('id', $id)?->id;
        $this->metodeBayar = 'cash';
        $this->resetValidation();
    }

    public function batalBayar(): void
    {
        $this->bayarId = null;
        $this->resetValidation();
    }

    public function tutupTerakhir(): void
    {
        $this->terakhir = null;
    }

    public function terimaBayar(PesananMakananService $layanan): void
    {
        Gate::authorize('take-fnb-order');
        $this->validate(['metodeBayar' => ['required', Rule::in(array_keys(PesananMakananService::METODE_KASIR))]], ['metodeBayar.*' => 'Pilih metode pembayaran.']);
        $o = PesananMakananService::query()->with('invoice')->findOrFail((int) $this->bayarId);
        $layanan->bayar($o, $this->metodeBayar, Auth::user());
        $this->bayarId = null;
        $this->dispatch('toast', teks: "Pembayaran {$o->order_code} diterima. Pesanan masuk dapur.");
    }

    /** Pesanan bayar-sekarang yang belum lunas (24 jam terakhir); yang kedaluwarsa dibatalkan otomatis. */
    private function belumDibayar(): Collection
    {
        return PesananMakananService::query()->with(['items.service', 'customer', 'reservationRoom.room', 'invoice'])
            ->where('status', 'pending')->where('payment_mode', 'pay_now')
            ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['paid', 'cancelled']))
            ->where('order_date', '>=', now()->subDay())->orderBy('order_date')->get()
            ->reject(fn ($o) => $o->batalkanBilaKedaluwarsa()->status === 'cancelled')->values();
    }

    /** Kamar yang sedang ditempati: id baris reservasi => "205 · Budi Santoso (RSV-0012)". */
    private function kamarTerisi(): Collection
    {
        return ReservationRoom::with('room', 'reservation.customer')
            ->where('status', 'checked_in')->whereHas('reservation', fn ($q) => $q->where('status', 'checked_in'))
            ->get()->sortBy(fn ($b) => $b->room?->room_number)
            ->mapWithKeys(fn ($b) => [$b->id => $b->room?->room_number.' · '.$b->reservation->customer?->full_name.' ('.$b->reservation->reservation_code.')']);
    }

    public function render(): View
    {
        $kata = mb_strtolower(trim($this->cari));
        $menu = Service::menuKafe()->orderBy('id')->get();
        $dipilih = $menu->whereIn('id', array_keys($this->keranjang))->keyBy('id');
        $subtotal = collect($this->keranjang)->sum(function ($b, $id) use ($dipilih) {
            $m = $dipilih->get($id);
            $harga = $m?->price !== null ? (float) $m->price : (float) str_replace(['.', ' '], '', (string) ($b['harga'] ?? ''));

            return $harga * max(0, (int) $b['qty']);
        });

        return view('livewire.staf.layanan.kasir', [
            'grup' => $menu->filter(fn ($m) => $kata === '' || str_contains(mb_strtolower($m->name), $kata))
                ->groupBy(fn ($m) => $m->menu_group ?: 'Lainnya'),
            'dipilih' => $dipilih,
            'subtotal' => $subtotal,
            'kamarTerisi' => $this->kamarTerisi(),
            'belumDibayar' => $this->belumDibayar(),
            'metodeKasir' => PesananMakananService::METODE_KASIR,
        ])->layout('components.layouts.staf', ['title' => 'Kasir']);
    }
}
