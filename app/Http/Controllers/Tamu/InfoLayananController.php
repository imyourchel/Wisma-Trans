<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\Hall;
use App\Services\Layanan\LaundryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman informasi /aula dan /laundry. Formulirnya ada di halaman sendiri (aula.ajukan, laundry.pesan) yang dibuka
 * lewat tombol, seperti alur kamar (daftar → detail → pesan).
 */
class InfoLayananController extends Controller
{
    public function aula(): View
    {
        return view('tamu.aula', [
            'daftarAula' => Hall::where('is_active', true)->orderBy('id')->get(),
            'tarif' => Hall::tarif(),
        ]);
    }

    public function laundry(Request $request): View|RedirectResponse
    {
        // Tautan lama dari WhatsApp (/laundry?t=token) langsung ke formulir yang mengenali kamar tamu
        if ($request->filled('t')) {
            return redirect()->route('laundry.pesan', $request->query());
        }

        return view('tamu.laundry', [
            'layanan' => LaundryService::layanan(),
            'minKg' => LaundryService::MIN_KG,
        ]);
    }
}
