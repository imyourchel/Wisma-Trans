<?php

namespace App\Support;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pilihan kamar tamu di halaman /kamar dan detail tipe: jumlah kamar per tipe dan jenis kasur, dibawa di URL
 * (?pilih=FAM.king:1,VIP.king:1), lalu dipecah menjadi kamar fisik dan pembagian tamu per kamar.
 *
 * Kamar fisik selalu berurutan per tipe (urutan kemunculan tipe di pilihan) supaya nomor "Kamar n" sama dengan urutan baris
 * kamar yang dibuat ReservasiService::buatOnline.
 */
final class PilihanKamar
{
    /**
     * "FAM.king:1,VIP.king:1" menjadi daftar baris; kode tipe huruf besar, kasur harus Room::BED_TYPES, jumlah 1–MAKS_KAMAR,
     * baris kembar digabung, dan total kamar dibatasi PencarianInap::MAKS_KAMAR.
     *
     * @return list<array{kode: string, kasur: string, jumlah: int}>
     */
    public static function baca(?string $pilih): array
    {
        $baris = [];
        $total = 0;
        foreach (explode(',', (string) $pilih) as $bagian) {
            if (! preg_match('/^([A-Za-z0-9_-]{1,20})\.([a-z_]{1,20}):(\d{1,2})$/', trim($bagian), $m) || ! isset(Room::BED_TYPES[$m[2]])) {
                continue;
            }
            $kunci = Str::upper($m[1]).'.'.$m[2];
            $n = min((int) $m[3], PencarianInap::MAKS_KAMAR - $total);
            if ($n <= 0) {
                continue;
            }
            $baris[$kunci] = ['kode' => Str::upper($m[1]), 'kasur' => $m[2], 'jumlah' => ($baris[$kunci]['jumlah'] ?? 0) + $n];
            $total += $n;
        }

        return array_values($baris);
    }

    /** Kebalikan baca(); baris berjumlah 0 dibuang. */
    public static function tulis(array $baris): string
    {
        return collect($baris)->filter(fn ($b) => $b['jumlah'] > 0)
            ->map(fn ($b) => $b['kode'].'.'.$b['kasur'].':'.$b['jumlah'])->join(',');
    }

    /** Ubah jumlah satu tipe dan kasur menjadi $jumlah (0 = hapus); baris baru ditaruh di akhir. */
    public static function atur(string $pilih, string $kode, string $kasur, int $jumlah): string
    {
        $baris = collect(self::baca($pilih))->keyBy(fn ($b) => $b['kode'].'.'.$b['kasur'])->all();
        $kunci = Str::upper($kode).'.'.$kasur;
        $baris[$kunci] = ['kode' => Str::upper($kode), 'kasur' => $kasur, 'jumlah' => max(0, $jumlah)];

        return self::tulis($baris);
    }

    /** Jumlah kamar satu tipe dan kasur di pilihan. */
    public static function jumlah(array $baris, string $kode, string $kasur): int
    {
        return (int) (collect($baris)->first(fn ($b) => $b['kode'] === Str::upper($kode) && $b['kasur'] === $kasur)['jumlah'] ?? 0);
    }

    /**
     * Kamar fisik dari pilihan, berurutan per tipe. Tipe yang tidak dikenal (atau kasur yang tidak dimiliki tipenya) dilewati.
     *
     * @param  Collection<string, RoomType>  $tipe  kode huruf besar => tipe (dengan atribut kodeKasur)
     * @return list<array{tipe: RoomType, kasur: string, kunci: string}>
     */
    public static function kamar(array $baris, Collection $tipe): array
    {
        $urutTipe = [];
        foreach ($baris as $b) {
            $urutTipe[$b['kode']] ??= count($urutTipe);
        }
        $kamar = [];
        foreach (collect($baris)->sortBy(fn ($b, $i) => [$urutTipe[$b['kode']], $i]) as $b) {
            $t = $tipe->get($b['kode']);
            if (! $t || ! in_array($b['kasur'], $t->kodeKasur ?? [$b['kasur']], true)) {
                continue;
            }
            for ($i = 0; $i < $b['jumlah']; $i++) {
                $kamar[] = ['tipe' => $t, 'kasur' => $b['kasur'], 'kunci' => $b['kode'].'.'.$b['kasur']];
            }
        }

        return $kamar;
    }

