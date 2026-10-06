<?php

namespace App\Livewire\Staf\Reservasi;

use App\Models\RoomType;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\KetersediaanKamar;
use App\Services\ReservasiService;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
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

    public function mount(): void
    {
        Gate::authorize('manage-reservations');
        $this->masuk = today()->toDateString();
        $this->keluar = today()->addDay()->toDateString();
    }

    public function updated(string $nama): void
    {
        // Ganti tanggal: kamar yang sudah tidak tersedia dilepas dari pilihan
        if (in_array($nama, ['masuk', 'keluar'], true) && $this->tanggal()) {
            [$m, $k] = $this->tanggal();
            $bebas = app(KetersediaanKamar::class)->kamarBebas($m, $k)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $this->kamar = array_values(array_intersect($this->kamar, $bebas));
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

        return view('livewire.staf.reservasi.reservasi-langsung', [
            'tipe' => $tipe,
            'perTipe' => $bebas->groupBy('room_type_id'),
            'sisa' => $sisa,
            'malam' => $malam,
            'dipilih' => $dipilih,
            'total' => $dipilih->sum(fn ($k) => (float) $tipe[$k->room_type_id]->base_price * $malam),
            'hariIni' => $this->masuk === today()->toDateString(),
            'tanggalValid' => $tgl !== null,
        ])->layout('components.layouts.staf', ['title' => 'Reservasi Langsung']);
    }
}
