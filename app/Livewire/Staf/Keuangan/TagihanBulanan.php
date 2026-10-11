<?php

namespace App\Livewire\Staf\Keuangan;

use App\Models\BillingStatement;
use App\Models\Institution;
use App\Models\Invoice;
use App\Services\FolioService;
use App\Services\PembayaranService;
use App\Services\TagihanBulananService;
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
 * Tagihan Bulanan (Manager): draft disusun otomatis setelah tanggal potong (atau "Susun sekarang"), lalu Manager
 * menerbitkan (PDF dikirim ke narahubung), mengeluarkan nota dari draft, membatalkan, dan mencatat pembayaran.
 */
class TagihanBulanan extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $instansi = '';

    /** Tanggal dalam periode (Y-m-d) */
    #[Url(except: '')]
    public string $periode = '';

    #[Locked]
    public ?int $selectedId = null;

    public bool $formSusun = false;

    public string $susunInstansi = '';

    public string $susunTanggal = '';

    public bool $formBayar = false;

    public string $jumlah = '';

    public string $metode = 'bank_transfer';

    public string $referensi = '';

    public function mount(): void
    {
        Gate::authorize('manage-receivables');
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['status', 'instansi', 'periode'], true)) {
            $this->resetPage();
        }
    }

    public function open(int $id): void
    {
        $this->selectedId = BillingStatement::findOrFail($id)->id;
        $this->reset('formBayar', 'jumlah', 'referensi');
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->reset('formBayar', 'jumlah', 'referensi');
    }

    public function bukaSusun(): void
    {
        $this->formSusun = true;
        $this->susunTanggal = today()->subDay()->toDateString();
        $this->resetValidation();
    }

    /** Susun sekarang per instansi dan periode (untuk nota susulan dan demo). */
    public function susun(TagihanBulananService $bulanan): void
    {
        Gate::authorize('manage-receivables');
        $this->validate([
            'susunInstansi' => ['required', Rule::exists('institutions', 'id')->where('default_billing_scheme', 'monthly_fixed_date')],
            'susunTanggal' => ['required', 'date'],
        ], ['susunInstansi.*' => 'Pilih instansi dengan tagihan bulanan.', 'susunTanggal.*' => 'Pilih tanggal dalam periode.']);
        $i = Institution::findOrFail((int) $this->susunInstansi);
        $p = $i->periodeTagihan(Carbon::parse($this->susunTanggal));
        if (! $p) {
            $this->addError('susunInstansi', 'Tanggal tagih instansi ini belum diatur.');

            return;
        }
        $s = $bulanan->susun($i, $p, Auth::user());
        if (! $s) {
            $this->addError('susunTanggal', 'Tidak ada nota kredit yang siap ditagih sampai '.$p['akhir']->translatedFormat('j M Y').'.');

            return;
        }
        $this->formSusun = false;
        $this->open($s->id);
        $this->dispatch('toast', teks: "Draft {$s->statement_number} disusun.");
    }

    public function terbitkan(TagihanBulananService $bulanan): void
    {
        Gate::authorize('manage-receivables');
        $kanal = $bulanan->terbitkan($this->terpilih(), Auth::user());
        $this->dispatch('toast', teks: $kanal === [] ? 'Tagihan diterbitkan, tetapi belum terkirim: narahubung tidak punya WhatsApp atau email.'
            : 'Tagihan diterbitkan dan dikirim lewat '.implode(' dan ', array_map(fn ($k) => $k === 'whatsapp' ? 'WhatsApp' : 'email', $kanal)).'.', peringatan: $kanal === []);
    }

    public function keluarkan(int $notaId, TagihanBulananService $bulanan): void
    {
        Gate::authorize('manage-receivables');
        $s = $this->terpilih();
        $bulanan->keluarkanNota($s, Invoice::where('billing_statement_id', $s->id)->findOrFail($notaId));
        $this->dispatch('toast', teks: 'Nota dikeluarkan; ikut tagihan periode berikutnya.');
    }

    public function batalkan(TagihanBulananService $bulanan): void
    {
        Gate::authorize('manage-receivables');
        $s = $bulanan->batalkan($this->terpilih());
        $this->dispatch('toast', teks: "{$s->statement_number} dibatalkan; notanya dilepas.");
    }

    public function bayar(PembayaranService $pembayaran): void
    {
        Gate::authorize('manage-receivables');
        $this->jumlah = preg_replace('/\D/', '', $this->jumlah);
        $this->validate([
            'jumlah' => ['required', 'numeric', 'min:1'],
            'metode' => ['required', Rule::in(array_keys(PiutangInstansi::METODE))],
            'referensi' => ['nullable', 'string', 'max:100'],
        ], ['jumlah.*' => 'Isi jumlah yang diterima.']);
        $pembayaran->catatTagihanBulanan($this->terpilih(), (float) $this->jumlah, $this->metode, Auth::user(), trim($this->referensi) !== '' ? 'Ref: '.trim($this->referensi) : null);
        $this->reset('formBayar', 'jumlah', 'referensi');
        $this->dispatch('toast', teks: 'Pembayaran tagihan bulanan dicatat.');
    }

    private function terpilih(): BillingStatement
    {
        return BillingStatement::findOrFail((int) $this->selectedId);
    }

    public function render(): View
    {
        $daftar = BillingStatement::with('institution')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->instansi !== '', fn ($q) => $q->where('institution_id', (int) $this->instansi))
            ->when($this->periode !== '' && strtotime($this->periode), fn ($q) => $q->whereDate('period_start', '<=', $this->periode)->whereDate('period_end', '>=', $this->periode))
            ->orderByDesc('period_end')->orderByDesc('id')->paginate(20);

        $s = $this->selectedId ? BillingStatement::with([
            'institution.primaryContact', 'issuedBy', 'payments.receivedBy',
            'invoices' => fn ($q) => $q->with('reservation.rooms.room', 'hallBooking.hall', 'customer', 'items')->orderBy('issue_date')->orderBy('id'),
        ])->find($this->selectedId) : null;

        return view('livewire.staf.keuangan.tagihan-bulanan', [
            'daftar' => $daftar,
            's' => $s,
            'instansiBulanan' => Institution::where('default_billing_scheme', 'monthly_fixed_date')->orderBy('name')->get(),
            'urlPdf' => $s ? route('staf.keuangan.tagihan-bulanan.pdf', $s) : null,
            'metodeBayar' => PiutangInstansi::METODE,
            'rp' => fn ($n) => FolioService::rp($n),
        ])->layout('components.layouts.staf', ['title' => 'Tagihan Bulanan']);
    }
}