    /**
     * Pembagian tamu otomatis tanpa extra bed: kamar berkapasitas terbesar diisi lebih dulu. Tiap kamar mendapat satu dewasa
     * dulu (anak selalu bersama dewasa), lalu sisa dewasa sampai batas dewasa kasurnya, lalu anak sampai batas anak kamar itu.
     * Tamu yang tidak tertampung tidak dibagi; status() melaporkannya.
     *
     * @param  list<array{tipe: RoomType, kasur: string}>  $kamar
     * @return list<array{dewasa: int, anak: int}>
     */
    public static function bagiOtomatis(array $kamar, int $dewasa, int $anak): array
    {
        $hasil = array_fill(0, count($kamar), ['dewasa' => 0, 'anak' => 0]);
        $kap = array_map(fn ($k) => $k['tipe']->kapasitasKasur($k['kasur']), $kamar);
        $urut = collect($kap)->map(fn ($c, $i) => [-$c['dewasa'], -$c['anak'], $i])->sort()->keys()->all();

        foreach ($urut as $i) {
            if ($dewasa > 0) {
                $hasil[$i]['dewasa'] = 1;
                $dewasa--;
            }
        }
        foreach ($urut as $i) {
            $n = min($dewasa, $kap[$i]['dewasa'] - $hasil[$i]['dewasa']);
            if ($n > 0) {
                $hasil[$i]['dewasa'] += $n;
                $dewasa -= $n;
            }
        }
        foreach ($urut as $i) {
            if ($hasil[$i]['dewasa'] === 0) {
                continue;
            }
            $n = min($anak, $kap[$i]['anak'] - $hasil[$i]['anak']);
            if ($n > 0) {
                $hasil[$i]['anak'] += $n;
                $anak -= $n;
            }
        }

        return $hasil;
    }

    /**
     * Pembagian dari susunan tamu pencarian ("Kamar 1: 2 dewasa 2 anak, ..."), bila jumlah kamar yang dipilih sama dengan
     * jumlah kamar yang dicari: kelompok terbesar dicoba ke kamar berkapasitas terbesar lebih dulu, lalu pasangan lain bila
     * tidak muat. Null bila jumlahnya berbeda atau tidak ada pasangan yang muat (dengan extra bed yang tersedia); pemanggil
     * lalu memakai bagiOtomatis.
     *
     * @param  list<array{tipe: RoomType, kasur: string}>  $kamar
     * @param  list<array{dewasa: int, anak: int}>  $susunan
     * @return list<array{dewasa: int, anak: int}>|null
     */
    public static function bagiSusunan(array $kamar, array $susunan, int $extraTersedia): ?array
    {
        if ($kamar === [] || count($kamar) !== count($susunan)) {
            return null;
        }
        $urutKamar = collect($kamar)->map(function ($k, $i) {
            $c = $k['tipe']->kapasitasKasur($k['kasur']);

            return [-$c['dewasa'], -$c['anak'], $i];
        })->sort()->keys()->all();
        $urutTamu = collect($susunan)->map(fn ($s, $i) => [-$s['dewasa'], -$s['anak'], $i])->sort()->keys()->values()->all();
        $muat = function (int $i, array $s) use ($kamar, $extraTersedia) {
            $perlu = $kamar[$i]['tipe']->extraPerlu($kamar[$i]['kasur'], $s['dewasa'], $s['anak']);

            return $perlu !== null && $perlu <= $extraTersedia;
        };

        // Penelusuran per kelompok tamu; susunan kamar terpakai yang sudah pasti gagal dicatat (paling banyak 2^10)
        $hasil = [];
        $gagal = [];
        $cari = function (int $n, int $terpakai) use (&$cari, &$hasil, &$gagal, $urutKamar, $urutTamu, $susunan, $muat): bool {
            if ($n === count($urutTamu)) {
                return true;
            }
            if (isset($gagal[$terpakai])) {
                return false;
            }
            $s = $susunan[$urutTamu[$n]];
            foreach ($urutKamar as $i) {
                if (! ($terpakai & (1 << $i)) && $muat($i, $s)) {
                    $hasil[$i] = ['dewasa' => $s['dewasa'], 'anak' => $s['anak']];
                    if ($cari($n + 1, $terpakai | (1 << $i))) {
                        return true;
                    }
                    unset($hasil[$i]);
                }
            }
            $gagal[$terpakai] = true;

            return false;
        };
        if (! $cari(0, 0)) {
            return null;
        }
        ksort($hasil);

        return array_values($hasil);
    }

