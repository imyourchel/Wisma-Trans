<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Support\MenuStaf;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menu sidebar yang halamannya dibangun pada tahap berikutnya. */
class SegeraController extends Controller
{
    public function __invoke(Request $request, string $slug): View
    {
        $menu = MenuStaf::cariSlug($slug) ?? abort(404);

        if ($request->user()->roles->whereIn('code', $menu['peran'])->isEmpty()) {
            abort(403, 'Halaman ini bukan untuk peran Anda.');
        }

        return view('staf.segera', ['judul' => $menu['label'], 'grup' => $menu['grup']]);
    }
}
