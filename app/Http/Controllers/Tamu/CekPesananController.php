<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\HallBooking;
use App\Models\Reservation;
use App\Models\ServiceOrder;
use App\Support\IzinCekPesanan;
use App\Support\PesananPerangkat;
use App\Support\Phone;
use App\Support\TokenAkses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cek pesanan tanpa masuk akun, dua tab: "Pesanan terakhir" yang dibuat atau dibuka di peramban ini (PesananPerangkat),
 * dan pencarian dengan kode pesanan (kamar, makanan, laundry, aula) serta nomor WhatsApp pemesan.
 */
class CekPesananController extends Controller
{
    /** Khusus tamu tanpa akun; yang sudah masuk diarahkan ke Pesanan saya. */
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('pesanan');
        }

        return view('tamu.cek-pesanan', ['perangkat' => PesananPerangkat::daftar()]);
    }

    /** Hapus satu pesanan dari daftar pesanan terakhir (kunci "jenis|kode"), atau semuanya. */
    public function lupakan(Request $request): RedirectResponse
    {
        $kunci = $request->input('kunci');
        $satu = is_string($kunci) && $kunci !== '';
        PesananPerangkat::lupakan($satu ? $kunci : null);

        return redirect()->route('cek')->with('status', $satu ? 'Pesanan dihapus dari daftar pesanan terakhir.' : 'Daftar pesanan terakhir sudah dikosongkan.');
    }

    /**
     * Cari satu pesanan dengan kode pesanan dan nomor WhatsApp pemesan: reservasi kamar (RSV-…), pesanan makanan atau
     * laundry (ORD/…), dan sewa aula (HB-…). Untuk reservasi, kode kamar 8 karakter juga diterima.
     * Hasilnya akses LIHAT SAJA (izin di session, IzinCekPesanan), bukan tautan bertoken: kode pesanan berurutan dan
     * nomor WhatsApp bukan rahasia, jadi pembatalan dan ubah tanggal tetap lewat tautan dari WhatsApp/email atau akun.
     * Selain throttle per IP, tiap kode hanya boleh gagal 5 kali (dari perangkat mana pun) sebelum terkunci 15 menit.
     * Aula yang belum punya halaman status langsung dimasukkan ke daftar pesanan terakhir.
     */
    public function cari(Request $request): RedirectResponse
    {
        $request->merge([
            'kode' => strtoupper((string) preg_replace('/\s+/', '', (string) $request->input('kode'))),
            // Nama isian lama "token" tetap diterima
            'kunci' => trim((string) $request->input('kunci', $request->input('token'))),
        ]);
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:30'],
            'kunci' => ['required', 'string', 'max:30'],
        ], [
            'kode.required' => 'Isi kode pesanan.',
            'kunci.required' => 'Isi nomor WhatsApp pemesan.',
        ]);

        // Kode yang tidak dikenal juga dihitung (kunci = isian apa adanya), jadi tidak bisa dipakai menebak kode yang ada
        [$jenis, $kode] = $this->normalkan($data['kode']);
        if ($detik = IzinCekPesanan::sisaKunci($kode)) {
            throw ValidationException::withMessages(['kode' => 'Terlalu banyak percobaan untuk kode ini. Coba lagi dalam '
                .(int) ceil($detik / 60).' menit atau hubungi resepsionis.']);
        }

        $pesanan = $this->temukan($jenis, $kode, $data['kunci']);
        if (! $pesanan) {
            IzinCekPesanan::gagal($kode);

            throw ValidationException::withMessages(['kode' => 'Kode pesanan atau nomor WhatsApp tidak cocok. Periksa lagi pesan konfirmasi Anda.']);
        }
        IzinCekPesanan::berhasil($kode);

        if ($pesanan instanceof HallBooking) {
            PesananPerangkat::aula($pesanan);

            return redirect()->route('cek')->with('status', "Pengajuan aula {$pesanan->booking_code} ditambahkan ke daftar pesanan terakhir.");
        }
        $jenisLihat = $pesanan instanceof Reservation ? 'reservasi' : $pesanan->jenisLayanan();
        IzinCekPesanan::beri($jenisLihat, $kode);

        return redirect()->route('cek.lihat', ['jenis' => $jenisLihat, 'kode' => str_replace('/', '-', $kode)]);
    }

    /**
     * Jenis dan kode baku: RSV0016 → RSV-0016; ORD-2026-10-0084 atau ORD2026100084 → ORD/2026/10/0084; HB7 → HB-7.
     * Kode yang tidak dikenali: jenis null, kode apa adanya.
     *
     * @return array{0: ?string, 1: string}
     */
    private function normalkan(string $kode): array
    {
        $angka = (string) preg_replace('/\D/', '', $kode);

        return match (true) {
            (bool) preg_match('/^RSV-?\d+$/', $kode) => ['reservasi', 'RSV-'.$angka],
            (bool) preg_match('/^ORD[-\/]?\d{4}[-\/]?\d{2}[-\/]?\d+$/', $kode) => ['layanan', 'ORD/'.substr($angka, 0, 4).'/'.substr($angka, 4, 2).'/'.substr($angka, 6)],
            (bool) preg_match('/^HB-?\d+$/', $kode) => ['aula', 'HB-'.$angka],
            default => [null, $kode],
        };
    }

    /** Pesanan yang kodenya cocok dan nomor WhatsApp pemesannya (atau kode kamar reservasinya) sama; selain itu null. */
    private function temukan(?string $jenis, string $kode, string $kunci): Reservation|ServiceOrder|HallBooking|null
    {
        $pesanan = match ($jenis) {
            'reservasi' => Reservation::with('customer')->where('reservation_code', $kode)->first(),
            'layanan' => ServiceOrder::with('customer')->where('order_code', $kode)->first(),
            'aula' => HallBooking::with('customer')->where('booking_code', $kode)->first(),
            default => null,
        };
        // Reservasi, makanan, dan laundry punya halaman status bertoken; tanpa token tidak bisa dibuka tamu
        if (! $pesanan || (! $pesanan instanceof HallBooking && ! $pesanan->access_token)) {
            return null;
        }

        $token = TokenAkses::rapikan($kunci);
        if ($pesanan instanceof Reservation && strlen($token) === 8 && hash_equals($pesanan->access_token, $token)) {
            return $pesanan;
        }
        $hp = Phone::normalize($kunci);
        $milik = Phone::normalize((string) $pesanan->customer?->phone);

        return strlen($hp) >= 10 && $milik !== '' && hash_equals($milik, $hp) ? $pesanan : null;
    }
}
