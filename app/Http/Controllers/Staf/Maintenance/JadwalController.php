<?php

namespace App\Http\Controllers\Staf\Maintenance;

use App\Http\Requests\Maintenance\JadwalRequest;
use App\Http\Requests\Maintenance\PelaksanaanRequest;
use App\Models\Facility;
use App\Models\FacilityUnit;
use App\Models\MaintenanceSchedule;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Jadwal perawatan rutin (Manager): daftar dengan penanda terlambat, tambah/ubah, nonaktifkan, catat perawatan. */
class JadwalController extends MaintenanceController
{
    public function index(): View
    {
        Gate::authorize('manage-maintenance');
        // Jadwal aktif lebih dulu; di antaranya yang perawatan berikutnya paling awal (yang terlambat otomatis di atas)
        $jadwal = MaintenanceSchedule::with('facility')->orderByDesc('is_active')->orderBy('next_due_date')->orderBy('id')->get();
        $jumlahUnit = FacilityUnit::where('is_active', true)->selectRaw('facility_id, area_label, COUNT(*) n')
            ->groupBy('facility_id', 'area_label')->get()->mapWithKeys(fn ($r) => [$r->facility_id.'|'.$r->area_label => (int) $r->n]);

        return view('staf.maintenance.jadwal', [
            'jadwal' => $jadwal,
            'jumlahUnit' => $jumlahUnit,
            'fasilitas' => Facility::where('is_maintainable', true)->orderBy('name')->pluck('name', 'id'),
            'area' => JadwalRequest::daftarArea(),
            'staf' => MaintenanceService::stafPengerja(),
        ]);
    }

    public function store(JadwalRequest $request): JsonResponse|RedirectResponse
    {
        $jadwal = MaintenanceSchedule::create($request->validated() + ['is_active' => true]);

        return $this->selesai($request, 'Jadwal '.$jadwal->facility->name.' '.$jadwal->area_label.' ditambahkan.');
    }

    public function update(JadwalRequest $request, MaintenanceSchedule $jadwal): JsonResponse|RedirectResponse
    {
        $jadwal->update($request->validated());

        return $this->selesai($request, 'Jadwal '.$jadwal->facility->name.' '.$jadwal->area_label.' disimpan.');
    }

    /** Jadwal tidak dihapus, hanya dinonaktifkan, supaya riwayat pelaksanaannya tetap utuh. */
    public function toggle(MaintenanceSchedule $jadwal): RedirectResponse
    {
        Gate::authorize('manage-maintenance');
        $jadwal->update(['is_active' => ! $jadwal->is_active]);
        $nama = 'Jadwal '.$jadwal->facility->name.' '.$jadwal->area_label;

        return back()->with('status', $jadwal->is_active ? "{$nama} diaktifkan." : "{$nama} dinonaktifkan.");
    }

    public function laksanakan(PelaksanaanRequest $request, MaintenanceSchedule $jadwal, MaintenanceService $layanan): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $laporan = $layanan->catatPelaksanaan($jadwal, Carbon::parse($data['tanggal']), $request->staf(), $request->vendor(),
            isset($data['cost']) ? (float) $data['cost'] : null, $data['catatan'] ?? null, $request->user());

        return $this->selesai($request, 'Perawatan dicatat. Perawatan berikutnya: '.$jadwal->fresh()->next_due_date->translatedFormat('j M Y').'.');
    }
}
