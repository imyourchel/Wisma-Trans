<?php

namespace App\Http\Controllers\Staf\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Jawaban formulir Maintenance: dikirim lewat fetch, lalu halaman dimuat ulang (atau pindah ke $ke) dengan pesan kilat. */
abstract class MaintenanceController extends Controller
{
    protected function selesai(Request $request, string $pesan, ?string $ke = null, ?string $peringatan = null): JsonResponse|RedirectResponse
    {
        $request->session()->flash('status', $pesan);
        if ($peringatan) {
            $request->session()->flash('peringatan', $peringatan);
        }

        return $request->expectsJson()
            ? response()->json(['pesan' => $pesan, 'redirect' => $ke, 'peringatan' => $peringatan])
            : ($ke ? redirect($ke) : back());
    }
}
