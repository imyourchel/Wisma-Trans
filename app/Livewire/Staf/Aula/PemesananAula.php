<?php

namespace App\Livewire\Staf\Aula;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Institution;
use App\Rules\NomorHp;
use App\Services\Keuangan\NotaPdf;
use App\Services\Layanan\AulaService;
use App\Services\Notifikasi\NotifikasiLayanan;
use App\Services\Notifikasi\WhatsAppService;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Pemesanan Aula. FO mencatat pemesanan, mengisi harga usulan, menandai selesai, dan membatalkan;
 * Manager menyetujui dengan harga akhir (nota perorangan atau ditagihkan ke instansi) atau menolak dengan alasan.
 * Pengguna tanpa manage-halls/approve-halls (F&B) hanya melihat jadwal yang sudah terkonfirmasi, untuk persiapan
 * konsumsi: tanpa tab Menunggu, tanpa nomor WhatsApp penyewa, harga, nota, dan aksi apa pun.
 */
class PemesananAula extends Component
{
    /** Status yang boleh dilihat pengguna lihat saja. */
    public const STATUS_LIHAT_SAJA = ['confirmed', 'completed'];

    /** menunggu | terkonfirmasi | riwayat */
    #[Url(except: 'menunggu')]
    public string $tab = 'menunggu';

    /** Buka detail pemesanan tertentu, misalnya dari Kalender Aula. */
    #[Url(as: 'buka', except: null)]
    #[Locked]
    public ?int $selectedId = null;

    /** harga | setuju | tolak | batal */
    public string $aksi = '';

    public string $harga = '';

    /** perorangan | instansi */
    public string $tagih = 'perorangan';

    public string $instansi = '';

    public string $alasan = '';

    /* Formulir pemesanan baru */
    public bool $formBaru = false;

    public array $baru = [];

    public function mount(): void
    {
        Gate::authorize('view-halls');
        if ($this->selectedId && ! $this->terlihat()->whereKey($this->selectedId)->exists()) {
            $this->selectedId = null;
        }
        if (self::lihatSaja() && $this->tab === 'menunggu') {
            $this->tab = 'terkonfirmasi';
        }
    }

    /** Tidak bisa mengelola maupun menyetujui (F&B): hanya melihat jadwal terkonfirmasi. */
    public static function lihatSaja(): bool
    {
        return ! Gate::any(['manage-halls', 'approve-halls']);
    }

    /** Pemesanan yang boleh dilihat pengguna ini. */
    private function terlihat()
    {
        return HallBooking::query()->when(self::lihatSaja(), fn ($q) => $q->whereIn('status', self::STATUS_LIHAT_SAJA));
    }

    public function updatedTab(): void
    {
        if (self::lihatSaja() && $this->tab === 'menunggu') {
            $this->tab = 'terkonfirmasi';
        }
    }

    public function open(int $id): void
    {
        $this->selectedId = $this->terlihat()->findOrFail($id)->id;
        $this->tutupAksi();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->tutupAksi();
    }

    public function tutupAksi(): void
    {
        $this->reset('aksi', 'harga', 'tagih', 'instansi', 'alasan');
        $this->resetValidation();
    }

    public function mulai(string $aksi): void
    {
        Gate::authorize(in_array($aksi, ['setuju', 'tolak'], true) ? 'approve-halls' : 'manage-halls');
        $b = $this->terpilih();
        $this->tutupAksi();
        $this->aksi = in_array($aksi, ['harga', 'setuju', 'tolak', 'batal'], true) ? $aksi : '';
        $this->harga = $b->hargaDisepakati() ? (string) (int) $b->rental_price : '';
        $inst = $b->customer?->institution;
        $this->tagih = $inst || str_contains((string) $b->note, 'Instansi: ') ? 'instansi' : 'perorangan';
        $this->instansi = $inst ? (string) $inst->id : '';
    }

