<?php

namespace App\Livewire\Staf\Reservasi;

use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\BuktiInstansi;
use App\Services\KetersediaanKamar;
use App\Services\NotifikasiReservasi;
use App\Services\ProfilPelanggan;
use App\Services\ReservasiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Check-in: reservasi terkonfirmasi yang tiba hari ini (dan yang terlambat datang tetapi belum lewat tanggal keluar).
 * FO menetapkan nomor kamar yang berstatus Siap dan mencatat KTP yang ditahan. Manager hanya melihat.
 * Reservasi instansi hanya bisa check-in setelah kode bukti (token akses reservasi) yang disebutkan tamu diverifikasi.
 */
class CheckIn extends Component
{
    #[Url(except: '')]
    public string $cari = '';

    #[Locked]
    public ?int $selectedId = null;

    /** id baris reservasi => id kamar */
    public array $penempatan = [];

    public bool $ktpDitahan = true;

    public string $namaKtp = '';

    /** Kode reservasi dari Daftar Reservasi (?kode=): isian kode bukti langsung untuk reservasi itu. */
    #[Url(except: '')]
    public string $kode = '';

    /** Isian "Kode bukti" (token akses reservasi, boleh dengan atau tanpa tanda hubung) */
    public string $kodeBukti = '';

    /** Reservasi instansi yang sedang diverifikasi dari baris daftar; kode yang diketik harus miliknya. */
    #[Locked]
    public ?int $verifikasiUntuk = null;

    #[Locked]
    public string $kodeUntuk = '';

    /** Hasil verifikasi terakhir: sah, bisaCheckIn, pesan, id reservasi */
    #[Locked]
    public ?array $hasil = null;

    /** Token reservasi instansi yang buktinya sudah diverifikasi, dipakai saat check-in. */
    #[Locked]
    public string $buktiSah = '';

    #[Locked]
    public ?int $buktiUntuk = null;

    public function mount(): void
    {
        Gate::authorize('view-reservations');

        // Dari Daftar Reservasi: ?kode=RSV-0012, tinggal mengetik kode bukti
        if ($this->kode !== '' && $r = Reservation::where('reservation_code', mb_substr($this->kode, 0, 20))->first()) {
            $this->tujuVerifikasi($r);
        }
        $this->kode = '';
    }

