<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Facility;
use App\Models\FacilityUnit;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Unit fisik fasilitas (misalnya AC-101) yang dijadwalkan perawatannya oleh Manager. */
class UnitFasilitasController extends MasterController
{
    public function index(): View
    {
        return view('staf.admin.unit-fasilitas', [
            'unit' => FacilityUnit::with(['facility', 'room'])->orderByDesc('is_active')->orderBy('unit_label')->get(),
            'fasilitas' => Facility::orderBy('name')->get(),
            'kamar' => Room::orderBy('room_number')->get(['id', 'room_number', 'floor']),
        ]);
    }

    /** Area unit di kamar selalu mengikuti lantai kamarnya, supaya jadwal seperti "AC Lantai 2" mencakup unit yang benar. */
    public static function areaKamar(Room $kamar): string
    {
        return 'Lantai '.$kamar->floor;
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request);
        FacilityUnit::create($data + ['is_active' => true]);

        return $this->selesai($request, "Unit {$data['unit_label']} ditambahkan.");
    }

    public function update(Request $request, FacilityUnit $unit): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request, $unit);
        $unit->update($data);

        return $this->selesai($request, "Unit {$data['unit_label']} disimpan.");
    }

    /** Unit yang masih punya laporan kerusakan terbuka tidak bisa dinonaktifkan, supaya laporannya tidak kehilangan unit. */
    public function toggle(FacilityUnit $unit): RedirectResponse
    {
        if ($unit->is_active) {
            $terbuka = $unit->maintenanceRequests()->whereIn('status', ['reported', 'in_progress'])->orderBy('reported_at')->pluck('request_code');
            if ($terbuka->isNotEmpty()) {
                return back()->with('error', "Unit {$unit->unit_label} masih punya laporan kerusakan yang belum selesai ({$terbuka->implode(', ')}). Selesaikan atau batalkan laporannya dulu.");
            }
        }

        return $this->balikAktif($unit, 'Unit '.$unit->unit_label);
    }

    private function validasi(Request $request, ?FacilityUnit $unit = null): array
    {
        $request->merge([
            'unit_label' => trim((string) $request->input('unit_label')),
            'area_label' => trim((string) $request->input('area_label')),
        ]);
        // Unit di kamar: area tidak diketik, diambil dari lantai kamar
        $kamar = filled($request->input('room_id')) ? Room::find((int) $request->input('room_id')) : null;
        if ($kamar) {
            $request->merge(['area_label' => self::areaKamar($kamar)]);
        }

        return $request->validate([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'unit_label' => ['required', 'string', 'max:50', Rule::unique('facility_units', 'unit_label')->ignore($unit)],
            'area_label' => ['required', 'string', 'max:50'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'installed_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'area_label.required' => 'Isi area, misalnya Aula atau Lobi.',
        ]);
    }
}
