<?php

namespace App\Livewire\Tamu\Concerns;

use App\Models\Room;
use App\Models\RoomType;
use App\Services\Reservasi\TambahanKamar;
use App\Support\PencarianInap;
use App\Support\PilihanKamar;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Pilihan kamar per tipe dan jenis kasur (?pilih=FAM.king:1,VIP.king:1), dipakai halaman /kamar dan detail tipe kamar
 * bersama bar ringkasan dan rincian pembagian tamu (partial tamu.partials.bar-pilihan). Butuh trait PencarianKamar.
 *
 * Pembagian tamu per kamar selalu otomatis dan hanya ditampilkan sebagai info: susunan per kamar dari pencarian bila
 * jumlah kamarnya sama (PilihanKamar::bagiSusunan), selain itu PilihanKamar::bagiOtomatis.
 */
trait PilihKamarTamu
{
    #[Url(except: '')]
    public string $pilih = '';

    /** Ubah jumlah kamar satu tipe dan jenis kasur (stepper), dibatasi sisa kasur, sisa tipe, dan MAKS_KAMAR. */
    public function aturKamar(string $kode, string $kasur, int $jumlah): void
    {
        $jumlah = max(0, $jumlah);
        $batas = $this->batasKamar($kode, $kasur);
        if ($batas === null) {
            return;
        }
        $this->pilih = PilihanKamar::atur($this->pilih, $kode, $kasur, min($jumlah, $batas));
    }

    /** Tombol "Pilih" dan + di halaman detail: tambah satu kamar lalu beri tahu tamu, tanpa pindah halaman. */
    public function pilihKasur(string $kode, string $kasur): void
    {
        $t = $this->semuaTipe()->get(Str::upper($kode));
        $nama = $this->namaKasur($kode, $kasur);
        if ($t && in_array($kasur, $t->kodeKasur, true) && $this->cocokKasur($t, $kasur) === RoomType::TIDAK_MUAT) {
            $this->dispatch('toast', teks: "{$nama} tidak muat untuk tamu yang dicari. Pilih jenis kasur lain atau tambah jumlah kamar.", peringatan: true);

            return;
        }
        $sebelum = PilihanKamar::jumlah(PilihanKamar::baca($this->pilih), $kode, $kasur);
        $this->aturKamar($kode, $kasur, $sebelum + 1);
        PilihanKamar::jumlah(PilihanKamar::baca($this->pilih), $kode, $kasur) > $sebelum
            ? $this->dispatch('toast', teks: "{$nama} ditambahkan")
            : $this->dispatch('toast', teks: "{$nama} tidak bisa ditambah lagi untuk tanggal ini.", peringatan: true);
    }

    /** Tombol − di halaman detail: kurangi satu kamar (sampai 0, kembali ke tombol Pilih). */
    public function kurangiKasur(string $kode, string $kasur): void
    {
        $sebelum = PilihanKamar::jumlah(PilihanKamar::baca($this->pilih), $kode, $kasur);
        if ($sebelum === 0) {
            return;
        }
        $this->aturKamar($kode, $kasur, $sebelum - 1);
        $this->dispatch('toast', teks: $this->namaKasur($kode, $kasur).' dikurangi');
    }

    /** "Family · King Bed" untuk toast. */
    private function namaKasur(string $kode, string $kasur): string
    {
        $tipe = $this->semuaTipe()->get(Str::upper($kode));

        return $tipe ? PilihanKamar::namaPendek($tipe).' · '.Room::labelKasur($kasur) : Room::labelKasur($kasur);
    }

    /** Tombol ✕ di rincian: kurangi satu kamar dari kelompok kamar ke-$i. */
    public function kurangiKamar(int $i): void
    {
        $k = PilihanKamar::kamar($this->pilihanBerlaku(), $this->semuaTipe())[$i] ?? null;
        if ($k) {
            $this->aturKamar($k['tipe']->code, $k['kasur'], PilihanKamar::jumlah(PilihanKamar::baca($this->pilih), $k['tipe']->code, $k['kasur']) - 1);
        }
    }

