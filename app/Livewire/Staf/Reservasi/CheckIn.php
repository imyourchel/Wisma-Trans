<?php

namespace App\Livewire\Staf\Reservasi;

use App\Enums\RoomStatus;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Pelanggan\ProfilPelanggan;
use App\Services\Reservasi\BuktiInstansi;
use App\Services\Reservasi\ReservasiService;
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
 * Sebelum jam check-in (mulai early_check_in_from) tamu yang tiba hari ini bisa early check-in: hanya kamar Siap yang ditawarkan.
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
        $r = Reservation::with('rooms.room', 'customer')->where('status', 'confirmed')->findOrFail($id);
        if ($r->instansi() && $this->buktiUntuk !== $r->id) {
            $this->tujuVerifikasi($r);

            return;
        }
        $this->resetValidation();
        $this->selectedId = $r->id;
        // Early check-in: kamar yang ditetapkan tetapi belum Siap dikosongkan supaya FO memilih kamar yang Siap
        $early = self::early($r);
        $this->penempatan = $r->rooms->where('status', 'booked')
            ->mapWithKeys(fn ($b) => [$b->id => $early && $b->room?->status !== RoomStatus::Available ? '' : (string) ($b->room_id ?? '')])->all();
        $this->ktpDitahan = true;
        $this->namaKtp = (string) $r->customer?->full_name;
    }

    /** Tamu tiba hari ini sebelum jam check-in normal, dalam masa early check-in. */
    private static function early(Reservation $r): bool
    {
        return ReservasiService::masaCheckIn() === 'early' && (bool) $r->masuk()?->isSameDay(today());
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
            ->with(['customer', 'institution', 'request', 'rooms.roomType', 'rooms.room', 'stayInvoice', 'logs'])
            ->menungguCheckIn()
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('reservation_code', 'like', "%{$kata}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%"))))
            ->orderBy('id')
            ->get();

        $r = $this->selectedId ? Reservation::with('rooms.roomType', 'rooms.room', 'customer')->find($this->selectedId) : null;
        $pilihanKamar = [];
        $usulan = [];
        $kasurKamar = [];
        $mauKasur = [];
        $kurangMuat = [];
        $early = $r && self::early($r);
        if ($r) {
            // Kamar favorit pelanggan (CRM) hanya diusulkan, FO tetap memilih sendiri
            $favorit = $r->customer ? app(ProfilPelanggan::class)->untuk($r->customer)['kamar']['favorit'] : null;
            // Kamar siap yang tidak dipakai reservasi lain, per tipe; kamar yang sudah ditetapkan tetap ditawarkan
            foreach ($r->rooms->where('status', 'booked') as $b) {
                // Jenis kasur pilihan tamu = jenis kasur kamar yang ditetapkan saat memesan; kamar berjenis sama didahulukan
                $mau = $b->room?->bed_type;
                $mauKasur[$b->id] = $mau;
                $bebas = $ketersediaan->kamarBebas($b->check_in_date, $b->check_out_date, [$b->room_type_id], $r->id)
                    // Early check-in hanya untuk kamar berstatus Siap; yang belum siap tidak ditawarkan
                    ->when($early, fn ($k) => $k->where('status', 'available'))
                    ->sortBy(fn ($k) => [$mau && $k->bed_type !== $mau ? 1 : 0, $k->status === 'available' ? 0 : 1, strlen($k->room_number), $k->room_number]);
                $pilihanKamar[$b->id] = $bebas
                    ->mapWithKeys(fn ($k) => [$k->id => ['label' => 'Kamar '.$k->room_number.' · '.Room::labelKasur($k->bed_type).($k->status === 'available' ? '' : ' (belum siap)'),
                        'warna' => $k->status === 'available' ? '#5C9E67' : '#D4514A']])
                    ->all();
                $kasurKamar[$b->id] = $bebas->mapWithKeys(fn ($k) => [$k->id => $k->bed_type])->all();
                // Kamar yang menampung lebih sedikit tamu daripada kasur pesanan (RoomType::kapasitasKasur)
                $kurangMuat[$b->id] = $mau ? $bebas->mapWithKeys(fn ($k) => [$k->id => $b->roomType->kurangDari($k->bed_type, $b->roomType->kapasitasKasur($mau))])->all() : [];
                // Kamar yang ditetapkan belum siap: usulkan kamar siap berjenis kasur sama
                if ($mau && $b->room->status !== RoomStatus::Available) {
                    $sama = $bebas->first(fn ($k) => $k->status === 'available' && $k->bed_type === $mau);
                    if ($sama) {
                        $usulan[$b->id] = ['id' => $sama->id, 'nomor' => $sama->room_number, 'alasan' => 'siap, jenis kasur sama dengan pesanan'];
                    }
                }
                // Diusulkan bila bebas, berstatus Siap, dan tipenya sama dengan yang dipesan; cukup di satu baris
                $siap = $favorit && (int) $b->room_type_id === $favorit['tipe'] ? $bebas->firstWhere('id', $favorit['id']) : null;
                if ($siap && $siap->status === 'available' && $usulan === []) {
                    $usulan[$b->id] = $favorit + ['alasan' => 'kamar favorit tamu'];
                }
            }
        }

        return view('livewire.staf.reservasi.check-in', [
            'daftar' => $daftar,
            'r' => $r,
            'pilihanKamar' => $pilihanKamar,
            'usulan' => $usulan,
            'kasurKamar' => $kasurKamar,
            'mauKasur' => $mauKasur,
            'kurangMuat' => $kurangMuat,
            'bolehKelola' => Gate::allows('manage-reservations'),
            // Banner hanya antara early_check_in_from dan check_in_time
            'masaEarly' => ReservasiService::masaCheckIn() === 'early',
            'early' => $early,
            'jamCheckIn' => ReservasiService::jamCheckIn(),
            'namaTipe' => RoomType::pluck('name', 'id'),
        ])->layout('components.layouts.staf', ['title' => 'Check-in']);
    }
}
