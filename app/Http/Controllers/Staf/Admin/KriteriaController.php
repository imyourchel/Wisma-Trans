<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Http\Controllers\Controller;
use App\Models\CleaningCriterion;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Kriteria prioritas pembersihan (C1–C3) beserta bobot AHP-nya. Keduanya sudah tetap dari penelitian terdahulu
 * dan diisi langsung di basis data, jadi halaman ini hanya untuk dibaca: tidak ada tambah, ubah, atau nonaktifkan.
 */
class KriteriaController extends Controller
{
    public function index(): View
    {
        $sesi = DB::table('ahp_sessions')->where('is_active', true)->orderByDesc('calculated_at')->first();

        return view('staf.admin.kriteria', [
            'kriteria' => CleaningCriterion::orderBy('code')->get(),
            'sesi' => $sesi,
            'bobot' => $sesi ? DB::table('ahp_criteria_weights')->where('ahp_session_id', $sesi->id)->pluck('weight', 'criteria_id') : collect(),
        ]);
    }
}
