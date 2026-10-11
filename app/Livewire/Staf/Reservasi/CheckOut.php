<?php

namespace App\Livewire\Staf\Reservasi;

use App\Livewire\Staf\Concerns\QrisMeja;
use App\Models\Reservation;
use App\Services\CheckOutService;
use App\Services\FolioService;
use App\Services\NotaPdf;
use App\Services\PembayaranMidtrans;
use App\Services\PembayaranService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Check-out untuk Front Office (Manager melihat): tamu yang terlambat, yang keluar hari ini, lalu yang masih menginap.
 * Panel rincian menampilkan folio, penghalang, pilihan kamar yang keluar, biaya terlambat atau keluar lebih awal,
 * dan pembayaran (manual atau QRIS Midtrans yang dipindai tamu di meja).
 */
class CheckOut extends Component
{
    use QrisMeja;

    #[Url(except: '')]
    public string $cari = '';

    #[Locked]
    public ?int $selectedId = null;

    /** id baris reservasi => ikut keluar */
    public array $kamar = [];

    /** dipakai | pesanan */
    public string $keluarAwal = 'dipakai';

    /** id baris reservasi => biaya terlambat dibebaskan */
    public array $bebas = [];

    /** id baris reservasi => alasan pembebasan */
    public array $alasan = [];

    public bool $ktpKembali = false;

    /* Pembayaran */
    public string $jumlah = '';

    public string $metode = 'cash';

    /** stay | service */
    public string $notaBayar = 'stay';

    public function mount(): void
    {
        Gate::authorize('view-reservations');
    }

    public function open(int $id): void
    {
        Gate::authorize('view-reservations');
        $r = Reservation::with('rooms')->where('status', 'checked_in')->findOrFail($id);
        $this->selectedId = $r->id;
        $this->kamar = $r->rooms->where('status', 'checked_in')->mapWithKeys(fn ($b) => [$b->id => true])->all();
        $this->reset('keluarAwal', 'bebas', 'alasan', 'ktpKembali', 'jumlah', 'metode', 'notaBayar', 'qris');
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->qris = null;
        $this->resetValidation();
    }

    public function bayar(PembayaranService $pembayaran, CheckOutService $checkOut): void
    {
        Gate::authorize('manage-reservations');
        $this->validate([
            'notaBayar' => ['required', Rule::in(['stay', 'service'])],
            'metode' => ['required', Rule::in(array_keys(PembayaranService::METODE))],
            'jumlah' => ['nullable', 'regex:/^[0-9.\s]+$/'],
        ], ['metode.*' => 'Pilih metode pembayaran.', 'jumlah.regex' => 'Isi angka, misalnya 350000.']);
        $r = $this->terpilih();
        $ringkas = $this->folio($r)['nota'][$this->notaBayar] ?? null;
        if (! $ringkas || $ringkas['termin'] !== 'immediate') {
            $this->addError('jumlah', 'Nota ini tidak dibayar di meja.');

            return;
        }
        $sisa = $ringkas['sisa'];
        $nota = DB::transaction(fn () => $checkOut->siapkanNota($r, $this->notaBayar, Auth::user()));
        $jumlah = $this->jumlah === '' ? $sisa : (float) preg_replace('/\D/', '', $this->jumlah);
        $pembayaran->catatManual($nota, $jumlah, $this->metode, 'settlement', Auth::user(), 'Pelunasan check-out '.$r->reservation_code);
        $this->reset('jumlah');
        $this->qris = null;
        $this->dispatch('toast', teks: 'Pembayaran '.FolioService::rp($jumlah).' dicatat.');
    }

    /** Kembalikan kelebihan bayar ke tamu (dicatat sebagai refund manual). */
    public function kembalikan(string $kunci, PembayaranService $pembayaran): void
    {
        Gate::authorize('manage-reservations');
        $this->validate(['metode' => ['required', Rule::in(array_keys(PembayaranService::METODE))]], ['metode.*' => 'Pilih metode pengembalian.']);
        $r = Reservation::findOrFail((int) $this->selectedId);
        $ringkas = $this->folio($r)['nota'][$kunci] ?? null;
        if (! $ringkas || ! $ringkas['model'] || $ringkas['lebih'] <= 0) {
            $this->addError('jumlah', 'Tidak ada kelebihan bayar.');

            return;
        }
        $pembayaran->kembalikanDiMeja($ringkas['model'], $this->metode, Auth::user(), 'Lebih bayar saat check-out '.$r->reservation_code);
        $this->dispatch('toast', teks: FolioService::rp($ringkas['lebih']).' dikembalikan ke tamu.');
        if ($r->status !== 'checked_in') {
            $this->close();
        }
    }

    /** Tampilkan QRIS Midtrans senilai sisa saat ini untuk dipindai tamu; status dipantau setiap 5 detik. */
    public function mulaiQris(CheckOutService $checkOut): void
    {
        Gate::authorize('manage-reservations');
        $r = $this->terpilih();
        $ringkas = $this->folio($r)['nota'][$this->notaBayar] ?? null;
        if (! $ringkas || $ringkas['termin'] !== 'immediate') {
            $this->addError('jumlah', 'Nota ini tidak dibayar di meja.');

            return;
        }
        $this->tampilkanQris(fn () => DB::transaction(fn () => $checkOut->siapkanNota($r, $this->notaBayar, Auth::user())), (int) round($ringkas['sisa']));
    }

