<?php

namespace App\Livewire\Staf\Reservasi;

use App\Models\Customer;
use App\Models\Room;
use App\Models\RoomType;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Pelanggan\ProfilPelanggan;
use App\Services\Reservasi\ReservasiService;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Reservasi langsung (walk-in) oleh Front Office: cek ketersediaan, tamu memilih nomor kamar,
 * data tamu dicatat, lalu bisa langsung check-in. Pembayaran dicatat di menu Pembayaran.
 */
class ReservasiLangsung extends Component
{
    public string $masuk = '';

    public string $keluar = '';

    /** @var list<string> id kamar terpilih */
    public array $kamar = [];

    public string $nama = '';

    public string $hp = '';

    public string $email = '';

    public string $permintaan = '';

    public bool $langsungCheckIn = true;

    public bool $ktpDitahan = true;

    public string $namaKtp = '';

    /** Pesan bila kamar dari kalender (?kamar=) tidak tersedia pada tanggal itu. */
    public string $peringatanKamar = '';

    /** Pelanggan lama yang cocok dengan nomor HP (dicari saat isian HP ditinggalkan), untuk kartu rekomendasi CRM. */
    #[Locked]
    public ?int $pelangganLama = null;

    /** Dari klik sel kosong di Kalender Ketersediaan: ?kamar={id}&masuk={Y-m-d} mengisi awal formulir. */
    public function mount(): void
    {
        Gate::authorize('manage-reservations');
        $this->masuk = today()->toDateString();
        $masuk = (string) request()->query('masuk', '');
        // Tanggal yang tidak valid atau sudah lewat diabaikan
        $m = $masuk !== '' ? rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $masuk), null, false) : null;
        if ($m && $m->format('Y-m-d') === $masuk && ! $m->lt(today())) {
            $this->masuk = $masuk;
        }
        $this->keluar = CarbonImmutable::parse($this->masuk)->addDay()->toDateString();

        if ($id = request()->integer('kamar')) {
            $room = Room::find($id);
            [$m, $k] = $this->tanggal();
            $bebas = $room && app(KetersediaanKamar::class)->kamarBebas($m, $k)->contains('id', $room->id);
            if ($bebas) {
                $this->kamar = [(string) $room->id];
            } elseif ($room) {
                $this->peringatanKamar = "Kamar {$room->room_number} tidak tersedia pada ".$m->translatedFormat('j M').', pilih kamar lain.';
            }
        }
    }

    public function updated(string $nama): void
    {
        if ($nama === 'hp') {
            $hp = Phone::normalizeInput($this->hp);
            $this->pelangganLama = Phone::isValidMobile($hp) ? Customer::where('phone', $hp)->value('id') : null;

            return;
        }
        // Ganti tanggal: kamar yang sudah tidak tersedia dilepas dari pilihan
        if (in_array($nama, ['masuk', 'keluar'], true) && $this->tanggal()) {
            [$m, $k] = $this->tanggal();
            $bebas = app(KetersediaanKamar::class)->kamarBebas($m, $k)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $this->kamar = array_values(array_intersect($this->kamar, $bebas));
        }
    }

    /** Kamar favorit dari kartu rekomendasi: hanya ditambahkan bila FO menekan tombolnya dan kamarnya bebas. */
    public function pilihKamar(int $id): void
    {
        Gate::authorize('manage-reservations');
        $tgl = $this->tanggal();
        if ($tgl && app(KetersediaanKamar::class)->kamarBebas($tgl[0], $tgl[1])->contains('id', $id) && ! in_array((string) $id, $this->kamar, true)) {
            $this->kamar[] = (string) $id;
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function tanggal(): ?array
    {
        try {
            $m = CarbonImmutable::createFromFormat('!Y-m-d', $this->masuk);
            $k = CarbonImmutable::createFromFormat('!Y-m-d', $this->keluar);
        } catch (Throwable) {
            return null;
        }

        return $m && $k && $k->gt($m) && ! $m->lt(today()) ? [$m, $k] : null;
    }

    public function simpan(ReservasiService $layanan)
    {
        Gate::authorize('manage-reservations');
        $this->nama = PersonName::normalize($this->nama);
        $this->hp = Phone::normalizeInput($this->hp);
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'masuk' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'keluar' => ['required', 'date_format:Y-m-d', 'after:masuk'],
            'kamar' => ['required', 'array', 'min:1'],
            'nama' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'hp' => ['required', new NomorHp],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'permintaan' => ['nullable', 'string', 'max:500'],
        ], [
            'masuk.after_or_equal' => 'Tanggal check-in tidak boleh sebelum hari ini.',
            'keluar.after' => 'Check-out harus setelah check-in.',
            'kamar.required' => 'Pilih minimal satu kamar.',
            'nama.required' => 'Isi nama tamu.',
            'nama.min' => 'Minimal 3 huruf.',
            'nama.regex' => PersonName::MESSAGE,
            'hp.required' => 'Isi nomor HP tamu.',
        ]);

        $tamu = Pelanggan::dariIsian(['name' => $this->nama, 'phone' => $this->hp, 'email' => $this->email ?: null], null, 'hp');
        $fo = Auth::user();
        $r = $layanan->buatLangsung($tamu, CarbonImmutable::parse($this->masuk), CarbonImmutable::parse($this->keluar), array_map('intval', $this->kamar), $fo, trim($this->permintaan) ?: null);

        $pesan = "Reservasi {$r->reservation_code} dibuat.";
        if ($this->langsungCheckIn && $this->masuk === today()->toDateString()) {
            try {
                $layanan->checkIn($r, [], $this->ktpDitahan, $this->namaKtp ?: $this->nama, $fo);
                $pesan = "Reservasi {$r->reservation_code} dibuat dan tamu sudah check-in.";
            } catch (ValidationException $e) {
                $pesan .= ' Check-in belum bisa: '.collect($e->errors())->flatten()->first();
            }
        }
        session()->flash('status', $pesan);

        return $this->redirect(route('staf.reservasi', ['cari' => $r->reservation_code, 'status' => '']));
    }

    public function render(KetersediaanKamar $ketersediaan): View
    {
        $tgl = $this->tanggal();
        $tipe = RoomType::orderBy('base_price')->get()->keyBy('id');
        $bebas = $tgl ? $ketersediaan->kamarBebas($tgl[0], $tgl[1]) : collect();
        $sisa = $tgl ? $ketersediaan->sisa($tgl[0], $tgl[1]) : [];
        $malam = $tgl ? (int) $tgl[0]->diffInDays($tgl[1]) : 0;
        $dipilih = $bebas->whereIn('id', array_map('intval', $this->kamar));
        $lama = $this->pelangganLama ? Customer::find($this->pelangganLama) : null;
        $profil = $lama ? app(ProfilPelanggan::class)->untuk($lama) : null;
        $favorit = $profil['kamar']['favorit'] ?? null;

        return view('livewire.staf.reservasi.reservasi-langsung', [
            'tipe' => $tipe,
            'perTipe' => $bebas->groupBy('room_type_id'),
            'sisa' => $sisa,
            'malam' => $malam,
            'dipilih' => $dipilih,
            'total' => $dipilih->sum(fn ($k) => (float) $tipe[$k->room_type_id]->base_price * $malam),
            'hariIni' => $this->masuk === today()->toDateString(),
            'tanggalValid' => $tgl !== null,
            'pelanggan' => $lama,
            'profil' => $profil,
            // Diusulkan saja (tidak dicentang otomatis), dan hanya bila bebas pada tanggal yang diisi
            'favoritBebas' => $favorit && $bebas->contains('id', $favorit['id']) && ! in_array((string) $favorit['id'], $this->kamar, true) ? $favorit : null,
        ])->layout('components.layouts.staf', ['title' => 'Reservasi Langsung']);
    }
}