    /**
     * "Isi otomatis termurah" untuk susunan per kamar: tiap kamar (penghuni terbanyak dulu) mendapat tipe dan kasur termurah
     * yang muat tanpa extra bed dan masih tersisa (kasur terkecil yang muat bila harga sama). Null bila ada kamar yang
     * tidak kebagian.
     *
     * @param  list<array{kode: string, kasur: string, harga: float, dewasa: int, anak: int, sisa: int, sisaTipe: int}>  $opsi
     * @param  list<array{dewasa: int, anak: int}>  $susunan
     * @return list<array{kode: string, kasur: string, jumlah: int}>|null
     */
    public static function perKamar(array $opsi, array $susunan): ?array
    {
        $tugas = self::perKamarTugas($opsi, $susunan);
        if ($tugas === null) {
            return null;
        }
        $hasil = [];
        foreach ($tugas as $o) {
            $k = $o['kode'].'.'.$o['kasur'];
            $hasil[$k] = ['kode' => $o['kode'], 'kasur' => $o['kasur'], 'jumlah' => ($hasil[$k]['jumlah'] ?? 0) + 1];
        }

        return array_values($hasil);
    }

    /**
     * Pilihan per kamar dari perKamar: indeks susunan => opsi (tipe, kasur, harga), urut kamar. Null bila ada kamar yang tidak kebagian.
     *
     * Kelompok yang paling sedikit pilihannya (misalnya membawa 2 anak, hanya Family) dilayani lebih dulu.
     *
     * @return array<int, array{kode: string, kasur: string, harga: float, dewasa: int, anak: int}>|null
     */
    public static function perKamarTugas(array $opsi, array $susunan): ?array
    {
        $sisa = collect($opsi)->mapWithKeys(fn ($o) => [$o['kode'].'.'.$o['kasur'] => $o['sisa']])->all();
        $sisaTipe = collect($opsi)->mapWithKeys(fn ($o) => [$o['kode'] => $o['sisaTipe']])->all();
        $urutOpsi = collect($opsi)->sortBy(fn ($o) => [$o['harga'], $o['dewasa'], $o['anak']])->values();
        $muat = fn ($o, $s) => $s['dewasa'] <= $o['dewasa'] && $s['anak'] <= $o['anak'];
        $hasil = [];
        foreach (collect($susunan)->sortBy(fn ($s) => [$urutOpsi->filter(fn ($o) => $muat($o, $s))->count(), -$s['dewasa'], -$s['anak']]) as $i => $s) {
            $o = $urutOpsi->first(fn ($o) => $muat($o, $s) && $sisa[$o['kode'].'.'.$o['kasur']] > 0 && $sisaTipe[$o['kode']] > 0);
            if (! $o) {
                return null;
            }
            $sisa[$o['kode'].'.'.$o['kasur']]--;
            $sisaTipe[$o['kode']]--;
            $hasil[$i] = $o;
        }
        ksort($hasil);

        return $hasil;
    }

    /** Ringkasan pencarian: "2 kamar · 4 dewasa · 2 anak". */
    public static function teksPencarian(array $susunan): string
    {
        return count($susunan).' kamar · '.self::teksTamu(array_sum(array_column($susunan, 'dewasa')), array_sum(array_column($susunan, 'anak')));
    }

    /**
     * Keadaan pembagian: tamu yang belum mendapat kamar, kelebihan dibanding pencarian, dan kamar tanpa dewasa.
     *
     * @param  list<array{dewasa: int, anak: int}>  $bagi
     * @return array{lengkap: bool, kurangDewasa: int, kurangAnak: int, lebih: bool, kosong: int, pesan: string}
     */
    public static function status(array $bagi, int $dewasa, int $anak): array
    {
        $d = array_sum(array_column($bagi, 'dewasa'));
        $a = array_sum(array_column($bagi, 'anak'));
        $kurangDewasa = max(0, $dewasa - $d);
        $kurangAnak = max(0, $anak - $a);
        $lebih = $d > $dewasa || $a > $anak;
        $kosong = count(array_filter($bagi, fn ($b) => $b['dewasa'] === 0));
        $kurang = trim(($kurangDewasa ? "{$kurangDewasa} dewasa" : '').($kurangDewasa && $kurangAnak ? ' dan ' : '').($kurangAnak ? "{$kurangAnak} anak" : ''));

        $pesan = match (true) {
            $bagi === [] => '',
            $kurang !== '' => "Masih ada {$kurang} tanpa kamar. Silakan tambah kamar.",
            $lebih => 'Pembagian melebihi jumlah tamu ('.self::teksTamu($dewasa, $anak).').',
            $kosong > 0 => ($kosong === 1 ? 'Ada 1 kamar' : "Ada {$kosong} kamar").' tanpa tamu dewasa. Kurangi kamar.',
            default => 'Semua tamu ('.self::teksTamu($dewasa, $anak).') sudah mendapat kamar.',
        };

        return ['lengkap' => $bagi !== [] && $kurang === '' && ! $lebih && $kosong === 0, 'kurangDewasa' => $kurangDewasa, 'kurangAnak' => $kurangAnak,
            'lebih' => $lebih, 'kosong' => $kosong, 'pesan' => $pesan];
    }

