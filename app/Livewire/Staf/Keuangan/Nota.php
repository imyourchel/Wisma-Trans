<?php

namespace App\Livewire\Staf\Keuangan;

use App\Livewire\Staf\Concerns\QrisMeja;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Keuangan\KoreksiNota;
use App\Services\Keuangan\NotaPdf;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Keuangan\PembayaranService;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\FolioService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Nota (Manager, FO, F&B sebatas nota makanan): daftar dengan penyaring, detail beserta sumber dan pembayaran,
 * pencatatan pembayaran dan QRIS di meja, kirim ulang ke tamu, serta diskon, koreksi baris, dan pembatalan oleh Manager.
 */
class Nota extends Component
{
    use QrisMeja, WithPagination;

    /** '' | stay | service | hall */
    #[Url(except: '')]
    public string $jenis = '';

    /** '' | belum | lewat | lunas | batal */
    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    #[Url(except: '')]
    public string $instansi = '';

    /** '' | immediate | credit */
    #[Url(except: '')]
    public string $termin = '';

    #[Url(except: '')]
    public string $cari = '';

    #[Locked]
    public ?int $selectedId = null;

    /** '' | bayar | diskon | koreksi | hapus | batal */
    public string $aksi = '';

    #[Locked]
    public ?int $barisId = null;

    public string $jumlah = '';

    public string $metode = 'cash';

    public string $diskonJenis = 'percent';

    public string $diskonNilai = '';

    public string $barisJumlah = '';

    public string $barisHarga = '';

    public string $alasan = '';

