<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Services\Keuangan\NotaPdf;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Support\PesananPerangkat;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman status laundry untuk tamu (tautan dari WhatsApp, berisi token akses), atau lihat saja dari Cek Pesanan.
 * Pembayaran memakai route pesanan.bayar/cek.
 */
class LaundryController extends Controller
{
    public function __construct(private PembayaranMidtrans $midtrans, private NotaPdf $notaPdf) {}

    public function show(Request $request, string $kode): View
    {
        $order = ServiceOrder::dariKodeUrl($kode);
        abort_unless($order && $order->access_token && hash_equals($order->access_token, (string) $request->query('t'))
            && $order->jenisLayanan() === 'laundry', 404);

        return $this->tampilkan($order, false);
    }

    /** Halaman hanya-lihat dari Cek Pesanan; pemanggil (CekPesananLihatController) sudah memeriksa izin session. */
    public function lihat(ServiceOrder $order): View
    {
        return $this->tampilkan($order, true);
    }

    private function tampilkan(ServiceOrder $order, bool $hanyaLihat): View
    {
        if ($order->invoice) {
            $this->midtrans->sinkronkan($order);
        }
        $order = $order->fresh(['items.service', 'invoice.payments', 'customer', 'reservationRoom.room']);
        // Tamu tanpa akun: masuk ke tab "Pesanan terakhir" di Cek Pesanan (lihat saja tanpa token)
        PesananPerangkat::layanan($order, $hanyaLihat);
        $bayar = $order->payment_mode === 'pay_now' && $order->invoice && ! $order->sudahDibayar()
            && $order->status !== 'cancelled' && PembayaranMidtrans::siap() && $order->batasBayar()->isFuture();

        return view('tamu.laundry-status', [
            'order' => $order,
            'title' => 'Laundry '.$order->order_code,
            'hanyaLihat' => $hanyaLihat,
            'bayar' => $bayar,
            'tagihan' => $bayar ? $this->midtrans->tagihanAktif($order) : null,
            'urlNota' => $order->invoice && (float) $order->invoice->paid_amount > 0 ? $this->notaPdf->tautan($order->invoice) : null,
        ]);
    }
}
