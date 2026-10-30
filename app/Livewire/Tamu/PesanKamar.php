<?php

namespace App\Livewire\Tamu;

use App\Livewire\Tamu\Concerns\PencarianKamar;
use App\Models\Room;
use App\Models\RoomType;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\ReservasiService;
use App\Services\Reservasi\TambahanKamar;
use App\Support\Pelanggan;
use App\Support\PencarianInap;
use App\Support\PersonName;
use App\Support\Phone;
use App\Support\PilihanKamar;
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
 * Halaman /kamar/{kode}/pesan (langkah 2 dari 3): data pemesan dan metode bayar uang muka.
 * Kamar yang dipesan berasal dari bar pilihan di /kamar atau detail tipe: tipe per kamar (?pilihan=FAM,VIP), jenis kasur
 * per kamar (?kasur=king,king), dan pembagian tamu per kamar (?tamu=2-2_2-0), urut sama. Tanpa ?pilihan= semua kamar
 * memakai tipe {kode}. Pembagian tamu dipakai untuk memeriksa kapasitas dan dicatat sebagai teks di log reservasi.
 * Per kamar bisa ditambah Extra bed dan Sarapan tambahan (TambahanKamar); uang muka dihitung dari total termasuk tambahan.
 * Jenis kasur per kamar dibawa dari halaman detail (?kasur=twin_single,double, urut kamar) dan dijamin dengan nomor kamar
 * saat reservasi dibuat; kamar tanpa pilihan kasur (rekomendasi kombinasi) mendapat kamar bebas mana pun dari tipenya.
 */
class PesanKamar extends Component
{
    use PencarianKamar;

    #[Locked]
    public string $kode;

    /** Kode tipe per kamar untuk rekomendasi kombinasi, misalnya "STD,FAM". */
    #[Url(except: '')]
    public string $pilihan = '';

    /** Jenis kasur per kamar dari halaman detail, misalnya "twin_single,double" (Room::BED_TYPES). */
    #[Url(except: '')]
    public string $kasur = '';

    public string $nama = '';

    public string $hp = '';

    public string $email = '';

    public string $permintaan = '';

    public string $metode = '';

