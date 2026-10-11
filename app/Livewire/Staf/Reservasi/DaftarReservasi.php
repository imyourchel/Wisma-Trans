<?php

namespace App\Livewire\Staf\Reservasi;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Keuangan\NotaPdf;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Notifikasi\WhatsAppService;
use App\Services\Pelanggan\ProfilPelanggan;
use App\Services\Reservasi\BuktiInstansi;
use App\Services\Reservasi\FolioService;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar Reservasi untuk Manager dan Front Office. Detail reservasi tampil di jendela;
 * FO dapat mengubah tanggal, membatalkan, memperpanjang, dan memindahkan kamar (Manager hanya melihat).
 */
class DaftarReservasi extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $cari = '';

    /** aktif = belum selesai (menunggu, terkonfirmasi, menginap); atau satu status tertentu */
    #[Url(except: 'aktif')]
    public string $status = 'aktif';

    /** '' semua, perorangan, instansi, langsung */
    #[Url(except: '')]
    public string $jenis = '';

    #[Locked]
    public ?int $selectedId = null;

    /** Formulir di jendela detail: ubah | batal | perpanjang | pindah */
    public string $aksi = '';

    #[Locked]
    public ?int $barisId = null;

    public string $masuk = '';

    public string $keluar = '';

    public string $alasan = '';

    public string $kamarBaru = '';

    public const STATUS_AKTIF = ['pending_payment', 'waiting_approval', 'confirmed', 'checked_in'];

    /** Dari Kalender Ketersediaan: ?buka={id reservasi} langsung membuka detailnya; dihapus dari URL saat detail ditutup. */
    #[Url(except: '')]
    public string $buka = '';

    public function mount(): void
    {
        Gate::authorize('view-reservations');
        $id = ctype_digit($this->buka) ? (int) $this->buka : 0;
        if ($id && Reservation::whereKey($id)->exists()) {
            $this->open($id);
        } else {
            $this->buka = '';
        }
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['cari', 'status', 'jenis'], true)) {
            $this->resetPage();
        }
    }

    public function open(int $id): void
    {
        Gate::authorize('view-reservations');
        $this->selectedId = Reservation::findOrFail($id)->id;
        $this->tutupAksi();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->buka = '';
        $this->tutupAksi();
    }

    public function tutupAksi(): void
    {
        $this->reset('aksi', 'barisId', 'masuk', 'keluar', 'alasan', 'kamarBaru');
        $this->resetValidation();
    }

    /** Buka formulir aksi; $baris untuk aksi per kamar (perpanjang, pindah). */
    public function mulai(string $aksi, ?int $baris = null): void
    {
        Gate::authorize('manage-reservations');
        $r = $this->terpilih();
        $this->tutupAksi();
        $this->aksi = in_array($aksi, ['ubah', 'batal', 'perpanjang', 'pindah'], true) ? $aksi : '';
        if ($baris !== null) {
            $this->barisId = $r->rooms->firstWhere('id', $baris)?->id;
        }
        $this->masuk = $r->masuk()?->toDateString() ?? '';
        $this->keluar = $this->barisId ? $r->rooms->firstWhere('id', $this->barisId)->check_out_date->addDay()->toDateString() : ($r->keluar()?->toDateString() ?? '');
    }

    /** Bukti pemesanan instansi dikirim ulang ke PIC (bukti hilang atau data diubah); PDF dibuat dari data terbaru. */
    public function kirimUlangBukti(NotifikasiReservasi $notifikasi): void
    {
        Gate::authorize('manage-reservations');
        $r = $this->terpilih();
        if (! BuktiInstansi::berlaku($r)) {
            $this->dispatch('toast', teks: 'Bukti hanya untuk reservasi instansi yang sudah disetujui.', galat: true);

            return;
        }
        $notifikasi->kirimBukti($r);
        $this->dispatch('toast', teks: "Bukti {$r->reservation_code} dikirim ulang ke {$r->customer?->full_name}.");
    }

    public function simpanUbah(ReservasiService $layanan): void
    {
        Gate::authorize('manage-reservations');
        $this->validate(['masuk' => 'required|date_format:Y-m-d', 'keluar' => 'required|date_format:Y-m-d'], ['*.required' => 'Pilih tanggal.', '*.date_format' => 'Tanggal tidak valid.']);
        $layanan->ubahTanggal($this->terpilih(), CarbonImmutable::parse($this->masuk), CarbonImmutable::parse($this->keluar), Auth::user());
        $this->selesai('Tanggal reservasi diubah.');
    }

    public function simpanBatal(ReservasiService $layanan, PembayaranMidtrans $midtrans): void
    {
        Gate::authorize('manage-reservations');
        $this->validate(['alasan' => 'required|string|max:200'], ['alasan.required' => 'Isi alasan pembatalan.', 'alasan.max' => 'Maksimal 200 karakter.']);
        $r = $this->terpilih();
        $kembali = $layanan->batalkan($r, $this->alasan, Auth::user());
        $midtrans->akhiriTagihan($r);
        $this->selesai($kembali > 0
            ? 'Reservasi dibatalkan. Kembalikan uang muka Rp'.number_format($kembali, 0, ',', '.').' kepada tamu.'
            : 'Reservasi dibatalkan.');
    }

    public function simpanPerpanjang(ReservasiService $layanan): void
    {
        Gate::authorize('manage-reservations');
        $this->validate(['keluar' => 'required|date_format:Y-m-d'], ['keluar.*' => 'Pilih tanggal check-out baru.']);
        $layanan->perpanjang($this->baris(), CarbonImmutable::parse($this->keluar), Auth::user());
        $this->selesai('Masa menginap diperpanjang.');
    }

    public function simpanPindah(ReservasiService $layanan): void
    {
        Gate::authorize('manage-reservations');
        $this->validate(['kamarBaru' => 'required|integer', 'alasan' => 'nullable|string|max:150'], ['kamarBaru.*' => 'Pilih kamar tujuan.']);
        $baru = $layanan->pindahKamar($this->baris(), Room::findOrFail((int) $this->kamarBaru), $this->alasan ?: null, Auth::user());
        $this->selesai('Tamu dipindah ke kamar '.$baru->room->room_number.'.');
    }

    private function selesai(string $pesan): void
    {
        $this->tutupAksi();
        $this->dispatch('toast', teks: $pesan);
    }

    private function terpilih(): Reservation
    {
        abort_if($this->selectedId === null, 404);

        return Reservation::with(['rooms.room', 'rooms.roomType', 'customer', 'institution', 'request', 'stayInvoice.payments', 'logs.changedBy'])
            ->findOrFail($this->selectedId);
    }

    private function baris(): ReservationRoom
    {
        return ReservationRoom::where('reservation_id', $this->selectedId)->findOrFail($this->barisId);
    }

    public function render(ReservasiService $layanan): View
    {
        $kata = trim($this->cari);
        $angka = preg_replace('/\D/', '', $kata);
        $daftar = Reservation::query()
            ->with(['customer', 'institution', 'request', 'rooms.roomType', 'rooms.room', 'stayInvoice'])
            ->when($this->status === 'aktif', fn ($q) => $q->whereIn('status', self::STATUS_AKTIF))
            ->when(isset(Reservation::STATUS[$this->status]), fn ($q) => $q->where('status', $this->status))
            ->when($this->jenis === 'instansi', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('institution_id')->orWhereHas('request')))
            ->when($this->jenis === 'perorangan', fn ($q) => $q->whereNull('institution_id')->whereDoesntHave('request')->where('booking_channel', 'online'))
            ->when($this->jenis === 'langsung', fn ($q) => $q->where('booking_channel', 'walk_in'))
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('reservation_code', 'like', "%{$kata}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%")
                    ->when(strlen($angka) >= 4, fn ($c) => $c->orWhere('phone', 'like', "%{$angka}%")))
                ->orWhereHas('institution', fn ($i) => $i->where('name', 'like', "%{$kata}%"))))
            ->orderByDesc('id')
            ->paginate(15);

        $r = $this->selectedId ? $this->terpilih() : null;
        $bolehKelola = Gate::allows('manage-reservations');
        $kamarPindah = collect();
        if ($r && $this->aksi === 'pindah' && $this->barisId) {
            $b = $r->rooms->firstWhere('id', $this->barisId);
            $kamarPindah = app(KetersediaanKamar::class)->kamarBebas(today(), CarbonImmutable::parse($b->check_out_date), null, $r->id)
                ->where('status', 'available');
        }

        return view('livewire.staf.reservasi.daftar-reservasi', [
            'daftar' => $daftar,
            'r' => $r,
            'bolehKelola' => $bolehKelola,
            'bolehUbah' => $r && $r->status === 'confirmed' && $r->kamarBerlaku()->every(fn ($k) => $k->status === 'booked') && $layanan->masihBolehDiubah($r),
            'bolehBatal' => $r && in_array($r->status, ['pending_payment', 'waiting_approval', 'confirmed'], true) && ! $r->rooms->contains('status', 'checked_in'),
            'pengembalian' => $r ? $layanan->pengembalian($r) : 0,
            'kamarPindah' => $kamarPindah,
            // Kartu profil CRM: segmen, kunjungan ke-berapa, dan rekomendasi (bila minimal 2 kunjungan)
            'profilPemesan' => $r && $r->customer && ProfilPelanggan::pribadi($r) ? [
                'profil' => $p = app(ProfilPelanggan::class)->untuk($r->customer),
                'label' => ProfilPelanggan::labelKunjungan(app(ProfilPelanggan::class)->kunjunganKe($r), $p['kunjungan']['rombongan'] > 0),
                'tautan' => Gate::allows('view-customers') ? route('staf.pelanggan', ['lihat' => $r->customer_id]) : null,
            ] : null,
            'polaRombongan' => $r && $r->institution && ! ProfilPelanggan::pribadi($r)
                ? ['pola' => app(ProfilPelanggan::class)->untukInstansi($r->institution), 'nama' => $r->institution->name] : null,
            'namaTipe' => RoomType::pluck('name', 'id'),
            // Kirim ulang manual: setelah uang muka diterima, pesannya berisi tautan nota
            'waUlang' => $r ? WhatsAppService::tautan($r->customer?->phone, $r->dpDibayar() > 0
                ? app(NotifikasiReservasi::class)->teksUangMukaDiterima($r)
                : app(NotifikasiReservasi::class)->teksDibuat($r)) : null,
            'buktiBerlaku' => $r && BuktiInstansi::berlaku($r),
            // Biaya tambahan sudah terlihat selama menginap, tidak baru diketahui saat check-out
            'folio' => $r && $r->status === 'checked_in' ? app(FolioService::class)->rincian($r) : null,
            'urlNota' => $r?->stayInvoice && $r->stayInvoice->status !== 'cancelled' ? app(NotaPdf::class)->tautan($r->stayInvoice) : null,
        ])->layout('components.layouts.staf', ['title' => 'Daftar Reservasi']);
    }
}
