<?php

namespace App\Http\Controllers\Staf\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\RekapKas;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Rekap kas harian siap cetak (pengganti rekap Excel): ringkasan per metode lalu daftar transaksi berhasil. */
class RekapKasController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tanggal = strtotime((string) $request->query('tanggal')) ? Carbon::parse($request->query('tanggal')) : today();

        return view('staf.keuangan.rekap-cetak', [
            'rekap' => RekapKas::untuk($tanggal, $request->user()),
            'transaksi' => Payment::query()->terlihatOleh($request->user())->with(['invoice.customer', 'receivedBy'])
                ->where('status', 'success')->whereDate('paid_at', $tanggal->toDateString())->orderBy('paid_at')->orderBy('id')->get(),
            'oleh' => $request->user(),
        ]);
    }
}
