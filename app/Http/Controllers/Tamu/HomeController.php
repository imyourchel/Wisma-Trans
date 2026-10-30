<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\Hall;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Service;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $roomTypes = RoomType::with(['facilities' => fn ($q) => $q->orderBy('facilities.id')])
            ->orderBy('base_price')
            ->get();

        // Menu pilihan: kelompok Rekomendasi lebih dulu, lalu menu yang baru ditambahkan
        $menus = Service::menuKafe()
            ->where(fn ($q) => $q->where('menu_group', 'Rekomendasi')->orWhere('created_at', '>=', now()->subDays(30)))
            ->orderByRaw("menu_group = 'Rekomendasi' desc")
            ->orderBy('id')
            ->limit(5)
            ->get();

        $aula = Hall::where('is_active', true)->orderBy('id')->first();

        return view('tamu.home', [
            'roomTypes' => $roomTypes,
            'menus' => $menus,
            'roomCount' => Room::where('is_active', true)->count(),
            // Beranda menonjolkan "75+ orang"; kapasitas penuh (kolom capacity) tampil di halaman /aula
            'hallCapacity' => Hall::KAPASITAS_PROMOSI,
            'tarifAula' => Hall::tarif(),
            'fotoAula' => $aula?->photoUrls()[0] ?? asset('ds/img/aula.jpg'),
            'laundryPrice' => Service::where('code', 'LDR01')->value('price'),
        ]);
    }
}
