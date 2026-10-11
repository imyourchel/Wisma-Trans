<?php

namespace App\Http\Controllers\Staf\Maintenance;

use App\Http\Requests\Maintenance\LaporRequest;
use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Services\MaintenanceService;
use App\Services\NotifikasiMaintenance;
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

    public function store(LaporRequest $request, MaintenanceService $layanan, NotifikasiMaintenance $notifikasi): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $kamar = $data['lokasi'] === 'kamar' ? Room::findOrFail((int) $data['room_id']) : null;
        $foto = collect($request->file('foto', []))->map(fn ($f) => $f->store('maintenance', 'public'))->all();

        [$laporan, $peringatan] = $layanan->lapor($request->user(), $request->unitTerpilih(), $kamar,
            $kamar ? null : $data['area_label'], $data['deskripsi'], $data['priority'],
            $kamar !== null && $data['tidak_bisa_dipakai'], $foto);

        // Setelah tersimpan: Manager (laporan penting) dan FO (tamu perlu pindah kamar) diberi tahu lewat WhatsApp dan email
        if ($pindah = $notifikasi->laporanBaru($laporan, $request->user())) {
            $peringatan = trim($peringatan.' '.$pindah);
        }

        return $this->selesai($request, "Laporan {$laporan->request_code} terkirim ke Manager.", route('staf.maintenance.saya', ['tab' => 'saya']), $peringatan);
    }

    /**
     * Laporan Kerusakan, hanya untuk dibaca. Tab "Belum selesai": semua kerusakan terbuka dari siapa pun, supaya staf tahu
     * kamar atau area mana yang sedang rusak. Tab "Laporan saya": laporan yang saya kirim, semua status.
     * Biaya, nama vendor, dan catatan penyelesaian tidak ditampilkan; penanganan tetap di Penanganan (Manager).
     */
    public function saya(Request $request): View
    {
        Gate::authorize('report-damage');
        $saya = $request->user();
        $tab = $request->query('tab') === 'saya' ? 'saya' : 'terbuka';
        $saring = [
            'lokasi' => in_array($request->query('lokasi'), ['kamar', 'area'], true) ? $request->query('lokasi') : '',
            'cari' => trim((string) $request->query('cari')),
            'menahan' => $request->boolean('menahan') && MaintenanceRequest::adaKolomMenahan(),
        ];

        $dasar = MaintenanceRequest::query()->where('maintenance_type', 'corrective');
        $terbuka = fn () => (clone $dasar)->whereIn('status', ['reported', 'in_progress']);
        $milikSaya = fn () => (clone $dasar)->where('reported_by', $saya->id);

        $laporan = ($tab === 'saya'
            ? $milikSaya()->orderByDesc('reported_at')->orderByDesc('id')
            : $terbuka()
                ->when($saring['lokasi'] === 'kamar', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('room_id')->orWhereHas('unit', fn ($u) => $u->whereNotNull('room_id'))))
                ->when($saring['lokasi'] === 'area', fn ($q) => $q->whereNull('room_id')->where(fn ($q) => $q->whereNull('facility_unit_id')->orWhereHas('unit', fn ($u) => $u->whereNull('room_id'))))
                ->when($saring['cari'], fn ($q, $kata) => $q->where(fn ($q) => $q->where('request_code', 'like', "%{$kata}%")
                    ->orWhereHas('room', fn ($r) => $r->where('room_number', $kata))->orWhereHas('unit.room', fn ($r) => $r->where('room_number', $kata))))
                ->when($saring['menahan'], fn ($q) => $q->where('blocks_room', true))
                ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")->orderBy('reported_at')->orderBy('id'))
            ->with('unit.room', 'room', 'handler', 'reporter')->paginate(25)->withQueryString();

        // Detail hanya-baca: laporan terbuka siapa pun, atau laporan milik sendiri
        $lihat = $request->integer('lihat') ?: null;
        $detail = $lihat ? (clone $dasar)->with('unit.room', 'room', 'handler', 'reporter')->whereKey($lihat)
            ->where(fn ($q) => $q->whereIn('status', ['reported', 'in_progress'])->orWhere('reported_by', $saya->id))->first() : null;

        return view('staf.maintenance.saya', [
            'tab' => $tab,
            'saring' => $saring,
            'laporan' => $laporan,
            'jumlah' => ['terbuka' => $terbuka()->count(), 'saya' => $milikSaya()->count()],
            'detail' => $detail,
            'bolehTangani' => $saya->can('manage-maintenance'),
        ]);
    }
}
