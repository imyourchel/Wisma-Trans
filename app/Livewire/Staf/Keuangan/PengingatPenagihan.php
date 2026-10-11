<?php

namespace App\Livewire\Staf\Keuangan;

use App\Models\BillingReminder;
use App\Services\Keuangan\PengingatPenagihan as Layanan;
use App\Services\Keuangan\TagihanBulananService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pengingat Penagihan (Manager): pengingat ke instansi menunggu diperiksa, lalu dikirim lewat email dan/atau WhatsApp,
 * ditunda, atau dibatalkan. Tagihan di atas ambang juga bisa dicetak surat tagihannya. Riwayat memuat semua pengingat.
 */
class PengingatPenagihan extends Component
{
    use WithPagination;

    /** periksa | tertunda | riwayat */
    #[Url(except: 'periksa')]
    public string $tab = 'periksa';

    #[Locked]
    public ?int $selectedId = null;

    public bool $kirimWa = true;

    public bool $kirimEmail = false;

    public string $hp = '';

    public string $email = '';

    public string $tanggalTunda = '';

    public function mount(): void
    {
        Gate::authorize('manage-receivables');
    }

    public function open(int $id): void
    {
        $p = $this->pending()->findOrFail($id);
        $p->tagihan()->loadMissing('institution.primaryContact');
        $tujuan = TagihanBulananService::penerima($p->tagihan()->institution);
        $this->selectedId = $p->id;
        $this->hp = (string) $tujuan['hp'];
        $this->email = (string) $tujuan['email'];
        $this->kirimWa = $this->hp !== '';
        $this->kirimEmail = $this->email !== '';
        $this->tanggalTunda = today()->addDays(3)->toDateString();
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->resetValidation();
    }

    public function kirim(Layanan $layanan): void
    {
        Gate::authorize('manage-receivables');
        $kanal = array_keys(array_filter(['whatsapp' => $this->kirimWa, 'email' => $this->kirimEmail]));
        $hasil = $layanan->periksaDanKirim($this->terpilih(), $kanal, trim($this->email) ?: null, trim($this->hp) ?: null, Auth::user());
        $gagal = array_keys(array_filter($hasil, fn ($ok) => ! $ok));
        $this->close();
        $this->dispatch('toast', teks: $gagal === [] ? 'Pengingat terkirim.' : 'Sebagian gagal terkirim: '.implode(', ', $gagal).'. Lihat Riwayat.', peringatan: $gagal !== []);
    }

    public function tunda(Layanan $layanan): void
    {
        Gate::authorize('manage-receivables');
        $layanan->tunda($this->terpilih(), $this->tanggalTunda);
        $this->close();
        $this->dispatch('toast', teks: 'Pengingat ditunda.');
    }

    public function batalkan(int $id, Layanan $layanan): void
    {
        Gate::authorize('manage-receivables');
        $layanan->batalkan($this->pending()->findOrFail($id));
        if ($this->selectedId === $id) {
            $this->close();
        }
        $this->dispatch('toast', teks: 'Pengingat dibatalkan.');
    }

    public function suratTerkirim(Layanan $layanan): void
    {
        Gate::authorize('manage-receivables');
        $layanan->suratTerkirim($this->terpilih()->tagihan(), Auth::user());
        $this->dispatch('toast', teks: 'Surat tagihan dicatat terkirim.');
    }

    private function pending()
    {
        return BillingReminder::where('reminder_stage', 'to_institution')->where('status', 'pending');
    }

    private function terpilih(): BillingReminder
    {
        return $this->pending()->findOrFail((int) $this->selectedId);
    }

    public function render(Layanan $layanan): View
    {
        $relasi = ['invoice.institution', 'billingStatement.institution'];
        $periksa = $this->pending()->with($relasi)->whereDate('scheduled_date', '<=', today())->orderBy('scheduled_date')->get();
        $tertunda = $this->pending()->with($relasi)->whereDate('scheduled_date', '>', today())->orderBy('scheduled_date')->get();
        $riwayat = $this->tab === 'riwayat'
            ? BillingReminder::with([...$relasi, 'verifiedBy'])->orderByDesc('id')->paginate(30) : null;
        $p = $this->selectedId ? $this->pending()->with($relasi)->find($this->selectedId) : null;

        return view('livewire.staf.keuangan.pengingat-penagihan', [
            'periksa' => $periksa,
            'tertunda' => $tertunda,
            'riwayat' => $riwayat,
            'p' => $p,
            't' => $p?->tagihan(),
            'pratinjau' => $p ? $layanan->teks($p->tagihan()) : null,
            'perluSurat' => $p && Layanan::perluSurat($p->tagihan()),
            'urlSurat' => $p ? route('staf.keuangan.surat-tagihan', ['jenis' => $p->invoice_id ? 'nota' : 'bulanan', 'id' => $p->invoice_id ?? $p->billing_statement_id]) : null,
        ])->layout('components.layouts.staf', ['title' => 'Pengingat Penagihan']);
    }
}