    public function simpanHarga(AulaService $layanan): void
    {
        Gate::authorize('manage-halls');
        $this->validate(['harga' => 'required|numeric|min:1000'], ['harga.*' => 'Isi harga usulan dalam rupiah.']);
        $layanan->ubahHarga($this->terpilih(), (float) $this->harga);
        $this->selesai('Harga usulan disimpan.');
    }

    public function setujui(AulaService $layanan, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('approve-halls');
        $this->validate([
            'harga' => 'required|numeric|min:1000',
            'tagih' => ['required', Rule::in(['perorangan', 'instansi'])],
            'instansi' => [$this->tagih === 'instansi' ? 'required' : 'nullable', Rule::exists('institutions', 'id')],
        ], ['harga.*' => 'Isi harga sewa yang disepakati.', 'instansi.required' => 'Pilih instansi yang ditagih.']);
        $b = $this->terpilih();
        $layanan->setujui($b, (float) $this->harga, $this->tagih === 'instansi' ? Institution::find((int) $this->instansi) : null, null, Auth::user());
        $notifikasi->aula($b->fresh());
        $this->selesai("Pemesanan {$b->booking_code} disetujui.");
    }

    public function tolak(AulaService $layanan, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('approve-halls');
        $this->validate(['alasan' => 'required|string|max:150'], ['alasan.required' => 'Isi alasan; dikirim ke penyewa.']);
        $b = $this->terpilih();
        $layanan->tolak($b, trim($this->alasan));
        $notifikasi->aula($b->fresh());
        $this->selesai("Pemesanan {$b->booking_code} ditolak.");
    }

    public function batalkan(AulaService $layanan, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('manage-halls');
        $this->validate(['alasan' => 'required|string|max:150'], ['alasan.required' => 'Isi alasan pembatalan.']);
        $b = $this->terpilih();
        $layanan->batalkan($b, trim($this->alasan));
        $notifikasi->aula($b->fresh());
        $this->selesai("Pemesanan {$b->booking_code} dibatalkan.");
    }

    public function tandaiSelesai(AulaService $layanan): void
    {
        Gate::authorize('manage-halls');
        $layanan->selesai($this->terpilih());
        $this->selesai('Acara ditandai selesai.');
    }

    public function bukaBaru(): void
    {
        Gate::authorize('manage-halls');
        $this->baru = ['aula' => (string) Hall::where('is_active', true)->orderBy('id')->value('id'), 'nama' => '', 'hp' => '', 'instansi' => '',
            'tanggal' => '', 'mulai' => '08:00', 'tanggal_selesai' => '', 'selesai' => '12:00', 'peserta' => '', 'keperluan' => '', 'harga' => '', 'catatan' => ''];
        $this->resetValidation();
        $this->formBaru = true;
    }

