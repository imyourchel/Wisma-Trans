<?php

namespace App\Livewire\Tamu;

use App\Models\ReservationRoom;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\Layanan\LaundryService;
use App\Services\Notifikasi\NotifikasiLayanan;
use App\Services\Reservasi\KodeKamar;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Halaman /laundry/pesan: formulir pesan laundry kiloan (harga dan alur di /laundry, InfoLayananController).
 *  - Tamu menginap: kamar dipilih dari reservasi yang sedang check-in (akun pemilik, atau kode kamar seperti di Resto).
 *  - Tamu luar: nama dan nomor WhatsApp, cucian diantar ke resepsionis.
 */
class PesanLaundry extends Component
{
    /** menginap | luar */
    #[Url(except: 'menginap')]
    public string $jenis = 'menginap';

    /** Kode kamar (token akses reservasi), boleh huruf kecil dan dengan/tanpa tanda hubung */
    public string $kodeKamar = '';

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
        // Tautan ?t= langsung dicocokkan; tautan lama ?kode=&t= tetap diterima (bagian kode diabaikan).
        // Kode yang sudah cocok di Resto (session) langsung memunculkan kamar.
        if (request()->filled('t')) {
            $this->kodeKamar = (string) request('t');
            $this->cariKamar(app(KodeKamar::class));
        }
        $kamar = $this->kamarTersedia();
        if ($kamar->count() === 1) {
            $this->kamar = (string) $kamar->first()['id'];
        }
    }

    /** Cari kamar dengan kode kamar (aturan dan batas percobaan sama dengan Resto). */
    public function cariKamar(KodeKamar $kodeKamar): void
    {
        try {
            $kodeKamar->cocokkan($this->kodeKamar, (string) request()->ip());
        } catch (ValidationException $e) {
            $this->addError('kodeKamar', collect($e->errors())->flatten()->first());

            return;
        }
        $this->resetErrorBag('kodeKamar');
        $this->kodeKamar = '';
        $kamar = $this->kamarTersedia();
        $this->kamar = $kamar->count() === 1 ? (string) $kamar->first()['id'] : '';
    }

    /** Ganti kode kamar: lupakan hasil cocok di session; kamar milik akun yang masuk tetap tampil. */
    public function lepas(KodeKamar $kodeKamar): void
    {
        $kodeKamar->lupakan();
        $this->reset('kamar', 'kodeKamar');
    }

    private function kamarTersedia()
    {
        $kodeKamar = app(KodeKamar::class);

        return $kodeKamar->kamarUntuk(Auth::user(), $kodeKamar->reservasiSesi());
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
            'kamar.in' => 'Kamar tidak ditemukan. Cocokkan kode kamar dulu.',
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
            // Periksa ulang: reservasi masih check-in dan memang hasil cocok kode kamar atau milik akun ini
            if (! app(KodeKamar::class)->bolehMemakai(Auth::user(), $baris)) {
                $this->addError('kamar', KodeKamar::TIDAK_BOLEH);

                return null;
            }
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
            'dariKode' => app(KodeKamar::class)->reservasiSesi() !== null,
            'minKg' => LaundryService::MIN_KG,
        ])->layout('components.layouts.guest', ['title' => 'Pesan laundry']);
    }
}