    public function mount(): void
    {
        Gate::authorize('view-finance');
        // Dari halaman Pembayaran: ?lihat=12 membuka detail nota itu
        if ($id = request()->integer('lihat')) {
            $this->open($id);
        }
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['jenis', 'status', 'dari', 'sampai', 'instansi', 'termin', 'cari'], true)) {
            $this->resetPage();
        }
    }

    public function resetSaringan(): void
    {
        $this->reset('jenis', 'status', 'dari', 'sampai', 'instansi', 'termin', 'cari');
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $this->selectedId = $this->terlihat()->findOrFail($id)->id;
        $this->tutupAksi();
        $this->qris = null;
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->qris = null;
        $this->tutupAksi();
    }

    public function tutupAksi(): void
    {
        $this->reset('aksi', 'barisId', 'jumlah', 'diskonNilai', 'barisJumlah', 'barisHarga', 'alasan');
        $this->resetValidation();
    }

    public function mulai(string $aksi, ?int $baris = null): void
    {
        Gate::authorize(in_array($aksi, ['bayar'], true) ? 'record-payment' : 'approve-finance');
        $this->tutupAksi();
        if (! in_array($aksi, ['bayar', 'diskon', 'koreksi', 'hapus', 'batal'], true)) {
            return;
        }
        $n = $this->terpilih();
        if ($baris !== null) {
            $item = $n->items()->findOrFail($baris);
            $this->barisId = $item->id;
            $this->barisJumlah = rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.');
            $this->barisHarga = (string) (int) round((float) $item->unit_price);
        }
        $this->aksi = $aksi;
    }

    public function bayar(PembayaranService $pembayaran): void
    {
        Gate::authorize('record-payment');
        $this->validate([
            'metode' => ['required', Rule::in(array_keys(PembayaranService::METODE))],
            'jumlah' => ['nullable', 'regex:/^[0-9.\s]+$/'],
        ], ['metode.*' => 'Pilih metode pembayaran.', 'jumlah.regex' => 'Isi angka, misalnya 350000.']);
        $n = $this->terpilih();
        if ($galat = $this->penghalangBayar($n)) {
            $this->addError('jumlah', $galat);

            return;
        }
        $jumlah = $this->jumlah === '' ? $pembayaran->sisaTagihan($n) : (float) preg_replace('/\D/', '', $this->jumlah);
        $pembayaran->catatManual($n, $jumlah, $this->metode, PembayaranService::tujuan($n), Auth::user(), 'Dicatat di menu Nota');
        $this->tutupAksi();
        $this->qris = null;
        $this->dispatch('toast', teks: 'Pembayaran '.FolioService::rp($jumlah).' dicatat.');
    }

    public function mulaiQris(PembayaranService $pembayaran): void
    {
        Gate::authorize('record-payment');
        $n = $this->terpilih();
        if ($galat = $this->penghalangBayar($n)) {
            $this->addError('jumlah', $galat);

            return;
        }
        $this->tampilkanQris($n, (int) round($pembayaran->sisaTagihan($n)));
    }

    public function kirimUlang(NotifikasiReservasi $notifikasi): void
    {
        Gate::authorize('record-payment');
        $kanal = $notifikasi->kirimNota($this->terpilih());
        $this->dispatch('toast', teks: $kanal === [] ? 'Pelanggan tidak punya WhatsApp atau email yang bisa dihubungi.' : 'Nota dikirim lewat '.implode(' dan ', array_map(fn ($k) => $k === 'whatsapp' ? 'WhatsApp' : 'email', $kanal)).'.',
            peringatan: $kanal === []);
    }

    public function simpanDiskon(KoreksiNota $koreksi): void
    {
        Gate::authorize('approve-finance');
        $this->validate([
            'diskonJenis' => ['required', Rule::in(['percent', 'amount'])],
            'diskonNilai' => ['required', 'numeric', 'min:0.01'],
            'alasan' => ['required', 'string', 'max:150'],
        ], ['diskonNilai.*' => 'Isi nilai diskon.', 'alasan.required' => 'Isi alasan diskon.']);
        $koreksi->diskon($this->terpilih(), $this->diskonJenis, (float) $this->diskonNilai, $this->alasan, Auth::user());
        $this->tutupAksi();
        $this->dispatch('toast', teks: 'Diskon nota disimpan.');
    }

    public function simpanBaris(KoreksiNota $koreksi): void
    {
        Gate::authorize('approve-finance');
        $this->barisJumlah = str_replace(',', '.', trim($this->barisJumlah));
        $this->validate([
            'barisJumlah' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'barisHarga' => ['required', 'numeric', 'min:0'],
            'alasan' => ['required', 'string', 'max:150'],
        ], ['barisJumlah.*' => 'Isi jumlah.', 'barisHarga.*' => 'Isi harga.', 'alasan.required' => 'Isi alasan koreksi.']);
        $koreksi->ubahBaris($this->baris(), (float) $this->barisJumlah, (float) $this->barisHarga, $this->alasan, Auth::user());
        $this->tutupAksi();
        $this->dispatch('toast', teks: 'Baris nota dikoreksi.');
    }

    public function hapusBaris(KoreksiNota $koreksi): void
    {
        Gate::authorize('approve-finance');
        $this->validate(['alasan' => ['required', 'string', 'max:150']], ['alasan.required' => 'Isi alasan menghapus baris.']);
        $koreksi->hapusBaris($this->baris(), $this->alasan, Auth::user());
        $this->tutupAksi();
        $this->dispatch('toast', teks: 'Baris nota dihapus.');
    }

    public function batalkanNota(KoreksiNota $koreksi): void
    {
        Gate::authorize('approve-finance');
        $this->validate(['alasan' => ['required', 'string', 'max:150']], ['alasan.required' => 'Isi alasan pembatalan.']);
        $n = $koreksi->batalkan($this->terpilih(), $this->alasan, Auth::user());
        $this->tutupAksi();
        $this->dispatch('toast', teks: "Nota {$n->invoice_number} dibatalkan.");
    }

    private function penghalangBayar(Invoice $n): ?string
    {
        return match (true) {
            $n->status === 'cancelled' => 'Nota ini sudah dibatalkan.',
            $n->billing_statement_id !== null => 'Bagian dari tagihan bulanan '.$n->billingStatement?->statement_number.'; dibayar lewat tagihan bulanannya.',
            default => null,
        };
    }

    /** Nota yang boleh dilihat pengguna ini. */
    private function terlihat(): Builder
    {
        return Invoice::query()->terlihatOleh(Auth::user());
    }

    private function terpilih(): Invoice
    {
        abort_if($this->selectedId === null, 404);

        return $this->terlihat()->with(['reservation', 'billingStatement'])->findOrFail($this->selectedId);
    }

    private function baris(): InvoiceItem
    {
        return $this->terpilih()->items()->findOrFail((int) $this->barisId);
    }

    public function render(PembayaranService $pembayaran, KoreksiNota $koreksi): View
    {
        $kata = trim($this->cari);
        $angka = preg_replace('/\D/', '', $kata);
        $belum = ['open', 'issued', 'partially_paid', 'overdue'];
        $daftar = $this->terlihat()
            ->with(['customer', 'institution'])
            ->when(in_array($this->jenis, ['stay', 'service', 'hall'], true), fn ($q) => $q->where('invoice_type', $this->jenis))
            ->when(in_array($this->termin, ['immediate', 'credit'], true), fn ($q) => $q->where('payment_term', $this->termin))
            ->when($this->instansi !== '', fn ($q) => $q->where('institution_id', (int) $this->instansi))
            ->when($this->dari !== '' && strtotime($this->dari), fn ($q) => $q->whereDate('issue_date', '>=', $this->dari))
            ->when($this->sampai !== '' && strtotime($this->sampai), fn ($q) => $q->whereDate('issue_date', '<=', $this->sampai))
            ->when($this->status === 'belum', fn ($q) => $q->whereIn('status', $belum))
            ->when($this->status === 'lewat', fn ($q) => $q->whereIn('status', $belum)->whereDate('due_date', '<', today()))
            ->when($this->status === 'lunas', fn ($q) => $q->where('status', 'paid'))
            ->when($this->status === 'batal', fn ($q) => $q->where('status', 'cancelled'))
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('invoice_number', 'like', "%{$kata}%")
                ->orWhereHas('reservation', fn ($r) => $r->where('reservation_code', 'like', "%{$kata}%"))
                ->orWhereHas('serviceOrders', fn ($o) => $o->where('order_code', 'like', "%{$kata}%"))
                ->orWhereHas('hallBooking', fn ($b) => $b->where('booking_code', 'like', "%{$kata}%"))
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%")
                    ->when(strlen($angka) >= 4, fn ($c) => $c->orWhere('phone', 'like', "%{$angka}%")))
                ->orWhereHas('institution', fn ($i) => $i->where('name', 'like', "%{$kata}%"))))
            ->orderByDesc('id')
            ->paginate(20);

        $kartu = [
            'belum' => $this->terlihat()->whereIn('status', $belum)->whereColumn('paid_amount', '<', 'total_amount'),
            'lewat' => $this->terlihat()->whereIn('status', $belum)->whereDate('due_date', '<', today()),
            'lunas' => $this->terlihat()->where('status', 'paid')
                ->whereHas('payments', fn ($p) => $p->where('transaction_type', 'payment')->where('status', 'success')->whereDate('paid_at', today())),
        ];

        $n = $this->selectedId ? $this->terlihat()->with([
            'items', 'customer', 'institution', 'reservation', 'billingStatement', 'hallBooking.hall', 'serviceOrders.items.service',
            'payments' => fn ($q) => $q->with('receivedBy')->orderBy('id'),
        ])->find($this->selectedId) : null;

        return view('livewire.staf.keuangan.nota', [
            'daftar' => $daftar,
            'kartu' => [
                'belum' => ['Belum lunas', $kartu['belum']->count(), FolioService::rp((clone $kartu['belum'])->get()->sum(fn ($x) => $x->sisa())), 'belum'],
                'lewat' => ['Lewat jatuh tempo', $kartu['lewat']->count(), null, 'lewat'],
                'lunas' => ['Lunas hari ini', $kartu['lunas']->count(), null, 'lunas'],
            ],
            'perluRefund' => PembayaranMidtrans::jumlahPerluRefund(Auth::user()),
            'daftarInstansi' => Gate::allows('approve-finance') || Auth::user()->hasRole('front_office') ? Institution::orderBy('name')->pluck('name', 'id') : collect(),
            'n' => $n,
            'sisa' => $n ? $pembayaran->sisaTagihan($n) : 0,
            'lebih' => $n ? $pembayaran->lebihBayar($n) : 0,
            'refundTertunda' => $n ? $pembayaran->refundTertunda($n) : null,
            'penghalang' => $n ? $koreksi->penghalang($n) : null,
            'penghalangBayar' => $n ? $this->penghalangBayar($n) : null,
            'urlPdf' => $n ? app(NotaPdf::class)->tautan($n) : null,
            'bolehCatat' => Gate::allows('record-payment'),
            'bolehUbah' => Gate::allows('approve-finance'),
            'midtransSiap' => PembayaranMidtrans::siap(),
            'metodeBayar' => PembayaranService::METODE,
        ])->layout('components.layouts.staf', ['title' => 'Nota']);
    }
}