    /** "Isi otomatis termurah": ganti pilihan dengan kombinasi termurah yang menampung semua tamu. */
    public function isiOtomatis(): void
    {
        $opsi = $this->opsiKamar();
        if ($opsi === null) {
            return;
        }
        ['dewasa' => $d, 'anak' => $a] = $this->jumlahTamu();
        // Sesuai susunan per kamar yang dicari; bila tidak bisa, kombinasi termurah untuk total tamu
        $hasil = PilihanKamar::perKamar($opsi, $this->susunanTamu()) ?? PilihanKamar::termurah($opsi, $d, $a);
        if ($hasil === null) {
            $this->dispatch('toast', teks: 'Kamar yang tersedia belum cukup untuk '.PilihanKamar::teksTamu($d, $a).'. Coba tanggal lain atau ajukan pemesanan rombongan.', peringatan: true);

            return;
        }
        $this->pilih = PilihanKamar::tulis($hasil);
    }

    /** Tiap tipe dan jenis kasur dengan harga, kapasitas, dan sisanya untuk tanggal pencarian; null tanpa tanggal yang sah. */
    protected function opsiKamar(): ?array
    {
        $sisa = $this->sisaKamar($tipe = $this->tipeKamar(), $this->pencarian());
        if ($sisa === null) {
            return null;
        }
        $opsi = [];
        foreach ($tipe as $t) {
            foreach ($t->kodeKasur as $kasur) {
                $kap = $t->kapasitasKasur($kasur);
                $opsi[] = ['kode' => Str::upper($t->code), 'kasur' => $kasur, 'harga' => (float) $t->base_price, 'dewasa' => $kap['dewasa'], 'anak' => $kap['anak'],
                    'sisa' => (int) ($sisa['kasur'][$t->id][$kasur] ?? 0), 'sisaTipe' => (int) ($sisa['tipe'][$t->id] ?? 0)];
            }
        }

        return $opsi;
    }

    /**
     * Kartu rekomendasi untuk pencarian beberapa kamar: tipe dan kasur termurah yang muat per kamar (PilihanKamar::perKamarTugas).
     * Tiap kamar juga menyebut jenis kasur lain di tipe itu yang muat dan masih tersisa (lain). Tombolnya memasukkan
     * kombinasi itu ke keranjang (isiOtomatis). Null untuk satu kamar atau bila sisa kamar tidak cukup.
     *
     * @return array{kamar: list<array{no: int, tamu: string, tipe: string, kasur: string, lain: list<string>}>, judul: string, total: float}|null
     */
    protected function rekomendasi(): ?array
    {
        $susunan = $this->susunanTamu();
        $opsi = count($susunan) >= 2 ? $this->opsiKamar() : null;
        $tugas = $opsi === null ? null : PilihanKamar::perKamarTugas($opsi, $susunan);
        if ($tugas === null) {
            return null;
        }
        $tipe = $this->semuaTipe();
        $malam = max(1, $this->pencarian()->malam());

        return [
            'kamar' => collect($tugas)->map(fn ($o, $i) => ['no' => $i + 1, 'tamu' => PilihanKamar::teksTamu($susunan[$i]['dewasa'], $susunan[$i]['anak']),
                'tipe' => PilihanKamar::namaPendek($tipe[$o['kode']]), 'kasur' => Room::labelKasur($o['kasur']),
                'lain' => collect($opsi)->filter(fn ($x) => $x['kode'] === $o['kode'] && $x['kasur'] !== $o['kasur'] && $x['sisa'] > 0
                    && $susunan[$i]['dewasa'] <= $x['dewasa'] && $susunan[$i]['anak'] <= $x['anak'])
                    ->map(fn ($x) => Room::labelKasur($x['kasur']))->values()->all()])->values()->all(),
            'judul' => collect($tugas)->countBy('kode')->sortBy(fn ($n, $k) => (float) $tipe[$k]->base_price)
                ->map(fn ($n, $k) => $n.' '.$tipe[$k]->name)->join(' + '),
            'total' => collect($tugas)->sum(fn ($o) => $o['harga'] * $malam),
        ];
    }

