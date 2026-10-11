<?php

namespace App\Livewire\Tamu;

use App\Livewire\Tamu\Concerns\PencarianKamar;
use App\Models\RoomType;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\ReservasiService;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Halaman /kamar/{kode}/pesan: data pemesan dan metode bayar uang muka.
 * Kamar yang dipesan berasal dari pencarian: satu tipe untuk semua kamar, atau kombinasi per kamar
 * (?pilihan=STD,FAM, urut sesuai kamar di ?tamu=). Jumlah dewasa/anak hanya untuk memastikan tipe muat; tidak disimpan.
 */
class PesanKamar extends Component
{
    use PencarianKamar;

    #[Locked]
    public string $kode;

    /** Kode tipe per kamar untuk rekomendasi kombinasi, misalnya "STD,FAM". */
    #[Url(except: '')]
    public string $pilihan = '';

    public string $nama = '';

    public string $hp = '';

    public string $email = '';

    public string $permintaan = '';

    public string $metode = '';

    public function mount(string $kode): void
    {
        $this->kode = Str::upper($kode);
        abort_unless(RoomType::where('code', $this->kode)->exists(), 404);

        if ($user = Auth::user()) {
            $c = $user->customer;
            $this->nama = $c?->full_name ?? $user->name;
            $this->hp = $c?->phone ?? (string) $user->phone;
            $this->email = $c?->email ?? $user->email;
        }
    }

    /**
     * Susunan kamar yang dipesan beserta keadaannya.
     *
     * @return array{kamar: list<array{no: int, dewasa: int, anak: int, tipe: RoomType}>, pesanan: array<int, int>, tipe: Collection, galat: ?string}
     */
    private function susunan(): array
    {
        $cari = $this->pencarian();
        $semua = RoomType::all()->keyBy(fn ($t) => Str::upper($t->code));
        $kodePerKamar = $this->pilihan !== ''
            ? array_map(fn ($k) => Str::upper(trim($k)), explode(',', $this->pilihan))
            : array_fill(0, $cari->jumlah, $this->kode);

        $kamar = [];
        foreach ($cari->kamar as $i => $k) {
            $tipe = $semua->get($kodePerKamar[$i] ?? '');
            if ($tipe) {
                $kamar[] = ['no' => $i + 1, 'dewasa' => $k['dewasa'], 'anak' => $k['anak'], 'tipe' => $tipe];
            }
        }
        $pesanan = collect($kamar)->countBy(fn ($k) => $k['tipe']->id)->all();
        $tipe = $semua->keyBy('id')->only(array_keys($pesanan));

        $galat = match (true) {
            ! $cari->masuk => 'Pilih tanggal menginap dulu di halaman kamar.',
            $cari->galat !== null => $cari->galat,
            count($kamar) !== $cari->jumlah || count($kodePerKamar) !== $cari->jumlah => 'Pilihan kamar tidak lengkap. Pilih ulang dari halaman kamar.',
            default => null,
        };
        if (! $galat) {
            $tidakMuat = collect($kamar)->first(fn ($k) => $k['tipe']->max_capacity < $k['dewasa'] + $k['anak']);
            if ($tidakMuat) {
                $galat = "{$tidakMuat['tipe']->name} hanya muat {$tidakMuat['tipe']->max_capacity} orang, kamar {$tidakMuat['no']} berisi ".($tidakMuat['dewasa'] + $tidakMuat['anak']).' orang.';
            }
        }
        if (! $galat) {
            $sisa = app(KetersediaanKamar::class)->sisa($cari->masuk, $cari->keluar, array_keys($pesanan));
            foreach ($pesanan as $id => $n) {
                if (($sisa[$id] ?? 0) < $n) {
                    $galat = ($sisa[$id] ?? 0) === 0
                        ? "{$tipe[$id]->name} sudah penuh pada tanggal itu."
                        : "{$tipe[$id]->name} tinggal {$sisa[$id]} kamar pada tanggal itu.";
                    break;
                }
            }
        }

        return ['kamar' => $kamar, 'pesanan' => $pesanan, 'tipe' => $tipe, 'galat' => $galat];
    }

    public function pesan(ReservasiService $reservasi, NotifikasiReservasi $notifikasi, PembayaranMidtrans $midtrans)
    {
        $this->nama = PersonName::normalize($this->nama);
        $this->hp = Phone::normalizeInput($this->hp);
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'nama' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'hp' => ['required', new NomorHp],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'permintaan' => ['nullable', 'string', 'max:500'],
            'metode' => ['required', Rule::in(array_keys(PembayaranMidtrans::METODE))],
        ], [
            'nama.required' => 'Isi nama pemesan.',
            'nama.min' => 'Minimal 3 huruf.',
            'nama.regex' => PersonName::MESSAGE,
            'hp.required' => 'Isi nomor WhatsApp untuk konfirmasi.',
            'permintaan.max' => 'Maksimal 500 karakter.',
            'metode.*' => 'Pilih metode pembayaran uang muka.',
        ]);

        $s = $this->susunan();
        if ($s['galat']) {
            $this->addError('kamar', $s['galat']);

            return null;
        }

        $cari = $this->pencarian();
        $pemesan = Pelanggan::dariIsian(['name' => $this->nama, 'phone' => $this->hp, 'email' => $this->email ?: null], Auth::user(), 'hp');
        $r = $reservasi->buatOnline($pemesan, $cari->masuk, $cari->keluar, $s['pesanan'], trim($this->permintaan) ?: null);
        $notifikasi->dibuat($r);

        // Nomor VA atau QRIS dibuat sekarang, jadi halaman reservasi langsung menampilkannya
        try {
            if (PembayaranMidtrans::siap()) {
                $midtrans->buatTagihan($r, $this->metode);
            }
        } catch (Throwable $e) {
            Log::error('Tagihan uang muka Midtrans gagal dibuat', ['reservasi' => $r->reservation_code, 'metode' => $this->metode, 'pesan' => $e->getMessage()]);
            session()->flash('error', PembayaranMidtrans::METODE[$this->metode][0].' sedang tidak dapat dipakai. Pilih metode lain di bawah.');
        }

        return $this->redirect($r->urlStatus());
    }

    public function render(): View
    {
        $cari = $this->pencarian();
        $s = $this->susunan();
        $malam = $cari->malam();
        $total = collect($s['kamar'])->sum(fn ($k) => (float) $k['tipe']->base_price * $malam);

        return view('livewire.tamu.pesan-kamar', [
            'cari' => $cari,
            'kamar' => $s['kamar'],
            'kelompok' => collect($s['kamar'])->groupBy(fn ($k) => $k['tipe']->id)->map(fn ($g) => ['tipe' => $g->first()['tipe'], 'jumlah' => $g->count()])->values(),
            'galat' => $s['galat'],
            'malam' => $malam,
            'total' => $total,
            'uangMuka' => app(ReservasiService::class)->uangMuka($total),
            'persen' => ReservasiService::persenUangMuka(),
            'jamBayar' => ReservasiService::jamBatasBayar(),
            'urlKembali' => route('kamar', $cari->query()),
        ])->layout('components.layouts.guest', ['title' => 'Data pemesan']);
    }
}
