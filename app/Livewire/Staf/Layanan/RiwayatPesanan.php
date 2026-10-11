<?php

namespace App\Livewire\Staf\Layanan;

use App\Livewire\Staf\Layanan\Concerns\DetailPesanan;
use App\Models\ServiceOrderItem;
use App\Services\PesananMakananService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Riwayat pesanan makanan (F&B, Manager): penyaring, ringkasan sesuai saringan, dan detail pesanan. Tanpa laundry. */
class RiwayatPesanan extends Component
{
    use DetailPesanan, WithPagination;

    public const BAYAR = ['lunas' => 'Lunas', 'tagihan-kamar' => 'Tagihan kamar', 'belum-dibayar' => 'Belum dibayar', 'dibatalkan' => 'Dibatalkan'];

    public const JENIS = ['in_house' => 'Tamu menginap', 'outside_guest' => 'Tamu luar'];

    /** Y-m-d; kosong = semua tanggal */
    #[Url(except: '')]
    public string $tanggal = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $jenis = '';

    #[Url(except: '')]
    public string $bayar = '';

    #[Url(except: '')]
    public string $cari = '';

    public function mount(): void
    {
        Gate::authorize('view-fnb');
        // Bawaan hari ini; ?tanggal=semua menampilkan semua tanggal
        $this->tanggal = match (true) {
            $this->tanggal === 'semua' => '',
            $this->tanggal !== '' && strtotime($this->tanggal) !== false => Carbon::parse($this->tanggal)->toDateString(),
            default => today()->toDateString(),
        };
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['tanggal', 'status', 'jenis', 'bayar', 'cari'], true)) {
            $this->resetPage();
        }
    }

    public function resetSaringan(): void
    {
        $this->reset('status', 'jenis', 'bayar', 'cari');
        $this->tanggal = today()->toDateString();
        $this->resetPage();
    }

    private function saring(): Builder
    {
        $kata = trim($this->cari);
        $lunas = fn ($q) => $q->where('status', 'paid');

        return PesananMakananService::query()
            ->when($this->tanggal !== '' && strtotime($this->tanggal) !== false, fn ($q) => $q->whereDate('order_date', $this->tanggal))
            ->when(array_key_exists($this->status, PesananMakananService::STATUS), fn ($q) => $q->where('status', $this->status))
            ->when(array_key_exists($this->jenis, self::JENIS), fn ($q) => $q->where('order_type', $this->jenis))
            ->when($this->bayar !== '', fn ($q) => match ($this->bayar) {
                'lunas' => $q->where('status', '!=', 'cancelled')->where('payment_mode', 'pay_now')->whereHas('invoice', $lunas),
                'tagihan-kamar' => $q->where('status', '!=', 'cancelled')->where('payment_mode', 'charge_to_room'),
                'belum-dibayar' => $q->where('status', '!=', 'cancelled')->where('payment_mode', 'pay_now')->whereDoesntHave('invoice', $lunas),
                'dibatalkan' => $q->where('status', 'cancelled'),
                default => $q,
            })
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('order_code', 'like', "%{$kata}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%"))));
    }

    /** Ringkasan sesuai saringan: jumlah, total lunas, total tagihan kamar, dibatalkan, dan 5 menu terlaris. */
    private function ringkasan(): array
    {
        $id = fn () => $this->saring()->select('service_orders.id');
        $item = fn () => ServiceOrderItem::whereIn('service_order_id', $id());
        $aktif = fn () => $this->saring()->where('status', '!=', 'cancelled');

        return [
            'jumlah' => $this->saring()->count(),
            'lunas' => (float) ServiceOrderItem::whereIn('service_order_id', $aktif()->where('payment_mode', 'pay_now')
                ->whereHas('invoice', fn ($q) => $q->where('status', 'paid'))->select('service_orders.id'))->sum('subtotal'),
            'tagihanKamar' => (float) ServiceOrderItem::whereIn('service_order_id', $aktif()->where('payment_mode', 'charge_to_room')
                ->select('service_orders.id'))->sum('subtotal'),
            'batal' => $this->saring()->where('status', 'cancelled')->count(),
            'terlaris' => $item()->whereIn('service_order_id', $aktif()->select('service_orders.id'))
                ->join('services as s', 's.id', '=', 'service_order_items.service_id')
                ->groupBy('s.id', 's.name')->selectRaw('s.name, SUM(service_order_items.quantity) porsi')
                ->orderByDesc('porsi')->orderBy('s.name')->limit(5)->get(),
        ];
    }

    public function render(): View
    {
        return view('livewire.staf.layanan.riwayat-pesanan', [
            'daftar' => $this->saring()->with(self::RELASI)->orderByDesc('order_date')->orderByDesc('id')->paginate(25),
            'ringkasan' => $this->ringkasan(),
            ...$this->dataDetail(),
        ])->layout('components.layouts.staf', ['title' => 'Riwayat Pesanan']);
    }
}