    /**
     * Kombinasi kamar termurah yang menampung semua tamu (tombol "Isi otomatis termurah"), paling banyak MAKS_KAMAR kamar.
     * Pemrograman dinamis atas sisa tamu (dewasa, anak): tiap kamar diisi 1..batas dewasa kasurnya dan anak sampai batas anaknya.
     * Jatah kamar per tipe dibagi ke jenis kasurnya mulai dari yang terbesar, sehingga tidak melebihi sisa tipe.
     * Bila harga sama, kamar paling sedikit menang. Null bila sisa kamar tidak cukup.
     *
     * @param  list<array{kode: string, kasur: string, harga: float, dewasa: int, anak: int, sisa: int, sisaTipe: int}>  $opsi
     * @return list<array{kode: string, kasur: string, jumlah: int}>|null
     */
    public static function termurah(array $opsi, int $dewasa, int $anak): ?array
    {
        // Salinan kamar per opsi, dibatasi sisa kasur dan sisa tipe
        $salinan = [];
        foreach (collect($opsi)->groupBy('kode') as $kelompok) {
            $jatah = (int) $kelompok->first()['sisaTipe'];
            foreach ($kelompok->sortBy(fn ($o) => [-$o['dewasa'], -$o['anak']]) as $o) {
                $n = min($o['sisa'], $jatah, PencarianInap::MAKS_KAMAR);
                $jatah -= $n;
                for ($i = 0; $i < $n; $i++) {
                    $salinan[] = $o;
                }
            }
        }

        // keadaan "dewasa,anak" sisa => [biaya, kamar, jejak]
        $awal = "{$dewasa},{$anak}";
        $keadaan = [$awal => [0.0, 0, []]];
        foreach ($salinan as $j => $o) {
            $baru = $keadaan;
            foreach ($keadaan as $kunci => [$biaya, $n, $jejak]) {
                [$d, $a] = array_map('intval', explode(',', $kunci));
                if ($d === 0 || $n >= PencarianInap::MAKS_KAMAR) {
                    continue;
                }
                for ($x = min($d, $o['dewasa']); $x >= 1; $x--) {
                    $y = min($a, $o['anak']);
                    $k = ($d - $x).','.($a - $y);
                    $calon = [$biaya + $o['harga'], $n + 1, [...$jejak, $j]];
                    if (! isset($baru[$k]) || [$calon[0], $calon[1]] < [$baru[$k][0], $baru[$k][1]]) {
                        $baru[$k] = $calon;
                    }
                }
            }
            $keadaan = $baru;
        }
        if (! isset($keadaan['0,0']) || $keadaan['0,0'][1] === 0) {
            return null;
        }

        $hasil = [];
        foreach ($keadaan['0,0'][2] as $j) {
            $k = $salinan[$j]['kode'].'.'.$salinan[$j]['kasur'];
            $hasil[$k] = ['kode' => $salinan[$j]['kode'], 'kasur' => $salinan[$j]['kasur'], 'jumlah' => ($hasil[$k]['jumlah'] ?? 0) + 1];
        }

        return array_values($hasil);
    }

    /** "2 dewasa · 2 anak" atau "2 dewasa". */
    public static function teksTamu(int $dewasa, int $anak): string
    {
        return $dewasa.' dewasa'.($anak > 0 ? ' · '.$anak.' anak' : '');
    }

    /** Daya tampung kasur untuk tamu: "2 dewasa + 2 anak". */
    public static function teksKapasitas(RoomType $tipe, string $kasur): string
    {
        return $tipe->teksKapasitas($kasur);
    }

    /** Teks kartu kasur: "Maks. 2 dewasa + 1 anak". */
    public static function teksMaks(RoomType $tipe, string $kasur): string
    {
        return 'Maks. '.$tipe->teksKapasitas($kasur);
    }

    /** Nama tipe tanpa "Room" untuk chip: "Family Room" → "Family", "VIP Room / Suite" → "VIP". */
    public static function namaPendek(RoomType $tipe): string
    {
        return trim(Str::of($tipe->name)->before(' /')->replaceMatches('/\s+Room$/i', '')->value()) ?: $tipe->name;
    }
}