    /** Dipanggil PencarianKamar::cek: tanggal atau tamu berubah, pilihan dirapikan ke sisa yang baru. */
    protected function setelahCek(): void
    {
        $this->pilih = PilihanKamar::tulis($this->pilihanBerlaku());
    }

    /** Tipe kamar (dengan kodeKasur) per kode huruf besar. */
    protected function semuaTipe(): Collection
    {
        return $this->tipeKamar()->keyBy(fn (RoomType $t) => Str::upper($t->code));
    }

    /** Batas jumlah kamar satu tipe dan kasur: sisa kasur, sisa tipe dikurangi kasur lain tipe itu, dan MAKS_KAMAR. Null bila tidak bisa dipilih. */
    private function batasKamar(string $kode, string $kasur): ?int
    {
        $tipe = $this->semuaTipe();
        $t = $tipe->get(Str::upper($kode));
        $sisa = $this->sisaKamar($tipe->values(), $this->pencarian());
        // Jenis kasur yang tidak muat untuk kamar mana pun di pencarian tidak bisa dipilih
        if (! $t || $sisa === null || ! in_array($kasur, $t->kodeKasur, true) || $this->cocokKasur($t, $kasur) === RoomType::TIDAK_MUAT) {
            return null;
        }
        $baris = PilihanKamar::baca($this->pilih);
        $ini = PilihanKamar::jumlah($baris, $kode, $kasur);
        $tipeLain = collect($baris)->where('kode', Str::upper($kode))->sum('jumlah') - $ini;
        $semuaLain = collect($baris)->sum('jumlah') - $ini;

        return max(0, min((int) ($sisa['kasur'][$t->id][$kasur] ?? 0), (int) ($sisa['tipe'][$t->id] ?? 0) - $tipeLain, PencarianInap::MAKS_KAMAR - $semuaLain));
    }

    /** Pilihan di URL yang masih berlaku untuk tanggal pencarian (dibatasi sisa); tanpa tanggal semuanya tetap. */
    protected function pilihanBerlaku(): array
    {
        $baris = PilihanKamar::baca($this->pilih);
        $tipe = $this->semuaTipe();
        $sisa = $this->sisaKamar($tipe->values(), $this->pencarian());
        $terpakai = [];
        $hasil = [];
        foreach ($baris as $b) {
            $t = $tipe->get($b['kode']);
            if (! $t || ! in_array($b['kasur'], $t->kodeKasur, true)) {
                continue;
            }
            $n = $sisa === null ? $b['jumlah']
                : min($b['jumlah'], (int) ($sisa['kasur'][$t->id][$b['kasur']] ?? 0), (int) ($sisa['tipe'][$t->id] ?? 0) - ($terpakai[$t->id] ?? 0));
            if ($n > 0) {
                $terpakai[$t->id] = ($terpakai[$t->id] ?? 0) + $n;
                $hasil[] = ['kode' => $b['kode'], 'kasur' => $b['kasur'], 'jumlah' => $n];
            }
        }

        return $hasil;
    }

