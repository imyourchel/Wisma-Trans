<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Support\TokenAkses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Cek pesanan tanpa masuk akun: kode reservasi dan token 8 karakter dari WhatsApp atau email konfirmasi. */
class CekPesananController extends Controller
{
    /** Khusus tamu tanpa akun; yang sudah masuk diarahkan ke Pesanan saya. */
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('pesanan');
        }

        return view('tamu.cek-pesanan');
    }

    public function cari(Request $request): RedirectResponse
    {
        $request->merge([
            'kode' => strtoupper(trim((string) $request->input('kode'))),
            'token' => TokenAkses::rapikan((string) $request->input('token')),
        ]);
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20'],
            'token' => ['required', 'string', 'size:8'],
        ], [
            'kode.required' => 'Isi kode reservasi.',
            'token.required' => 'Isi kode kamar dari pesan konfirmasi.',
            'token.size' => 'Kode kamar terdiri dari 8 huruf dan angka.',
        ]);

        // Kode tanpa tanda hubung juga diterima: RSV0016 menjadi RSV-0016
        $kode = preg_replace('/^RSV-?/', 'RSV-', $data['kode']);
        $r = Reservation::where('reservation_code', $kode)->first();
        if (! $r || ! $r->access_token || ! hash_equals($r->access_token, $data['token'])) {
            throw ValidationException::withMessages(['kode' => 'Kode reservasi atau kode kamar tidak cocok. Periksa lagi pesan konfirmasi Anda.']);
        }

        return redirect($r->urlStatus());
    }
}
