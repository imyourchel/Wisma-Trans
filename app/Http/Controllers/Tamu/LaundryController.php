<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Services\Keuangan\NotaPdf;
use App\Services\Keuangan\PembayaranMidtrans;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman status laundry untuk tamu (tautan dari WhatsApp, berisi token akses). Pembayaran memakai route pesanan.bayar/cek. */
class LaundryController extends Controller
{
    public function show(Request $request, string $kode, PembayaranMidtrans $midtrans, NotaPdf $notaPdf): View
    {
        $order = ServiceOrder::dariKodeUrl($kode);
        abort_unless($order && $order->access_token && hash_equals($order->access_token, (string) $request->query('t'))
            && $order->jenisLayanan() === 'laundry', 404);

        if ($order->invoice) {
            $midtrans->sinkronkan($order);
        }
        $order = $order->fresh(['items.service', 'invoice.payments', 'customer', 'reservationRoom.room']);
        $bayar = $order->payment_mode === 'pay_now' && $order->invoice && ! $order->sudahDibayar()
            && $order->status !== 'cancelled' && PembayaranMidtrans::siap() && $order->batasBayar()->isFuture();

        return view('tamu.laundry-status', [
            'order' => $order,
            'title' => 'Laundry '.$order->order_code,
            'bayar' => $bayar,
            'tagihan' => $bayar ? $midtrans->tagihanAktif($order) : null,
            'urlNota' => $order->invoice && (float) $order->invoice->paid_amount > 0 ? $notaPdf->tautan($order->invoice) : null,
        ]);
    }
}
