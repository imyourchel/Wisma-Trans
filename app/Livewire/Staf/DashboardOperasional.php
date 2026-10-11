<?php

namespace App\Livewire\Staf;

use App\Services\PiutangService;
use App\Services\RingkasanDashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Bagian Dashboard yang dipantau terus: Manager (Hari ini, Bulan ini, Piutang instansi) dan Front Office
 * (kedatangan, keberangkatan, terlambat, uang muka, refund). Dimuat ulang tiap 60 detik selama tab terlihat.
 * Angka diambil dari RingkasanDashboard (Laporan dan PiutangService), bukan dihitung di sini.
 */
class DashboardOperasional extends Component
{
    public function render(RingkasanDashboard $ringkasan, PiutangService $piutang): View
    {
        $user = Auth::user();

        return view('livewire.staf.dashboard-operasional', [
            'manager' => $user?->can('view-reports') ? $ringkasan->manager() : null,
            'piutang' => $user?->can('manage-receivables') ? $piutang->ringkasan() : null,
            'frontOffice' => $user?->can('manage-reservations') ? $ringkasan->frontOffice($user) : null,
        ]);
    }
}