    public function checkOut(CheckOutService $checkOut): void
    {
        Gate::authorize('manage-reservations');
        $r = $this->terpilih();
        $ids = array_keys(array_filter($this->kamar));
        $bebas = collect($this->bebas)->filter()->keys()->mapWithKeys(fn ($id) => [$id => trim((string) ($this->alasan[$id] ?? ''))])->all();
        foreach ($bebas as $id => $alasan) {
            if ($alasan === '') {
                $this->addError("alasan.{$id}", 'Isi alasan pembebasan.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $hasil = $checkOut->proses($r, $ids, ['keluar_awal' => $this->keluarAwal, 'bebas_terlambat' => $bebas, 'ktp_kembali' => $this->ktpKembali], Auth::user());
        $this->qris = null;
        if ($hasil['akhir'] && $hasil['lebih'] > 0) {
            // Panel tetap terbuka untuk mencatat pengembalian kelebihan bayar
            $this->dispatch('toast', teks: "Check-out {$r->reservation_code} selesai. Kembalikan ".FolioService::rp($hasil['lebih']).' ke tamu.', peringatan: true);

            return;
        }
        $this->dispatch('toast', teks: $hasil['akhir'] ? "Check-out {$r->reservation_code} selesai." : 'Check-out sebagian kamar berhasil.');
        $hasil['akhir'] ? $this->close() : $this->open($r->id);
    }

    private function terpilih(): Reservation
    {
        $r = $this->selectedId ? Reservation::with('rooms.room', 'institution', 'customer')->find($this->selectedId) : null;
        abort_if(! $r || $r->status !== 'checked_in', 404);

        return $r;
    }

    private function opsi(Reservation $r): array
    {
        $menginap = $r->rooms->where('status', 'checked_in')->pluck('id')->all();

        return [
            'kamar' => array_values(array_intersect($menginap, array_keys(array_filter($this->kamar)))),
            'keluar_awal' => $this->keluarAwal,
            'bebas_terlambat' => array_keys(array_filter($this->bebas)),
        ];
    }

    private function folio(Reservation $r): array
    {
        return app(FolioService::class)->rincian($r, null, $r->status === 'checked_in' ? $this->opsi($r) : []);
    }

    public function render(FolioService $folio): View
    {
        $kata = trim($this->cari);
        $hariIni = today();
        $daftar = Reservation::query()
            ->with(['customer', 'institution', 'rooms.room'])
            ->where('status', 'checked_in')
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('reservation_code', 'like', "%{$kata}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%"))
                ->orWhereHas('institution', fn ($i) => $i->where('name', 'like', "%{$kata}%"))
                ->orWhereHas('rooms', fn ($b) => $b->where('status', 'checked_in')->whereHas('room', fn ($k) => $k->where('room_number', $kata)))))
            ->get()
            ->map(function (Reservation $r) use ($folio, $hariIni) {
                $menginap = $r->rooms->where('status', 'checked_in');
                $f = $folio->rincian($r);

                return [
                    'r' => $r,
                    'kamar' => $menginap->map(fn ($b) => $b->room?->room_number)->filter()->join(', '),
                    'keluar' => $menginap->min(fn ($b) => $b->check_out_date->toDateString()),
                    'kelompok' => match (true) {
                        $menginap->contains(fn ($b) => now()->gt(FolioService::batasKeluar($b))) => 'terlambat',
                        $menginap->contains(fn ($b) => $b->check_out_date->lte($hariIni)) => 'hari-ini',
                        default => 'menginap',
                    },
                    'sisa' => $f['sisa'],
                    'piutang' => $f['piutang'],
                ];
            })
            ->sortBy(fn ($x) => [$x['keluar'], $x['r']->id])
            ->groupBy('kelompok');

        $r = $this->selectedId ? Reservation::with('rooms.room', 'rooms.roomType', 'institution', 'customer')->find($this->selectedId) : null;
        $f = $r ? $this->folio($r) : null;

        return view('livewire.staf.reservasi.check-out', [
            'kelompok' => collect(['terlambat' => 'Terlambat', 'hari-ini' => 'Keluar hari ini', 'menginap' => 'Masih menginap'])
                ->map(fn ($label, $k) => ['label' => $label, 'isi' => $daftar->get($k, collect())]),
            'jumlahTamu' => $daftar->flatten(1)->count(),
            'r' => $r,
            'f' => $f,
            'bolehKelola' => Gate::allows('manage-reservations'),
            'jamKeluar' => str_replace(':', '.', FolioService::jamKeluar()),
            'metodeBayar' => PembayaranService::METODE,
            'midtransSiap' => PembayaranMidtrans::siap(),
            'urlNota' => $r && $r->status === 'checked_out' && ($n = $folio->notaMenginap($r)) ? app(NotaPdf::class)->tautan($n) : null,
        ])->layout('components.layouts.staf', ['title' => 'Check-out']);
    }
}