    public function verifikasi(BuktiInstansi $bukti): void
    {
        Gate::authorize('view-reservations');
        $this->resetValidation('kodeBukti');
        if (trim($this->kodeBukti) === '') {
            $this->addError('kodeBukti', 'Wajib diisi.');

            return;
        }
        // Batasi percobaan supaya kode bukti tidak bisa ditebak
        $kunci = 'verifikasi-bukti:'.Auth::id();
        if (RateLimiter::tooManyAttempts($kunci, 10)) {
            $this->addError('kodeBukti', 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.');

            return;
        }
        RateLimiter::hit($kunci, 60);

        $this->terapkan($bukti->verifikasi($this->kodeBukti, $this->verifikasiUntuk ? Reservation::find($this->verifikasiUntuk) : null));
    }

    /** Tombol "Verifikasi bukti" pada baris reservasi instansi: fokus ke isian kode bukti untuk reservasi itu. */
    public function mulaiVerifikasi(int $id): void
    {
        $this->tujuVerifikasi(Reservation::findOrFail($id));
    }

    /** Verifikasi tanpa reservasi tertentu (kode diketik langsung). */
    public function lepasVerifikasi(): void
    {
        $this->verifikasiUntuk = null;
        $this->kodeUntuk = '';
        $this->hasil = null;
        $this->resetValidation('kodeBukti');
    }

    private function tujuVerifikasi(Reservation $r): void
    {
        $this->verifikasiUntuk = $r->id;
        $this->kodeUntuk = $r->reservation_code;
        $this->kodeBukti = '';
        $this->hasil = null;
        $this->resetValidation();
        $this->dispatch('fokus-kode-bukti');
    }

    private function terapkan(array $h): void
    {
        $r = $h['reservasi'];
        $this->hasil = ['sah' => $h['sah'], 'bisaCheckIn' => $h['bisaCheckIn'], 'pesan' => $h['pesan'], 'id' => $r?->id];
        $this->buktiSah = $h['bisaCheckIn'] ? (string) $r->access_token : '';
        $this->buktiUntuk = $h['bisaCheckIn'] ? $r->id : null;
    }

    public function open(int $id): void
    {
        Gate::authorize('manage-reservations');
        $r = Reservation::with('rooms', 'customer')->where('status', 'confirmed')->findOrFail($id);
        if ($r->instansi() && $this->buktiUntuk !== $r->id) {
            $this->tujuVerifikasi($r);

            return;
        }
        $this->resetValidation();
        $this->selectedId = $r->id;
        $this->penempatan = $r->rooms->where('status', 'booked')->mapWithKeys(fn ($b) => [$b->id => (string) ($b->room_id ?? '')])->all();
        $this->ktpDitahan = true;
        $this->namaKtp = (string) $r->customer?->full_name;
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->resetValidation();
    }

    public function simpan(ReservasiService $layanan): void
    {
        Gate::authorize('manage-reservations');
        $r = Reservation::findOrFail($this->selectedId);
        $layanan->checkIn($r, array_map('intval', array_filter($this->penempatan)), $this->ktpDitahan, $this->namaKtp, Auth::user(),
            $this->buktiUntuk === $r->id ? $this->buktiSah : null);
        $this->close();
        if ($this->buktiUntuk === $r->id) {
            $this->hasil = null;
            $this->buktiSah = '';
            $this->buktiUntuk = $this->verifikasiUntuk = null;
            $this->kodeBukti = $this->kodeUntuk = '';
        }
        // Kode kamar dan tautan Resto untuk tamu; gagal kirim tidak membatalkan check-in
        try {
            app(NotifikasiReservasi::class)->selamatDatang($r->fresh());
        } catch (Throwable $e) {
            Log::warning('Pesan selamat datang gagal dikirim', ['reservasi' => $r->reservation_code, 'pesan' => $e->getMessage()]);
        }
        $this->dispatch('toast', teks: "Check-in {$r->reservation_code} berhasil.");
    }

    public function render(KetersediaanKamar $ketersediaan): View
    {
        $kata = trim($this->cari);
        $daftar = Reservation::query()
            ->with(['customer', 'institution', 'request', 'rooms.roomType', 'rooms.room', 'stayInvoice'])
            ->menungguCheckIn()
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('reservation_code', 'like', "%{$kata}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%"))))
            ->orderBy('id')
            ->get();

        $r = $this->selectedId ? Reservation::with('rooms.roomType', 'rooms.room', 'customer')->find($this->selectedId) : null;
        $pilihanKamar = [];
        $usulan = [];
        if ($r) {
            // Kamar favorit pelanggan (CRM) hanya diusulkan, FO tetap memilih sendiri
            $favorit = $r->customer ? app(ProfilPelanggan::class)->untuk($r->customer)['kamar']['favorit'] : null;
            // Kamar siap yang tidak dipakai reservasi lain, per tipe; kamar yang sudah ditetapkan tetap ditawarkan
            foreach ($r->rooms->where('status', 'booked') as $b) {
                $bebas = $ketersediaan->kamarBebas($b->check_in_date, $b->check_out_date, [$b->room_type_id], $r->id);
                $pilihanKamar[$b->id] = $bebas
                    ->mapWithKeys(fn ($k) => [$k->id => ['label' => 'Kamar '.$k->room_number.($k->status === 'available' ? '' : ' (belum siap)'),
                        'warna' => $k->status === 'available' ? '#5C9E67' : '#D4514A']])
                    ->all();
                // Diusulkan bila bebas, berstatus Siap, dan tipenya sama dengan yang dipesan; cukup di satu baris
                $siap = $favorit && (int) $b->room_type_id === $favorit['tipe'] ? $bebas->firstWhere('id', $favorit['id']) : null;
                if ($siap && $siap->status === 'available' && $usulan === []) {
                    $usulan[$b->id] = $favorit;
                }
            }
        }

        return view('livewire.staf.reservasi.check-in', [
            'daftar' => $daftar,
            'r' => $r,
            'pilihanKamar' => $pilihanKamar,
            'usulan' => $usulan,
            'bolehKelola' => Gate::allows('manage-reservations'),
            'belumWaktunya' => now()->format('H:i') < substr((string) Setting::get('check_in_time', '14:00'), 0, 5),
            'jamCheckIn' => ReservasiService::jamCheckIn(),
            'namaTipe' => RoomType::pluck('name', 'id'),
        ])->layout('components.layouts.staf', ['title' => 'Check-in']);
    }
}