    /**
     * Semua yang dibutuhkan bar ringkasan dan rincian: kamar fisik dengan pembagian tamu otomatis, status pembagian,
     * total, chip per kelompok, dan tautan LANJUTKAN (hanya bila tanggal sah dan pembagian lengkap).
     */
    protected function ringkasanPilihan(): array
    {
        $cari = $this->pencarian();
        $tipe = $this->semuaTipe();
        $baris = $this->pilihanBerlaku();
        $kamar = PilihanKamar::kamar($baris, $tipe);
        ['dewasa' => $dewasa, 'anak' => $anak] = $this->jumlahTamu();
        $malam = max(1, $cari->malam());
        $extra = app(TambahanKamar::class)->extraTersedia();

        // Pembagian awal: susunan per kamar dari pencarian bila jumlah kamarnya sama, selain itu dibagi otomatis
        $otomatis = PilihanKamar::bagiSusunan($kamar, $this->susunanTamu(), $extra) ?? PilihanKamar::bagiOtomatis($kamar, $dewasa, $anak);
        $isi = [];
        foreach ($kamar as $i => $k) {
            $bagi = $otomatis[$i];
            $perluExtra = $k['tipe']->extraPerlu($k['kasur'], $bagi['dewasa'], $bagi['anak']) ?? 0;
            $isi[] = [
                'tipe' => $k['tipe'],
                'kasur' => $k['kasur'],
                'judul' => $k['tipe']->name.' · '.Room::labelKasur($k['kasur']),
                'maks' => PilihanKamar::teksMaks($k['tipe'], $k['kasur']),
                'foto' => $k['tipe']->photoUrls()[0] ?? null,
                'harga' => (float) $k['tipe']->base_price * $malam,
                'bagi' => $bagi,
                'nilai' => $bagi['dewasa'].'-'.$bagi['anak'],
                'extra' => $perluExtra,
                'teksBagi' => $bagi['dewasa'] + $bagi['anak'] === 0 ? 'Belum ada tamu'
                    : PilihanKamar::teksTamu($bagi['dewasa'], $bagi['anak']).($perluExtra > 0 ? ' · perlu extra bed' : ''),
            ];
        }
        $status = PilihanKamar::status(array_column($isi, 'bagi'), $dewasa, $anak);
        $siap = $isi !== [] && $cari->aktif() && $status['lengkap'];

        return [
            'kamar' => $isi,
            'jumlah' => count($isi),
            'malam' => $cari->malam(),
            'rentang' => $this->rentangPendek($cari),
            'total' => array_sum(array_column($isi, 'harga')),
            'chip' => collect($baris)->map(fn ($b) => PilihanKamar::namaPendek($tipe[$b['kode']]).' · '.Room::labelKasur($b['kasur']).' × '.$b['jumlah'])->all(),
            'status' => $status,
            'dewasa' => $dewasa,
            'anak' => $anak,
            'aktif' => $cari->aktif(),
            'pilih' => PilihanKamar::tulis($baris),
            'urlLanjut' => $siap ? route('kamar.pesan', Str::lower($isi[0]['tipe']->code)).'?'.http_build_query([
                'masuk' => $cari->masuk->toDateString(),
                'keluar' => $cari->keluar->toDateString(),
                'tamu' => implode('_', array_column($isi, 'nilai')),
                'pilihan' => implode(',', array_map(fn ($k) => Str::upper($k['tipe']->code), $isi)),
                'kasur' => implode(',', array_column($isi, 'kasur')),
            ]) : null,
        ];
    }

    /** Parameter URL pencarian dan pilihan, untuk berpindah antara /kamar dan detail tipe tanpa kehilangan pilihan. */
    protected function queryCari(bool $denganPilih = true): array
    {
        $cari = $this->pencarian();

        return array_filter([
            'masuk' => $cari->masuk?->toDateString(),
            'keluar' => $cari->keluar?->toDateString(),
            'tamu' => PencarianInap::teks($this->susunanTamu()),
            'pilih' => $denganPilih ? PilihanKamar::tulis($this->pilihanBerlaku()) : '',
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** "26–27 Okt" atau "30 Okt – 2 Nov" (tanpa tahun). */
    private function rentangPendek(PencarianInap $cari): string
    {
        if (! $cari->aktif()) {
            return '';
        }

        return $cari->masuk->isSameMonth($cari->keluar)
            ? $cari->masuk->day.'–'.$cari->keluar->translatedFormat('j M')
            : $cari->masuk->translatedFormat('j M').' – '.$cari->keluar->translatedFormat('j M');
    }
}
