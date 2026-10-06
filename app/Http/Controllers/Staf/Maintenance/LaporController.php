<?php

namespace App\Http\Controllers\Staf\Maintenance;

use App\Http\Requests\Maintenance\LaporRequest;
use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Lapor kerusakan dan daftar laporan saya, untuk semua staf operasional (Manager, FO, F&B, HK). */
class LaporController extends MaintenanceController
{
    public function create(): View
    {
        Gate::authorize('report-damage');
        $unit = FacilityUnit::with('facility')->where('is_active', true)->orderBy('unit_label')->get();

        return view('staf.maintenance.lapor', [
            'kamar' => Room::where('is_active', true)->orderBy('room_number')->get(['id', 'room_number', 'status'])
                ->map(fn ($r) => ['id' => $r->id, 'nomor' => $r->room_number, 'status' => $r->status->value, 'label' => $r->status->label()]),
            'area' => LaporRequest::daftarArea(),
            // Unit per kamar (kunci id kamar) dan per area umum (kunci nama area)
            'unitKamar' => $unit->whereNotNull('room_id')->groupBy('room_id')->map(fn ($g) => $g->map(fn ($u) => ['id' => $u->id, 'label' => $u->unit_label])->values()),
            'unitArea' => $unit->whereNull('room_id')->groupBy('area_label')->map(fn ($g) => $g->map(fn ($u) => ['id' => $u->id, 'label' => $u->unit_label])->values()),
            'unitTerbuka' => (object) LaporRequest::laporanTerbukaPerUnit(),
        ]);
    }

    public function store(LaporRequest $request, MaintenanceService $layanan): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $kamar = $data['lokasi'] === 'kamar' ? Room::findOrFail((int) $data['room_id']) : null;
        $foto = collect($request->file('foto', []))->map(fn ($f) => $f->store('maintenance', 'public'))->all();

        [$laporan, $peringatan] = $layanan->lapor($request->user(), $request->unitTerpilih(), $kamar,
            $kamar ? null : $data['area_label'], $data['deskripsi'], $data['priority'],
            $kamar !== null && $data['tidak_bisa_dipakai'], $foto);

        return $this->selesai($request, "Laporan {$laporan->request_code} terkirim ke Manager.", route('staf.maintenance.saya'), $peringatan);
    }

    public function saya(Request $request): View
    {
        Gate::authorize('report-damage');

        return view('staf.maintenance.saya', [
            'laporan' => MaintenanceRequest::with('unit.room', 'room', 'handler')
                ->where('reported_by', $request->user()->id)->where('maintenance_type', 'corrective')
                ->orderByDesc('reported_at')->orderByDesc('id')->paginate(20),
        ]);
    }
}
