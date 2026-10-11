<?php

namespace App\Http\Controllers\Staf\Maintenance;

use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Riwayat perawatan yang sudah selesai, per kamar, unit, area, jenis, dan rentang tanggal, beserta total biayanya. */
class RiwayatController extends MaintenanceController
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-maintenance');
        $tanggal = fn ($v) => rescue(fn () => filled($v) ? Carbon::parse($v)->toDateString() : '', '', false);
        $saring = [
            'kamar' => (string) (int) $request->query('kamar') === '0' ? '' : (string) (int) $request->query('kamar'),
            'unit' => (string) (int) $request->query('unit') === '0' ? '' : (string) (int) $request->query('unit'),
            'area' => trim((string) $request->query('area')),
            'jenis' => in_array($request->query('jenis'), array_keys(MaintenanceRequest::JENIS), true) ? $request->query('jenis') : '',
            'dari' => $tanggal($request->query('dari')),
            'sampai' => $tanggal($request->query('sampai')),
            'jalur' => $request->query('jalur') === 'ya' ? 'ya' : '',
        ];

        $q = $this->query($saring);
        $kamar = $saring['kamar'] !== '' ? Room::find((int) $saring['kamar']) : null;
        $unit = $saring['unit'] !== '' ? FacilityUnit::with('facility', 'room')->find((int) $saring['unit']) : null;

        return view('staf.maintenance.riwayat', [
            'saring' => $saring,
            'riwayat' => (clone $q)->with('unit.room', 'unit.facility', 'room', 'schedule.facility', 'handler')
                ->orderByDesc('completed_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'totalBiaya' => (float) (clone $q)->sum('cost'),
            'jumlah' => (clone $q)->count(),
            'judul' => $unit ? 'Riwayat '.$unit->unit_label : ($kamar ? 'Riwayat Kamar '.$kamar->room_number : null),
            'daftarKamar' => Room::where('is_active', true)->orderBy('room_number')->pluck('room_number', 'id'),
            'daftarUnit' => FacilityUnit::orderBy('unit_label')->pluck('unit_label', 'id'),
            'daftarArea' => FacilityUnit::distinct()->orderBy('area_label')->pluck('area_label'),
        ]);
    }

    /** Laporan selesai sesuai saringan. Area mencakup unit di area itu dan jadwal rutin area itu. */
    public static function query(array $saring): Builder
    {
        return MaintenanceRequest::query()->where('status', 'completed')
            ->when($saring['jenis'], fn ($q, $j) => $q->where('maintenance_type', $j))
            ->when($saring['kamar'] !== '', fn ($q) => $q->where(fn ($q) => $q->where('room_id', (int) $saring['kamar'])
                ->orWhereHas('unit', fn ($u) => $u->where('room_id', (int) $saring['kamar']))))
            ->when($saring['unit'] !== '', fn ($q) => $q->where('facility_unit_id', (int) $saring['unit']))
            ->when($saring['area'] !== '', fn ($q) => $q->where(fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('area_label', $saring['area']))
                ->orWhereHas('schedule', fn ($s) => $s->where('area_label', $saring['area']))))
            ->when($saring['dari'] !== '', fn ($q) => $q->where('completed_at', '>=', $saring['dari'].' 00:00:00'))
            ->when($saring['sampai'] !== '', fn ($q) => $q->where('completed_at', '<=', $saring['sampai'].' 23:59:59'))
            ->when(($saring['jalur'] ?? '') === 'ya', fn ($q) => $q->jalurCepat());
    }
}
