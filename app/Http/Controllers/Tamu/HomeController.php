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
        $menus = Service::where('category', 'fnb')
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('menu_group', 'Rekomendasi')->orWhere('created_at', '>=', now()->subDays(30)))
            ->orderByRaw("menu_group = 'Rekomendasi' desc")
            ->orderBy('id')
            ->limit(5)
            ->get();

        return view('tamu.home', [
            'roomTypes' => $roomTypes,
            'menus' => $menus,
            'roomCount' => Room::where('is_active', true)->count(),
            'hallCapacity' => Hall::where('is_active', true)->max('capacity') ?? 150,
            'laundryPrice' => Service::where('code', 'LDR01')->value('price'),
        ]);
    }
}
