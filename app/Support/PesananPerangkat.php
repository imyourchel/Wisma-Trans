<?php

namespace App\Support;

use App\Models\HallBooking;
use App\Models\Reservation;
use App\Models\ServiceOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Tab "Pesanan terakhir" (tersimpan di peramban ini) untuk tamu tanpa akun (halaman Cek Pesanan), seperti riwayat pesanan di aplikasi pemesanan.
 * Setiap kali tamu yang belum masuk membuka halaman status pesanannya (kamar, makanan, laundry) atau mengirim pengajuan
 * aula, kodenya dicatat di cookie wt_pesanan. Cookie itu dienkripsi Laravel (tidak bisa dibaca atau dipalsukan di
 * peramban) dan HttpOnly. Kamar dan layanan yang dibuka lewat tautan bertoken ikut menyimpan tokennya; saat ditampilkan
 * token dicocokkan lagi, jadi pesanan yang tokennya sudah berubah tidak muncul.
 * Pesanan yang ditemukan lewat Cek Pesanan dicatat LIHAT SAJA (token kosong): tidak pernah menyimpan atau menampilkan
 * token, dan dibuka lewat route cek.perangkat.buka (izin lihat baru di session) ke halaman hanya-lihat.
 */
final class PesananPerangkat
{
    public const COOKIE = 'wt_pesanan';

    /** Jumlah pesanan terakhir yang diingat. */
    public const MAKS = 20;

    /** Lama cookie: 180 hari. */
    public const MENIT = 60 * 24 * 180;

    /** $lihat: dibuka dari Cek Pesanan (hanya-lihat), jadi token tidak disimpan. */
    public static function reservasi(Reservation $r, bool $lihat = false): void
    {
        self::catat('kamar', $r->reservation_code, $lihat ? null : $r->access_token);
    }

    public static function layanan(ServiceOrder $o, bool $lihat = false): void
    {
        self::catat('layanan', $o->order_code, $lihat ? null : $o->access_token);
    }

    /** Pesanan itu tercatat di perangkat ini (bertoken atau lihat saja). */
    public static function ada(string $jenis, string $kode): bool
    {
        return collect(self::entri())->contains(fn ($e) => str_starts_with($e, $jenis.'|'.$kode.'|'));
    }

    public static function aula(HallBooking $b): void
    {
        self::catat('aula', $b->booking_code, null);
    }

    /**
     * Pesanan di perangkat ini dalam bentuk PesananAkun, terbaru dulu, ditambah 'kunci' untuk menghapusnya dari daftar.
     *
     * @return Collection<int, array>
     */
    public static function daftar(): Collection
    {
        $entri = collect(self::entri())->map(fn ($e) => explode('|', $e, 3));
        $token = fn (string $jenis) => $entri->where(0, $jenis)->mapWithKeys(fn ($e) => [$e[1] => $e[2]]);

        // Kamar dan layanan bertoken: hanya yang token aksesnya masih sama. Tanpa token: lihat saja.
        $cocok = fn (Collection $simpan, Collection $asli) => $simpan->filter(fn ($t, $kode) => $t !== '' && isset($asli[$kode]) && hash_equals((string) $asli[$kode], $t))->keys()->all();
        $lihat = fn (Collection $simpan) => $simpan->filter(fn ($t) => $t === '')->keys()->all();
        $kamar = $token('kamar');
        $layanan = $token('layanan');
        $hasil = PesananAkun::dariKode(
            [...$cocok($kamar, Reservation::whereIn('reservation_code', $kamar->keys())->whereNotNull('access_token')->pluck('access_token', 'reservation_code')), ...$lihat($kamar)],
            [...$cocok($layanan, ServiceOrder::whereIn('order_code', $layanan->keys())->whereNotNull('access_token')->pluck('access_token', 'order_code')), ...$lihat($layanan)],
            $token('aula')->keys()->all(),
        );
        $hanyaLihat = ['kamar' => $lihat($kamar), 'layanan' => $lihat($layanan)];

        return $hasil->map(function ($p) use ($hanyaLihat) {
            $jenis = in_array($p['jenis'], ['makanan', 'laundry'], true) ? 'layanan' : $p['jenis'];
            // Lihat saja: tautan bertoken dari PesananAkun diganti tautan hanya-lihat, jadi token tidak pernah tampil
            if (in_array($p['kode'], $hanyaLihat[$jenis] ?? [], true)) {
                $buka = route('cek.perangkat.buka', ['jenis' => $p['jenis'] === 'kamar' ? 'reservasi' : $p['jenis'], 'kode' => str_replace('/', '-', $p['kode'])]);
                $p['aksi'] = collect($p['aksi'])->map(fn ($a) => str_contains($a[1], 't=') ? [$a[0], $buka, $a[2]] : $a)->all();
                $p['hanyaLihat'] = true;
            }

            return $p + ['kunci' => $jenis.'|'.$p['kode'], 'hanyaLihat' => false];
        })->values();
    }

    /** Hapus satu pesanan ("jenis|kode") dari daftar, atau semuanya bila $kunci null. */
    public static function lupakan(?string $kunci): void
    {
        $sisa = $kunci === null ? [] : collect(self::entri())->reject(fn ($e) => str_starts_with($e, $kunci.'|'))->values()->all();
        $sisa ? self::simpan($sisa) : Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** Pengguna yang sudah masuk tidak dicatat: pesanannya ada di Pesanan saya. */
    private static function catat(string $jenis, string $kode, ?string $token): void
    {
        if (Auth::check() || $kode === '') {
            return;
        }
        $kunci = $jenis.'|'.$kode;
        $entri = collect(self::entri());
        // Entri bertoken tidak diturunkan menjadi lihat saja saat pesanan yang sama dicari lewat Cek Pesanan
        $lama = $entri->first(fn ($e) => str_starts_with($e, $kunci.'|'));
        $token = $token ?: ($lama ? explode('|', $lama, 3)[2] : '');
        self::simpan($entri
            ->reject(fn ($e) => str_starts_with($e, $kunci.'|'))
            ->prepend($kunci.'|'.$token)
            ->take(self::MAKS)->values()->all());
    }

    /** @return list<string> "jenis|kode|token"; cookie yang sudah diantre di permintaan ini didahulukan */
    private static function entri(): array
    {
        $antre = Cookie::queued(self::COOKIE);
        $isi = json_decode((string) ($antre ? $antre->getValue() : request()->cookie(self::COOKIE)), true);

        return is_array($isi) ? array_values(array_filter($isi, fn ($e) => is_string($e) && substr_count($e, '|') === 2)) : [];
    }

    private static function simpan(array $entri): void
    {
        Cookie::queue(self::COOKIE, json_encode(array_values($entri)), self::MENIT);
    }
}
