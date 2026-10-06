<?php

namespace App\Http\Controllers\Staf\Maintenance;

use App\Enums\RoomStatus;
use App\Http\Requests\Maintenance\BatalkanRequest;
use App\Http\Requests\Maintenance\KerjakanRequest;
use App\Http\Requests\Maintenance\SelesaikanRequest;
use App\Models\MaintenanceRequest;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Penanganan laporan kerusakan oleh Manager: kerjakan, selesaikan, atau batalkan. */
class PenangananController extends MaintenanceController
{
    public const TAB = ['reported' => 'Baru dilaporkan', 'in_progress' => 'Sedang dikerjakan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    public function index(Request $request, MaintenanceService $layanan): View
    {
        Gate::authorize('manage-maintenance');
        $tab = array_key_exists((string) $request->query('tab'), self::TAB) ? (string) $request->query('tab') : 'reported';
        $saring = [
            'prioritas' => in_array($request->query('prioritas'), array_keys(MaintenanceRequest::PRIORITAS), true) ? $request->query('prioritas') : '',
            'lokasi' => in_array($request->query('lokasi'), ['kamar', 'area'], true) ? $request->query('lokasi') : '',
            'cari' => trim((string) $request->query('cari')),
        ];

        $dasar = MaintenanceRequest::query()->where('maintenance_type', 'corrective')
            ->when($saring['prioritas'], fn ($q, $p) => $q->where('priority', $p))
            ->when($saring['lokasi'] === 'kamar', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('room_id')->orWhereHas('unit', fn ($u) => $u->whereNotNull('room_id'))))
            ->when($saring['lokasi'] === 'area', fn ($q) => $q->whereNull('room_id')->where(fn ($q) => $q->whereNull('facility_unit_id')->orWhereHas('unit', fn ($u) => $u->whereNull('room_id'))))
            ->when($saring['cari'] !== '', fn ($q) => $q->where('request_code', 'like', '%'.$saring['cari'].'%'));

        $laporan = (clone $dasar)->with('unit.room', 'unit.facility', 'room', 'reporter', 'handler')->where('status', $tab)
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy($tab === 'reported' ? 'reported_at' : 'updated_at', in_array($tab, ['reported', 'in_progress'], true) ? 'asc' : 'desc')
            ->paginate(25)->withQueryString();

        return view('staf.maintenance.penanganan', [
            'tab' => $tab,
            'saring' => $saring,
            'jumlah' => (clone $dasar)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'laporan' => $laporan,
            // Laporan lain yang masih terbuka di kamar yang sama: yang menahan kamar tetap Perbaikan, dan yang hanya informasi
            'lainTerbuka' => $laporan->getCollection()->filter->terbuka()->mapWithKeys(fn ($l) => [$l->id => [
                'menahan' => $layanan->laporanTerbukaLain($l, menahan: true),
                'info' => $layanan->laporanTerbukaLain($l, menahan: false),
            ]]),
            'staf' => MaintenanceService::stafPengerja(),
        ]);
    }

    public function kerjakan(KerjakanRequest $request, MaintenanceRequest $laporan, MaintenanceService $layanan): JsonResponse|RedirectResponse
    {
        $layanan->kerjakan($laporan, $request->staf(), $request->vendor());

        return $this->selesai($request, "{$laporan->request_code} mulai dikerjakan.");
    }

    public function selesaikan(SelesaikanRequest $request, MaintenanceRequest $laporan, MaintenanceService $layanan): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $peringatan = $layanan->selesaikan($laporan, $data['resolution_note'], isset($data['cost']) ? (float) $data['cost'] : null,
            $data['status_kamar'] ?? null, $request->user());
        $kamar = $laporan->kamar()?->fresh();
        $info = ! $peringatan && $kamar && in_array($kamar->status, [RoomStatus::VacantDirty, RoomStatus::Available], true) && ($data['status_kamar'] ?? null)
            ? " Kamar {$kamar->room_number} sekarang {$kamar->status->label()}." : '';

        return $this->selesai($request, "{$laporan->request_code} selesai.".$info, null, $peringatan);
    }

    public function batalkan(BatalkanRequest $request, MaintenanceRequest $laporan, MaintenanceService $layanan): JsonResponse|RedirectResponse
    {
        $peringatan = $layanan->batalkan($laporan, $request->validated('alasan'), $request->user());

        return $this->selesai($request, "{$laporan->request_code} dibatalkan.", null, $peringatan);
    }
}
