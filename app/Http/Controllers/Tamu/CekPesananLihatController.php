<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ServiceOrder;
use App\Services\Notifikasi\WhatsAppService;
use App\Support\IzinCekPesanan;
use App\Support\PesananPerangkat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Halaman pesanan LIHAT SAJA dari Cek Pesanan (reservasi, makanan, laundry). Hanya tampil bila izin lihat di session
 * masih berlaku (IzinCekPesanan, 30 menit); token akses tidak pernah dikirim ke peramban. Batal dan ubah tanggal tetap
 * lewat tautan bertoken dari WhatsApp/email atau akun pemilik; tamu bisa meminta tautan itu dikirim ulang ke nomor
 * WhatsApp pemesan yang tersimpan.
 */
class CekPesananLihatController extends Controller
{
    /** Kirim ulang tautan: sekali per 10 menit per pesanan. */
    public const DETIK_KIRIM_ULANG = 600;

    public function show(string $jenis, string $kode): View|RedirectResponse
    {
        $pesanan = $this->pesanan($jenis, $kode);
        if (! $pesanan) {
            return $this->sesiBerakhir();
        }

        return match ($jenis) {
            'reservasi' => app(ReservasiTamuController::class)->lihat($pesanan),
            'makanan' => app(PesananMakananController::class)->lihat($pesanan),
            default => app(LaundryController::class)->lihat($pesanan),
        };
    }

    /** Kirim tautan bertoken ke nomor WhatsApp pemesan di basis data (bukan nomor yang diketik di Cek Pesanan). */
    public function kirimUlang(string $jenis, string $kode, WhatsAppService $wa): RedirectResponse
    {
        $pesanan = $this->pesanan($jenis, $kode);
        if (! $pesanan) {
            return $this->sesiBerakhir();
        }
        $kodeAsli = $pesanan instanceof Reservation ? $pesanan->reservation_code : $pesanan->order_code;
        $kunci = 'kirim-ulang-tautan:'.$jenis.'|'.$kodeAsli;
        if (RateLimiter::tooManyAttempts($kunci, 1)) {
            $menit = (int) ceil(RateLimiter::availableIn($kunci) / 60);

            return back()->with('error', "Tautan baru saja dikirim. Kirim ulang lagi dalam {$menit} menit.");
        }

        $hp = $pesanan->customer?->phone;
        $nama = Str::before((string) $pesanan->customer?->full_name, ' ');
        $judul = ['reservasi' => 'reservasi', 'makanan' => 'pesanan makanan', 'laundry' => 'laundry'][$jenis];
        $teks = "Halo {$nama}, ini tautan {$judul} Wisma Trans {$kodeAsli}:\n{$pesanan->urlStatus()}\n\n"
            .'Lewat tautan ini Anda bisa membayar'.($jenis === 'reservasi' ? ', mengubah tanggal, atau membatalkan reservasi' : ' dan memantau pesanan')
            .'. Jangan bagikan tautan ini kepada orang lain.';
        if (! $wa->kirim($hp, $teks)) {
            return back()->with('error', 'Tautan belum dapat dikirim. Hubungi resepsionis 0822-9222-4111.');
        }
        RateLimiter::hit($kunci, self::DETIK_KIRIM_ULANG);

        return back()->with('status', 'Tautan dikirim ke WhatsApp '.IzinCekPesanan::samarkan($hp).'.');
    }

    /**
     * Tab "Pesanan terakhir": pesanan lihat saja yang tercatat di cookie terenkripsi peramban ini (hanya bisa dibuat
     * server setelah pencarian berhasil) mendapat izin lihat baru, lalu dibuka di halaman lihat saja.
     */
    public function bukaPerangkat(string $jenis, string $kode): RedirectResponse
    {
        $pesanan = $this->cari($jenis, $kode);
        $kodeAsli = $pesanan instanceof Reservation ? $pesanan->reservation_code : $pesanan?->order_code;
        if (! $pesanan || ! PesananPerangkat::ada($jenis === 'reservasi' ? 'kamar' : 'layanan', $kodeAsli)) {
            return redirect()->route('cek')->with('error', 'Pesanan ini tidak ada di daftar pesanan terakhir. Cari ulang dengan kode pesanan.');
        }
        IzinCekPesanan::beri($jenis, $kodeAsli);

        return redirect()->route('cek.lihat', ['jenis' => $jenis, 'kode' => $kode]);
    }

    /** Pesanan jenis itu yang izin lihatnya masih berlaku; null bila tidak ada atau izin berakhir. */
    private function pesanan(string $jenis, string $kode): Reservation|ServiceOrder|null
    {
        $pesanan = $this->cari($jenis, $kode);
        $kodeAsli = $pesanan instanceof Reservation ? $pesanan->reservation_code : $pesanan?->order_code;

        return $pesanan && IzinCekPesanan::berlaku($jenis, $kodeAsli) ? $pesanan : null;
    }

    /** kode di alamat: RSV-0016, atau ORD-2026-10-0084 untuk ORD/2026/10/0084. */
    private function cari(string $jenis, string $kode): Reservation|ServiceOrder|null
    {
        if ($jenis === 'reservasi') {
            return Reservation::where('reservation_code', strtoupper($kode))->whereNotNull('access_token')->first();
        }
        $order = ServiceOrder::dariKodeUrl(strtoupper($kode));

        return $order && $order->access_token && $order->jenisLayanan() === $jenis ? $order : null;
    }

    private function sesiBerakhir(): RedirectResponse
    {
        return redirect()->route('cek')->with('error', 'Sesi cek pesanan berakhir, cari ulang.');
    }
}
