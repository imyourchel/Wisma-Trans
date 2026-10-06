<?php

namespace App\Livewire\Tamu;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\LaundryService;
use App\Services\NotifikasiLayanan;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use App\Support\TokenAkses;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Halaman /laundry: pesan laundry kiloan.
 *  - Tamu menginap: kamar dipilih dari reservasi yang sedang check-in (akun pemilik, atau kode reservasi + token).
 *  - Tamu luar: nama dan nomor WhatsApp, cucian diantar ke resepsionis.
 */
class PesanLaundry extends Component
{
    /** menginap | luar */
    #[Url(except: 'menginap')]
    public string $jenis = 'menginap';

    public string $kode = '';

    public string $token = '';

    /** Reservasi yang sudah dicocokkan lewat kode dan token (tamu tanpa akun). */
    #[Locked]
    public ?int $reservasiId = null;

    public string $kamar = '';

    /** kamar | sekarang */
    public string $tagih = 'kamar';

    public string $nama = '';

    public string $hp = '';

    public string $email = '';

    public string $perkiraan = '';

    public string $catatan = '';

    public function mount(): void
    {
        if ($user = Auth::user()) {
            $c = $user->customer;
            $this->nama = $c?->full_name ?? $user->name;
            $this->hp = $c?->phone ?? (string) $user->phone;
            $this->email = $c?->email ?? $user->email;
        }
        if (request()->filled('t') && request()->filled('kode')) {
            $this->kode = (string) request('kode');
            $this->token = (string) request('t');
            $this->cocokkan();
        }
        $kamar = $this->kamarTersedia();
        if ($kamar->count() === 1) {
            $this->kamar = (string) $kamar->first()['id'];
        }
    }

    /** Cocokkan kode reservasi dan token dari pesan konfirmasi. */
    public function cocokkan(): void
    {
        $kunci = 'laundry-token:'.request()->ip();
        if (RateLimiter::tooManyAttempts($kunci, 10)) {
            $this->addError('kode', 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.');

            return;
        }
        RateLimiter::hit($kunci, 300);

        $kode = preg_replace('/^RSV-?/', 'RSV-', Str::upper(trim($this->kode)));
        $token = TokenAkses::rapikan($this->token);
        $r = Reservation::where('reservation_code', $kode)->first();
        if (! $r || ! $r->access_token || $token === '' || ! hash_equals($r->access_token, $token)) {
            $this->addError('kode', 'Kode reservasi atau token tidak cocok.');

            return;
        }
        if ($r->status !== 'checked_in') {
            $this->addError('kode', 'Laundry kamar hanya untuk tamu yang sedang menginap. Reservasi ini: '.Str::lower($r->label()).'.');

            return;
        }
        $this->resetErrorBag('kode');
        $this->reservasiId = $r->id;
        $kamar = $this->kamarTersedia();
        $this->kamar = $kamar->count() === 1 ? (string) $kamar->first()['id'] : '';
    }

    public function lepas(): void
    {
        $this->reset('reservasiId', 'kamar', 'kode', 'token');
    }

    /** Kamar yang sedang ditempati: dari reservasi yang dicocokkan, atau semua reservasi aktif milik akun. */
    private function kamarTersedia()
    {
        $q = Reservation::with(['rooms' => fn ($q) => $q->where('status', 'checked_in')->with('room'), 'customer'])->where('status', 'checked_in');
        if ($this->reservasiId) {
            $q->whereKey($this->reservasiId);
        } elseif (Auth::check()) {
            $q->whereHas('customer', fn ($c) => $c->where('user_id', Auth::id()));
        } else {
            return collect();
        }

        return $q->get()->flatMap(fn (Reservation $r) => $r->rooms->map(fn ($b) => [
            'id' => $b->id, 'nomor' => $b->room?->room_number, 'kode' => $r->reservation_code, 'nama' => $r->customer?->full_name,
            'keluar' => $b->check_out_date->translatedFormat('j M'),
        ]))->values();
    }

    public function kirim(LaundryService $laundry, NotifikasiLayanan $notifikasi)
    {
        $menginap = $this->jenis === 'menginap';
        $this->perkiraan = str_replace(',', '.', trim($this->perkiraan));
        if (! $menginap) {
            $this->nama = PersonName::normalize($this->nama);
            $this->hp = Phone::normalizeInput($this->hp);
            $this->email = Str::lower(trim($this->email));
        }
        $kamar = $this->kamarTersedia();
        $this->validate([
            'kamar' => [$menginap ? 'required' : 'nullable', Rule::in($kamar->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'tagih' => [$menginap ? 'required' : 'nullable', Rule::in(['kamar', 'sekarang'])],
            'nama' => [$menginap ? 'nullable' : 'required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'hp' => [$menginap ? 'nullable' : 'required', $menginap ? 'string' : new NomorHp],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'perkiraan' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
            'catatan' => ['nullable', 'string', 'max:200'],
        ], [
            'kamar.required' => 'Pilih kamar Anda.',
            'kamar.in' => 'Kamar tidak ditemukan. Cocokkan kode reservasi dulu.',
            'nama.required' => 'Isi nama Anda.',
            'nama.min' => 'Minimal 3 huruf.',
            'nama.regex' => PersonName::MESSAGE,
            'hp.required' => 'Isi nomor WhatsApp untuk kabar cucian.',
            'perkiraan.numeric' => 'Isi angka, misalnya 3 atau 2,5.',
            'perkiraan.min' => 'Minimal 0,5 kg.',
            'perkiraan.max' => 'Untuk lebih dari 50 kg, hubungi resepsionis.',
            'catatan.max' => 'Maksimal 200 karakter.',
        ]);

        if ($menginap) {
            $baris = ReservationRoom::with('reservation.customer')->findOrFail((int) $this->kamar);
            $pemesan = $baris->reservation->customer;
        } else {
            $baris = null;
            $pemesan = Pelanggan::dariIsian(['name' => $this->nama, 'phone' => $this->hp, 'email' => $this->email ?: null], Auth::user(), 'hp');
        }

        $order = $laundry->terima($pemesan, $baris, $menginap && $this->tagih === 'kamar',
            $this->perkiraan !== '' ? (float) $this->perkiraan : null, trim($this->catatan) ?: null);
        $notifikasi->laundry($order);

        return $this->redirect($order->urlStatus());
    }

    public function render(): View
    {
        $layanan = LaundryService::layanan();

        return view('livewire.tamu.pesan-laundry', [
            'layanan' => $layanan,
            'kamarTersedia' => $this->kamarTersedia(),
            'minKg' => LaundryService::MIN_KG,
        ])->layout('components.layouts.guest', ['title' => 'Laundry']);
    }
}
