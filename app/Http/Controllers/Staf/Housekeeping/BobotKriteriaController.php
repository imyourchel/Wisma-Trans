<?php

namespace App\Http\Controllers\Staf\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\CleaningCriterion;
use App\Services\AhpService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Bobot kriteria Smart Cleaning untuk Manager (hanya dibaca): bobot dari penelitian terdahulu dan matriks AHP-nya. */
class BobotKriteriaController extends Controller
{
    public function __invoke(AhpService $ahp): View
    {
        Gate::authorize('view-cleaning-priority');
        $sesi = $ahp->sesiAktif();

        return view('staf.housekeeping.bobot', [
            'kriteria' => CleaningCriterion::where('is_active', true)->orderBy('code')->get(),
            'sesi' => $sesi,
            'bobot' => $sesi ? $ahp->bobot((int) $sesi->id) : [],
            'hitung' => $sesi ? $ahp->hitungDariPerbandingan((int) $sesi->id) : null,
        ]);
    }
}
