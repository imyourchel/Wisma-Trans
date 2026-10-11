<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\KodeKamar;
use App\Services\PembayaranMidtrans;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Katalog menu restoran untuk tamu. Tamu hanya melihat; pemesanan lewat staf, telepon kamar, atau WhatsApp. */
class RestoController extends Controller
{
    public function __invoke(Request $request, KodeKamar $kodeKamar): View
    {
        // Urutan kelompok mengikuti urutan menu di basis data (sama dengan menu cetak)
        $menu = Service::where('category', 'fnb')->where('is_active', true)->orderBy('id')->get();
        $ids = Service::terlarisIds();

        // Isian pemesan diisi otomatis untuk tamu yang sudah masuk
        $user = $request->user();
        $pemesan = [
            'name' => $user?->customer?->full_name ?? $user?->name ?? '',
            'phone' => $user?->customer?->phone ?? $user?->phone ?? '',
            'email' => $user?->email ?? '',
        ];

        // Tamu menginap: tautan /resto?t=KODE langsung dicocokkan (batas percobaan sama dengan isian kode kamar)
        $kodeGalat = null;
        $dariTautan = false;
        if ($request->filled('t')) {
            try {
                $dariTautan = (bool) $kodeKamar->cocokkan((string) $request->query('t'), (string) $request->ip());
            } catch (ValidationException $e) {
                $kodeGalat = collect($e->errors())->flatten()->first();
            }
        }
        $kamarTamu = $kodeKamar->kamarUntuk($user, $kodeKamar->reservasiSesi());

        return view('resto', [
            'title' => 'Resto dan Kafe',
            'grup' => $menu->groupBy(fn (Service $s) => $s->menu_group ?: 'Lainnya'),
            'terlaris' => $menu->whereIn('id', $ids)->sortBy(fn (Service $s) => array_search($s->id, $ids))->values(),
            // Hanya menu berharga tetap yang tidak sedang habis yang dapat dipesan online; menu sesuai ukuran dipesan di tempat
            'katalog' => $menu->whereNotNull('price')->reject(fn (Service $s) => $s->habis())->mapWithKeys(fn (Service $s) => [$s->id => ['nama' => $s->name, 'harga' => (int) $s->price]]),
            'pemesan' => $pemesan,
            'bayarOnline' => PembayaranMidtrans::siap(),
            'kamarTamu' => $kamarTamu,
            // Datang lewat tautan kode kamar: pilihan tamu menginap langsung aktif
            'jenisAwal' => $dariTautan || $kodeGalat ? 'menginap' : 'luar',
            'kodeGalat' => $kodeGalat,
        ]);
    }
}
