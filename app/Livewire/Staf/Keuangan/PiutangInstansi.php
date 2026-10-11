<?php

namespace App\Livewire\Staf\Keuangan;

use App\Models\BillingStatement;
use App\Models\Institution;
use App\Models\Invoice;
use App\Services\FolioService;
use App\Services\PembayaranService;
use App\Services\PengingatPenagihan;
use App\Services\PiutangService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Piutang Instansi (Manager): sisa piutang per instansi dengan umur piutang, lalu detail tagihan terbuka,
 * riwayat pembayaran, dan narahubung. Pembayaran transfer/tunai dicatat per tagihan (nota kredit atau tagihan bulanan).
 */
class PiutangInstansi extends Component
{
    public const METODE = ['bank_transfer' => 'Transfer bank', 'cash' => 'Tunai'];

    #[Locked]
    public ?int $instansiId = null;

    /** nota | bulanan */
    #[Locked]
    public string $bayarJenis = '';

    #[Locked]
    public ?int $bayarId = null;

    public string $jumlah = '';

    public string $metode = 'bank_transfer';

    public string $referensi = '';

    public function mount(): void
    {
        Gate::authorize('manage-receivables');
        // Dari halaman Instansi: ?lihat=3 membuka detail piutang instansi itu
        if ($id = request()->integer('lihat')) {
            $this->instansiId = Institution::find($id)?->id;
        }
    }

    public function open(int $id): void
    {
        $this->instansiId = Institution::findOrFail($id)->id;
        $this->tutupBayar();
    }

    public function close(): void
    {
        $this->instansiId = null;
        $this->tutupBayar();
    }

    public function tutupBayar(): void
    {
        $this->reset('bayarJenis', 'bayarId', 'jumlah', 'referensi');
        $this->metode = 'bank_transfer';
        $this->resetValidation();
    }

    public function mulaiBayar(string $jenis, int $id): void
    {
        Gate::authorize('manage-receivables');
        $t = $this->tagihan($jenis, $id);
        $this->tutupBayar();
        $this->bayarJenis = $jenis;
        $this->bayarId = $t->id;
        $this->jumlah = (string) (int) round(PengingatPenagihan::sisa($t));
    }

    public function bayar(PembayaranService $pembayaran): void
    {
        Gate::authorize('manage-receivables');
        $this->jumlah = preg_replace('/\D/', '', $this->jumlah);
        $this->validate([
            'jumlah' => ['required', 'numeric', 'min:1'],
            'metode' => ['required', Rule::in(array_keys(self::METODE))],
            'referensi' => ['nullable', 'string', 'max:100'],
        ], ['jumlah.*' => 'Isi jumlah yang diterima.', 'metode.*' => 'Pilih metode.']);
        $t = $this->tagihan($this->bayarJenis, (int) $this->bayarId);
        $catatan = trim($this->referensi) !== '' ? 'Ref: '.trim($this->referensi) : null;
        $t instanceof Invoice
            ? $pembayaran->catatManual($t, (float) $this->jumlah, $this->metode, PembayaranService::tujuan($t), Auth::user(), $catatan)
            : $pembayaran->catatTagihanBulanan($t, (float) $this->jumlah, $this->metode, Auth::user(), $catatan);
        $this->dispatch('toast', teks: 'Pembayaran '.FolioService::rp($this->jumlah).' untuk '.PengingatPenagihan::nomor($t).' dicatat.');
        $this->tutupBayar();
    }

    public function kirimPengingat(string $jenis, int $id, PengingatPenagihan $pengingat): void
    {
        Gate::authorize('manage-receivables');
        $t = $this->tagihan($jenis, $id);
        $pengingat->kirimSekarang($t);
        $this->dispatch('toast', teks: 'Pengingat '.PengingatPenagihan::nomor($t).' menunggu diperiksa di Pengingat Penagihan.');
    }

    private function tagihan(string $jenis, int $id): Invoice|BillingStatement
    {
        $t = $jenis === 'nota'
            ? Invoice::where('payment_term', 'credit')->whereNull('billing_statement_id')->findOrFail($id)
            : BillingStatement::findOrFail($id);
        abort_if($this->instansiId !== null && $t->institution_id !== $this->instansiId, 404);

        return $t;
    }

    public function render(PiutangService $piutang): View
    {
        $i = $this->instansiId ? Institution::with('primaryContact')->find($this->instansiId) : null;

        return view('livewire.staf.keuangan.piutang-instansi', [
            'ringkasan' => $piutang->ringkasan(),
            'daftar' => $piutang->perInstansi(),
            'i' => $i,
            'terbuka' => $i ? $piutang->tagihanTerbuka($i->id) : collect(),
            'riwayat' => $i ? $piutang->pembayaranInstansi($i->id)->take(20) : collect(),
            'metodeBayar' => self::METODE,
        ])->layout('components.layouts.staf', ['title' => 'Piutang Instansi']);
    }
}