    /** Extra bed dan Sarapan tambahan per kamar: nomor kamar => ['extra' => n, 'sarapan' => n]. */
    public array $tambahan = [];

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
     * Per kamar: kapasitas = dewasa dan anak kasur pilihan, atau yang terbesar dari tipe bila kasur tidak dipilih
     * (RoomType::ATURAN_KAPASITAS); wajib = extra bed yang harus ditambahkan karena dewasanya melebihi kapasitas kasur;
     * muat = tamu tertampung (dengan extra bed yang tersedia; anak hanya dibatasi batas anak, tanpa extra bed).
     *
     * @return array{kamar: list<array{no: int, dewasa: int, anak: int, tipe: RoomType, kasur: ?string, kapasitas: array{dewasa: int, anak: int}, wajib: int, muat: bool}>, pesanan: array<int, int>, tipe: Collection, galat: ?string}
     */
    private function susunan(): array
    {
        $cari = $this->pencarian();
        $semua = RoomType::all()->keyBy(fn ($t) => Str::upper($t->code));
        $kodePerKamar = $this->pilihan !== ''
            ? array_map(fn ($k) => Str::upper(trim($k)), explode(',', $this->pilihan))
            : array_fill(0, $cari->jumlah, $this->kode);

        $kasurPerKamar = $this->kasur !== '' ? array_map('trim', explode(',', $this->kasur)) : [];
        $extraTersedia = app(TambahanKamar::class)->extraTersedia();

        $kamar = [];
        foreach ($cari->kamar as $i => $k) {
            $tipe = $semua->get($kodePerKamar[$i] ?? '');
            $kasur = $kasurPerKamar[$i] ?? null;
            $kasur = isset(Room::BED_TYPES[$kasur]) ? $kasur : null;
            if ($tipe) {
                $kap = $kasur ? $tipe->kapasitasKasur($kasur) : $tipe->kapasitasTerbesar();
                $perlu = $kasur ? $tipe->extraPerlu($kasur, $k['dewasa'], $k['anak']) : 0;
                $kamar[] = ['no' => $i + 1, 'dewasa' => $k['dewasa'], 'anak' => $k['anak'], 'tipe' => $tipe, 'kasur' => $kasur, 'kapasitas' => $kap,
                    'wajib' => $perlu ?? 0,
                    'muat' => $kasur ? $perlu !== null && $perlu <= $extraTersedia : $k['dewasa'] <= $kap['dewasa'] && $k['anak'] <= $kap['anak']];
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
            // Jenis kasur pilihan boleh kurang sebanyak extra bed yang tersedia; extra bed itu lalu diwajibkan (pilihanTambahan)
            $tidakMuat = collect($kamar)->first(fn ($k) => ! $k['muat']);
            if ($tidakMuat) {
                $galat = $tidakMuat['kasur']
                    ? Room::labelKasur($tidakMuat['kasur'])." {$tidakMuat['tipe']->name} hanya muat ".$tidakMuat['tipe']->teksKapasitas($tidakMuat['kasur'])
                        .", kamar {$tidakMuat['no']} berisi {$tidakMuat['dewasa']} dewasa".($tidakMuat['anak'] ? " dan {$tidakMuat['anak']} anak" : '').'. Silakan pilih jenis kasur lain.'
                    : "{$tidakMuat['tipe']->name} hanya muat ".RoomType::teksBatas($tidakMuat['kapasitas']).", kamar {$tidakMuat['no']} berisi ".PilihanKamar::teksTamu($tidakMuat['dewasa'], $tidakMuat['anak']).'.';
            }
        }
        if (! $galat) {
            $sisa = app(KetersediaanKamar::class)->sisa($cari->masuk, $cari->keluar, array_keys($pesanan));
            foreach ($pesanan as $id => $n) {
                if (($sisa[$id] ?? 0) < $n) {
                    $no = collect($kamar)->filter(fn ($k) => $k['tipe']->id === $id)->values()[$sisa[$id] ?? 0]['no'];
                    $galat = ($sisa[$id] ?? 0) === 0
                        ? "Maaf, {$tipe[$id]->name} sudah penuh pada tanggal itu. Silakan pilih tipe lain untuk Kamar {$no}."
                        : "Maaf, {$tipe[$id]->name} tinggal {$sisa[$id]} kamar pada tanggal itu. Silakan pilih tipe lain untuk Kamar {$no}.";
                    break;
                }
            }
        }
        // Jenis kasur pilihan masih cukup untuk tanggal itu
        if (! $galat && $kasurPerKamar !== []) {
            $sisaKasur = app(KetersediaanKamar::class)->sisaKasur($cari->masuk, $cari->keluar, array_keys($pesanan));
            foreach (collect($kamar)->whereNotNull('kasur')->groupBy(fn ($k) => $k['tipe']->id.'|'.$k['kasur']) as $g) {
                $k = $g->first();
                $ada = (int) ($sisaKasur[$k['tipe']->id][$k['kasur']] ?? 0);
                if ($ada < $g->count()) {
                    $galat = 'Maaf, '.PilihanKamar::namaPendek($k['tipe']).' · '.Room::labelKasur($k['kasur']).' baru saja habis. Silakan pilih jenis kasur lain untuk Kamar '.$g->values()[$ada]['no'].'.';
                    break;
                }
            }
        }

        return ['kamar' => $kamar, 'pesanan' => $pesanan, 'tipe' => $tipe, 'galat' => $galat];
    }

    /** Centang atau ubah jumlah tambahan satu kamar. Extra bed dilepas: Sarapan tambahan ikut dilepas. */
    public function aturTambahan(int $no, string $jenis, int $jumlah): void
    {
        $kamar = collect($this->susunan()['kamar'])->firstWhere('no', $no);
        if (! $kamar || ! in_array($jenis, ['extra', 'sarapan'], true)) {
            return;
        }
        // Sarapan tambahan baru dicentang: mulai 1 orang; Extra bed dicentang ulang: sarapan tetap kosong
        $this->tambahan[$no] = [$jenis => $jumlah] + ($this->tambahan[$no] ?? []);
        $this->tambahan[$no] = $this->pilihanTambahan($kamar);
        $this->resetErrorBag('tambahan');
    }

    /** Pilihan tambahan yang berlaku untuk satu kamar; extra bed yang wajib (tamu melebihi kapasitas kasur) selalu ikut. */
    private function pilihanTambahan(array $k): array
    {
        $p = app(TambahanKamar::class)->rapikan($this->tambahan[$k['no']] ?? [], $k['tipe'], $k['kasur']);

        return $p['extra'] >= $k['wajib'] ? $p : app(TambahanKamar::class)->rapikan(['extra' => $k['wajib']] + $p, $k['tipe'], $k['kasur']);
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
        // Urut kamar per tipe, sama dengan urutan baris kamar yang dibuat ReservasiService::buatOnline
        $perTipe = collect($s['kamar'])->groupBy(fn ($k) => $k['tipe']->id);
        // Isian dikirim apa adanya supaya tetap diperiksa server; extra bed yang wajib selalu ikut
        $tambahan = $perTipe->map(fn ($g) => $g->map(fn ($k) => ['extra' => max((int) ($this->tambahan[$k['no']]['extra'] ?? 0), $k['wajib'])] + ($this->tambahan[$k['no']] ?? []))
            ->values()->all())->all();
        $kasur = $perTipe->map(fn ($g) => $g->pluck('kasur')->values()->all())->all();
        $orang = $perTipe->map(fn ($g) => $g->map(fn ($k) => ['dewasa' => $k['dewasa'], 'anak' => $k['anak']])->values()->all())->all();
        $r = $reservasi->buatOnline($pemesan, $cari->masuk, $cari->keluar, $s['pesanan'], trim($this->permintaan) ?: null, $tambahan, $kasur, $orang);
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
        $totalKamar = collect($s['kamar'])->sum(fn ($k) => (float) $k['tipe']->base_price * $malam);
        $tambahan = app(TambahanKamar::class);
        $pilihan = collect($s['kamar'])->mapWithKeys(fn ($k) => [$k['no'] => $this->pilihanTambahan($k)]);
        // Ringkasan tambahan digabung per jenis: "Extra bed × 2 · 2 malam", "Sarapan tambahan · 3 orang × 2 malam"
        $rincianTambahan = $pilihan->flatMap(fn ($p) => $tambahan->rincian($p, $malam))->groupBy('jenis')
            ->map(fn ($g, $jenis) => ['teks' => TambahanKamar::teksRincian($jenis, $g->sum('jumlah'), $malam), 'subtotal' => $g->sum('subtotal')])->values();
        $total = $totalKamar + $rincianTambahan->sum('subtotal');

        return view('livewire.tamu.pesan-kamar', [
            'cari' => $cari,
            'kamar' => $s['kamar'],
            'layananTambahan' => $tambahan->layanan(),
            'pilihanTambahan' => $pilihan,
            'rincianTambahan' => $rincianTambahan,
            'totalKamar' => $totalKamar,
            'kelompok' => collect($s['kamar'])->groupBy(fn ($k) => $k['tipe']->id.'|'.$k['kasur'])
                ->map(fn ($g) => ['tipe' => $g->first()['tipe'], 'kasur' => $g->first()['kasur'], 'jumlah' => $g->count()])->values(),
            'galat' => $s['galat'],
            'malam' => $malam,
            'total' => $total,
            'uangMuka' => app(ReservasiService::class)->uangMuka($total),
            'persen' => ReservasiService::persenUangMuka(),
            'jamBayar' => ReservasiService::jamBatasBayar(),
            'jumlahTamu' => ['dewasa' => collect($s['kamar'])->sum('dewasa'), 'anak' => collect($s['kamar'])->sum('anak')],
            'urlKembali' => route('kamar', array_filter([
                'masuk' => $cari->masuk?->toDateString(),
                'keluar' => $cari->keluar?->toDateString(),
                'tamu' => PencarianInap::teksTotal(['dewasa' => collect($cari->kamar)->sum('dewasa'), 'anak' => collect($cari->kamar)->sum('anak')]),
                'pilih' => PilihanKamar::tulis(collect($s['kamar'])->whereNotNull('kasur')->groupBy(fn ($k) => Str::upper($k['tipe']->code).'.'.$k['kasur'])
                    ->map(fn ($g) => ['kode' => Str::upper($g->first()['tipe']->code), 'kasur' => $g->first()['kasur'], 'jumlah' => $g->count()])->values()->all()),
            ])),
        ])->layout('components.layouts.guest', ['title' => 'Selesaikan pemesanan']);
    }
}
