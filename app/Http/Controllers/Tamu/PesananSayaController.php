<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Support\PesananAkun;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Semua pesanan milik akun yang sedang masuk: kamar, makanan, laundry, dan aula, termasuk yang belum dibayar
 * dan yang dibatalkan. Penyaring bekerja di peramban, jadi alamat halaman tetap /pesanan-saya.
 */
class PesananSayaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $semua = PesananAkun::untuk($request->user());

        return view('tamu.pesanan-saya', [
            'title' => 'Pesanan saya',
            'pesanan' => $semua,
            'jumlah' => $semua->countBy('keadaan')->put('semua', $semua->count()),
            'kodeKamar' => PesananAkun::kodeKamar($request->user()),
        ]);
    }
}
