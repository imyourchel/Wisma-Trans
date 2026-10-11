<?php

namespace App\Livewire\Staf\Keuangan;

use App\Models\Payment;
use App\Services\FolioService;
use App\Services\PembayaranService;
use App\Support\RekapKas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pembayaran: daftar transaksi per tanggal dengan rekap kas (pengganti rekap Excel harian),
 * dan antrean "Perlu pengembalian dana" (refund pending) yang diselesaikan FO/F&B atau dihanguskan Manager.
 */
class Pembayaran extends Component
{
    use WithPagination;

    /** transaksi | refund */
    #[Url(except: 'transaksi')]
    public string $tab = 'transaksi';

    /** Y-m-d; bawaan hari ini */
    #[Url(except: '')]
    public string $tanggal = '';

    #[Url(except: '')]
    public string $metode = '';

    #[Url(except: '')]
    public string $kanal = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $tujuan = '';

    /* Antrean pengembalian dana */
    #[Locked]
    public ?int $refundId = null;

    /** selesai | hangus */
    public string $aksi = '';

    public string $metodeRefund = 'cash';

    public string $catatan = '';

    public function mount(): void
    {
        Gate::authorize('view-finance');
        if ($this->tanggal === '' || strtotime($this->tanggal) === false) {
            $this->tanggal = today()->toDateString();
        }
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['tanggal', 'metode', 'kanal', 'status', 'tujuan', 'tab'], true)) {
            $this->resetPage();
        }
    }

    public function mulai(int $id, string $aksi): void
    {
        Gate::authorize($aksi === 'hangus' ? 'approve-finance' : 'record-payment');
        $this->refundId = $this->antrean()->findOrFail($id)->id;
        $this->aksi = $aksi === 'hangus' ? 'hangus' : 'selesai';
        $this->reset('catatan');
        $this->metodeRefund = 'cash';
        $this->resetValidation();
    }

    public function tutup(): void
    {
        $this->reset('refundId', 'aksi', 'catatan');
        $this->resetValidation();
    }

    public function selesaikan(PembayaranService $pembayaran): void
    {
        Gate::authorize('record-payment');
        $this->validate([
            'metodeRefund' => ['required', Rule::in(array_keys(PembayaranService::METODE))],
            'catatan' => ['nullable', 'string', 'max:100'],
        ], ['metodeRefund.*' => 'Pilih metode pengembalian.']);
        $r = $pembayaran->selesaikanRefund($this->antrean()->findOrFail((int) $this->refundId), $this->metodeRefund, Auth::user(), trim($this->catatan) ?: null);
        $this->tutup();
        $this->dispatch('toast', teks: FolioService::rp($r->amount).' sudah dikembalikan.');
    }

    public function hanguskan(PembayaranService $pembayaran): void
    {
        Gate::authorize('approve-finance');
        $this->validate(['catatan' => ['required', 'string', 'max:100']], ['catatan.required' => 'Isi alasan menghanguskan.']);
        $pembayaran->hanguskanRefund($this->antrean()->findOrFail((int) $this->refundId), $this->catatan, Auth::user());
        $this->tutup();
        $this->dispatch('toast', teks: 'Pengembalian dana dihanguskan.', peringatan: true);
    }

    /** Refund pending yang boleh dilihat, terlama di atas. */
    private function antrean()
    {
        return Payment::query()->terlihatOleh(Auth::user())->where('transaction_type', 'refund')->where('status', 'pending');
    }

    public function render(): View
    {
        $tanggal = Carbon::parse($this->tanggal ?: today());
        $transaksi = Payment::query()->terlihatOleh(Auth::user())
            ->with(['invoice.customer', 'billingStatement', 'receivedBy'])
            ->whereRaw('DATE(COALESCE(paid_at, created_at)) = ?', [$tanggal->toDateString()])
            ->when($this->metode !== '', fn ($q) => $q->where('method', $this->metode))
            ->when($this->kanal !== '', fn ($q) => $q->where('channel', $this->kanal))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->tujuan === 'refund', fn ($q) => $q->where('transaction_type', 'refund'))
            ->when($this->tujuan !== '' && $this->tujuan !== 'refund', fn ($q) => $q->where('transaction_type', 'payment')->where('payment_purpose', $this->tujuan))
            ->orderByDesc('id')
            ->paginate(30);

        $antrean = $this->antrean()->with('invoice.customer')->orderBy('created_at')->orderBy('id')->get();

        return view('livewire.staf.keuangan.pembayaran', [
            'transaksi' => $transaksi,
            'rekap' => RekapKas::untuk($tanggal, Auth::user()),
            'antrean' => $antrean,
            'dipilih' => $this->refundId ? $antrean->firstWhere('id', $this->refundId) : null,
            'bolehCatat' => Gate::allows('record-payment'),
            'bolehHangus' => Gate::allows('approve-finance'),
            'metodeRefundPilihan' => PembayaranService::METODE,
        ])->layout('components.layouts.staf', ['title' => 'Pembayaran']);
    }
}
