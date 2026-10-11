<?php

namespace App\Livewire\Tamu;

use App\Models\FacilityUnit;
use App\Models\Hall;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\AulaService;
use App\Services\NotifikasiLayanan;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Halaman /aula: informasi aula dan pengajuan sewa. Jadwal pemesanan lain tidak ditampilkan; bentrok tetap dicek. Tamu tidak mengisi harga;
 * harga sewa dirundingkan staf lalu ditetapkan Manager saat menyetujui.
 */
class SewaAula extends Component
{
    public string $aula = '';

    public string $tanggal = '';

    public string $jamMulai = '08:00';

    public string $tanggalSelesai = '';

    public string $jamSelesai = '12:00';

    public string $peserta = '';

    public string $keperluan = '';

    public string $instansi = '';

    public string $nama = '';

    public string $hp = '';

    public string $email = '';

    public string $catatan = '';

    /** Kode pemesanan setelah pengajuan terkirim. */
    #[Locked]
    public ?string $terkirim = null;

    public function mount(): void
    {
        $this->aula = (string) Hall::where('is_active', true)->orderBy('id')->value('id');
        // Penanggung jawab acara belum tentu pemilik akun: data kontaknya diisi sendiri, tidak diambil dari akun
    }

    /** Waktu mulai dan selesai dari isian; null bila belum lengkap atau tidak valid. */
    private function waktu(): ?array
    {
        try {
            $mulai = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->tanggal.' '.$this->jamMulai);
            $selesai = CarbonImmutable::createFromFormat('Y-m-d H:i', ($this->tanggalSelesai ?: $this->tanggal).' '.$this->jamSelesai);
        } catch (Throwable) {
            return null;
        }

        return $mulai && $selesai ? [$mulai, $selesai] : null;
    }

    public function kirim(AulaService $layanan, NotifikasiLayanan $notifikasi): void
    {
        $kunci = 'ajukan-aula:'.request()->ip();
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $this->addError('nama', 'Terlalu banyak pengajuan. Coba lagi beberapa menit lagi.');

            return;
        }

        $this->nama = PersonName::normalize($this->nama);
        $this->hp = Phone::normalizeInput($this->hp);
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'aula' => ['required', Rule::exists('halls', 'id')->where('is_active', true)],
            'tanggal' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'jamMulai' => ['required', 'date_format:H:i'],
            'tanggalSelesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal'],
            'jamSelesai' => ['required', 'date_format:H:i'],
            'peserta' => ['required', 'integer', 'min:1', 'max:2000'],
            'keperluan' => ['required', 'string', 'min:3', 'max:150'],
            'instansi' => ['nullable', 'string', 'max:150'],
            'nama' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'hp' => ['required', new NomorHp],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'catatan' => ['nullable', 'string', 'max:200'],
        ], [
            'tanggal.required' => 'Pilih tanggal acara.',
            'tanggal.after_or_equal' => 'Tanggal acara tidak boleh sebelum hari ini.',
            'tanggalSelesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'jamMulai.*' => 'Pilih jam mulai.',
            'jamSelesai.*' => 'Pilih jam selesai.',
            'peserta.required' => 'Isi perkiraan jumlah peserta.',
            'peserta.*' => 'Isi angka jumlah peserta.',
            'keperluan.required' => 'Isi jenis acara.',
            'keperluan.min' => 'Minimal 3 huruf.',
            'nama.required' => 'Isi nama penanggung jawab.',
            'nama.min' => 'Minimal 3 huruf.',
            'nama.regex' => PersonName::MESSAGE,
            'hp.required' => 'Isi nomor WhatsApp.',
            'catatan.max' => 'Maksimal 200 karakter.',
        ]);
        [$mulai, $selesai] = $this->waktu() ?? [null, null];
        if (! $mulai) {
            $this->addError('jamMulai', 'Waktu acara tidak valid.');

            return;
        }
        RateLimiter::hit($kunci, 600);

        $penyewa = Pelanggan::dariIsian(['name' => $this->nama, 'phone' => $this->hp, 'email' => $this->email ?: null], Auth::user(), 'hp');
        $catatan = collect([$this->instansi !== '' ? 'Instansi: '.trim($this->instansi) : null, trim($this->catatan) ?: null])->filter()->join("\n");
        $b = $layanan->ajukan($penyewa, Hall::findOrFail((int) $this->aula), $mulai, $selesai, (int) $this->peserta, trim($this->keperluan), $catatan ?: null);
        $notifikasi->aula($b);

        $this->terkirim = $b->booking_code;
        $this->reset('tanggal', 'tanggalSelesai', 'peserta', 'keperluan', 'catatan');
    }

    public function render(): View
    {
        $aula = Hall::where('is_active', true)->orderBy('id')->get();
        $dipilih = $aula->firstWhere('id', (int) $this->aula) ?? $aula->first();
        $waktu = $this->waktu();
        $bentrok = $dipilih && $waktu && $waktu[1]->gt($waktu[0]) ? app(AulaService::class)->bentrok($dipilih->id, $waktu[0], $waktu[1]) : null;

        return view('livewire.tamu.sewa-aula', [
            'daftarAula' => $aula,
            'dipilih' => $dipilih,
            'bentrok' => $bentrok,
            // Fasilitas dari unit fasilitas yang terpasang di area Aula, misalnya "AC (3 unit)"
            'fasilitas' => FacilityUnit::with('facility')->where('area_label', 'Aula')->where('is_active', true)->get()
                ->groupBy(fn ($u) => $u->facility?->name)->map(fn ($g, $nama) => $g->count() > 1 ? "{$nama} ({$g->count()} unit)" : $nama)->values()->all(),
        ])->layout('components.layouts.guest', ['title' => 'Sewa aula']);
    }
}
