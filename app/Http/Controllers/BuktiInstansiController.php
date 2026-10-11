<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\BuktiInstansi;
use Illuminate\Http\Response;

/** Unduh PDF bukti pemesanan instansi lewat tautan bertanda tangan (dari WhatsApp PIC atau halaman reservasi). */
class BuktiInstansiController extends Controller
{
    public function unduh(string $kode, BuktiInstansi $bukti): Response
    {
        $r = Reservation::where('reservation_code', $kode)->firstOrFail();
        abort_unless(BuktiInstansi::berlaku($r), 404);

        return response($bukti->isi($r), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$bukti->namaBerkas($r).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