    public function simpanBaru(AulaService $layanan, NotifikasiLayanan $notifikasi): void
    {
        Gate::authorize('manage-halls');
        $this->baru['nama'] = PersonName::normalize((string) ($this->baru['nama'] ?? ''));
        $this->baru['hp'] = Phone::normalizeInput((string) ($this->baru['hp'] ?? ''));
        $this->validate([
            'baru.aula' => ['required', Rule::exists('halls', 'id')],
            'baru.nama' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'baru.hp' => ['required', new NomorHp],
            'baru.instansi' => ['nullable', 'string', 'max:150'],
            'baru.tanggal' => ['required', 'date_format:Y-m-d'],
            'baru.mulai' => ['required', 'date_format:H:i'],
            'baru.tanggal_selesai' => ['nullable', 'date_format:Y-m-d'],
            'baru.selesai' => ['required', 'date_format:H:i'],
            'baru.peserta' => ['required', 'integer', 'min:1'],
            'baru.keperluan' => ['required', 'string', 'max:150'],
            'baru.harga' => ['nullable', 'numeric', 'min:0'],
            'baru.catatan' => ['nullable', 'string', 'max:200'],
        ], [
            'baru.nama.required' => 'Isi nama penyewa.',
            'baru.nama.regex' => PersonName::MESSAGE,
            'baru.hp.required' => 'Isi nomor WhatsApp.',
            'baru.tanggal.required' => 'Pilih tanggal.',
            'baru.peserta.*' => 'Isi jumlah peserta.',
            'baru.keperluan.required' => 'Isi jenis acara.',
        ]);
        try {
            $mulai = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->baru['tanggal'].' '.$this->baru['mulai']);
            $selesai = CarbonImmutable::createFromFormat('Y-m-d H:i', ($this->baru['tanggal_selesai'] ?: $this->baru['tanggal']).' '.$this->baru['selesai']);
        } catch (Throwable) {
            $this->addError('baru.mulai', 'Waktu tidak valid.');

            return;
        }
        $penyewa = Pelanggan::dariIsian(['name' => $this->baru['nama'], 'phone' => $this->baru['hp']], null, 'baru.hp');
        $catatan = collect([$this->baru['instansi'] ? 'Instansi: '.trim($this->baru['instansi']) : null, trim((string) $this->baru['catatan']) ?: null])->filter()->join("\n");
        try {
            $b = $layanan->ajukan($penyewa, Hall::findOrFail((int) $this->baru['aula']), $mulai, $selesai, (int) $this->baru['peserta'],
                trim($this->baru['keperluan']), $catatan ?: null, $this->baru['harga'] !== '' ? (float) $this->baru['harga'] : null, Auth::user());
        } catch (ValidationException $e) {
            // Galat jadwal dari layanan ditampilkan di kolom formulir ini
            foreach ($e->errors() as $kunci => $pesan) {
                $this->addError('baru.'.$kunci, $pesan[0]);
            }

            return;
        }
        $notifikasi->aula($b);
        $this->formBaru = false;
        $this->dispatch('toast', teks: "Pemesanan {$b->booking_code} dicatat.");
    }

    private function selesai(string $pesan): void
    {
        $this->tutupAksi();
        $this->dispatch('toast', teks: $pesan);
    }

    private function terpilih(): HallBooking
    {
        abort_if($this->selectedId === null, 404);

        return $this->terlihat()->with(['hall', 'customer.institution', 'invoice', 'createdBy'])->findOrFail($this->selectedId);
    }

    public function render(): View
    {
        $lihatSaja = self::lihatSaja();
        $daftar = $this->terlihat()->with(['hall', 'customer.institution', 'invoice'])
            ->when($this->tab === 'menunggu', fn ($q) => $q->where('status', 'pending')->orderBy('start_at'))
            ->when($this->tab === 'terkonfirmasi', fn ($q) => $q->where('status', 'confirmed')->orderBy('start_at'))
            ->when($this->tab === 'riwayat', fn ($q) => $q->whereIn('status', ['completed', 'rejected', 'cancelled'])->orderByDesc('start_at')->limit(100))
            ->get();
        $b = $this->selectedId ? $this->terpilih() : null;

        return view('livewire.staf.aula.pemesanan-aula', [
            'daftar' => $daftar,
            'jumlah' => $this->terlihat()->whereIn('status', ['pending', 'confirmed'])->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'b' => $b,
            'lihatSaja' => $lihatSaja,
            'bolehKelola' => Gate::allows('manage-halls'),
            'bolehPutuskan' => Gate::allows('approve-halls'),
            'daftarInstansi' => $lihatSaja ? collect() : Institution::orderBy('name')->pluck('name', 'id'),
            'daftarAula' => Hall::where('is_active', true)->orderBy('id')->pluck('name', 'id'),
            'bentrok' => $b && $b->status === 'pending' ? app(AulaService::class)->bentrok($b->hall_id, $b->start_at, $b->end_at, $b->id) : null,
            // Nomor penyewa dan nota tidak dikirim ke halaman lihat saja
            'waUlang' => $b && ! $lihatSaja ? WhatsAppService::tautan($b->customer?->phone, app(NotifikasiLayanan::class)->teksAula($b)) : null,
            'urlNota' => ! $lihatSaja && $b?->invoice && $b->invoice->status !== 'cancelled' ? app(NotaPdf::class)->tautan($b->invoice) : null,
        ])->layout('components.layouts.staf', ['title' => 'Pemesanan Aula']);
    }
}
