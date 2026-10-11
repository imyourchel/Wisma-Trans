<?php

namespace App\Http\Controllers\Staf;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Staf\Admin\MasterController;
use App\Models\FacilityUnit;
use App\Models\Hall;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceSchedule;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\PembayaranMidtrans;
use App\Services\PesananMakananService;
use App\Support\MenuStaf;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('staf.dashboard', [
            'admin' => $user->hasRole(Role::ADMIN) ? $this->ringkasanAdmin() : null,
            'maintenance' => $user->can('manage-maintenance') ? $this->ringkasanMaintenance() : null,
            'housekeeping' => $user->can('manage-housekeeping') ? $this->ringkasanHousekeeping() : null,
            'tugasSaya' => $user->can('do-housekeeping-task') ? $this->tugasSaya($user) : null,
            'fnb' => $user->can('view-fnb') ? $this->ringkasanFnb($user) : null,
            // Manager (Hari ini, Bulan ini, Piutang) dan Front Office: komponen Livewire yang dimuat ulang tiap 60 detik
            'operasional' => $user->can('view-reports') || $user->can('manage-receivables') || $user->can('manage-reservations'),
            // Pintasan ke menu peran lain (selain Dashboard dan menu Admin sendiri)
            'pintasan' => collect(MenuStaf::untuk($user))->filter(fn ($g) => $g['anak'] && ! in_array($g['label'], ['Data Master', 'Pengguna dan Akses']))->values(),
        ]);
    }

    private function ringkasanAdmin(): array
    {
        $staf = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('code', Role::STAFF));
        $tipe = RoomType::all();
        $terakhir = Setting::orderByDesc('updated_at')->first();

        return [
            'kartu' => [
                ['label' => 'Staf aktif', 'nilai' => (clone $staf)->count(), 'url' => route('staf.pengguna.index')],
                ['label' => 'Kamar aktif', 'nilai' => Room::where('is_active', true)->count().' / '.Room::count(), 'url' => route('staf.kamar.index')],
                ['label' => 'Tipe kamar', 'nilai' => $tipe->count(), 'url' => route('staf.tipe-kamar.index')],
                ['label' => 'Layanan dan menu aktif', 'nilai' => Service::where('is_active', true)->count(), 'url' => route('staf.layanan.index')],
            ],
            'perPeran' => Role::whereIn('code', Role::STAFF)
                ->withCount(['users' => fn ($q) => $q->where('is_active', true)])->get()
                ->sortBy(fn ($r) => array_search($r->code, Role::STAFF))->values(),
            // Data master yang belum lengkap, supaya halaman tamu tampil rapi
            'lengkapi' => array_values(array_filter([
                $this->butir($tipe->filter(fn ($t) => ! collect(MasterController::daftarFoto($t->photos))->contains(fn ($f) => $f['url']))->count(),
                    'tipe kamar belum punya foto', 'staf.tipe-kamar.index'),
                $this->butir(Hall::where('is_active', true)->get()->filter(fn ($h) => ! collect(MasterController::daftarFoto($h->photos))->contains(fn ($f) => $f['url']))->count(),
                    'aula belum punya foto', 'staf.aula.index'),
                $this->butir(Service::where('is_active', true)->where('category', 'fnb')->whereNull('photo')->count(),
                    'menu aktif belum punya foto', 'staf.layanan.index'),
                $this->butir(FacilityUnit::where('is_active', true)->whereNull('installed_at')->count(),
                    'unit fasilitas belum punya tanggal pasang', 'staf.unit-fasilitas.index'),
            ])),
            'pengaturan' => $terakhir ? [
                'waktu' => $terakhir->updated_at,
                'oleh' => $terakhir->updated_by ? User::find($terakhir->updated_by)?->name : null,
            ] : null,
        ];
    }

    /** Kartu Maintenance untuk Manager: kerusakan belum dikerjakan, sedang dikerjakan, jalur cepat yang belum dilengkapi, dan perawatan terlambat. */
    private function ringkasanMaintenance(): array
    {
        $laporan = MaintenanceRequest::where('maintenance_type', 'corrective')->whereIn('status', ['reported', 'in_progress'])
            ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return [
            ['label' => 'Kerusakan belum dikerjakan', 'nilai' => (int) ($laporan['reported'] ?? 0), 'url' => route('staf.maintenance.index'), 'awas' => ($laporan['reported'] ?? 0) > 0],
            ['label' => 'Sedang dikerjakan', 'nilai' => (int) ($laporan['in_progress'] ?? 0), 'url' => route('staf.maintenance.index', ['tab' => 'in_progress']), 'awas' => false],
            ['label' => 'Jalur cepat belum dilengkapi', 'nilai' => $cepat = MaintenanceRequest::jalurCepat()->whereNull('cost')->count(),
                'url' => route('staf.maintenance.index', ['tab' => 'completed', 'jalur' => 'belum']), 'awas' => $cepat > 0],
            ['label' => 'Perawatan terlambat', 'nilai' => $lewat = MaintenanceSchedule::where('is_active', true)->whereDate('next_due_date', '<', today())->count(),
                'url' => route('staf.maintenance.jadwal'), 'awas' => $lewat > 0],
        ];
    }

    /** Kartu Housekeeping untuk Manager: kamar kotor, tugas belum dimulai, dan kamar kotor yang belum terbagi hari ini. */
    private function ringkasanHousekeeping(): array
    {
        $hari = today()->toDateString();
        $kotor = Room::active()->whereIn('status', [RoomStatus::VacantDirty->value, RoomStatus::OccupiedDirty->value]);
        $belumTerbagi = (clone $kotor)->whereDoesntHave('housekeepingTasks', fn ($q) => $q->whereDate('task_date', $hari)
            ->whereIn('status', HousekeepingTask::TERBUKA))->count();
        $belumMulai = HousekeepingTask::whereDate('task_date', $hari)->where('status', 'assigned')->count();

        return [
            ['label' => 'Kamar kotor belum dibersihkan', 'nilai' => $n = (clone $kotor)->count(), 'url' => route('staf.housekeeping.prioritas'), 'awas' => $n > 0],
            ['label' => 'Tugas belum dimulai', 'nilai' => $belumMulai, 'url' => route('staf.housekeeping.pembagian'), 'awas' => false],
            ['label' => 'Belum terbagi', 'nilai' => $belumTerbagi, 'url' => route('staf.housekeeping.pembagian'), 'awas' => $belumTerbagi > 0],
        ];
    }

    /** Kartu F&B untuk F&B dan Manager: pesanan di dapur, terlambat, menunggu pembayaran, dan menu habis. */
    private function ringkasanFnb(User $user): array
    {
        $dapur = PesananMakananService::diDapur()->with('invoice.payments')->get();
        $terlambat = $dapur->filter(fn ($o) => PesananMakananService::terlambat($o))->count();
        $menungguBayar = PesananMakananService::query()->where('status', 'pending')->where('payment_mode', 'pay_now')
            ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['paid', 'cancelled']))->where('order_date', '>=', now()->subDay())->count();
        $habis = Service::where('category', 'fnb')->where('is_active', true)->where('is_sold_out', true)->count();

        return [
            ['label' => 'Pesanan aktif', 'nilai' => $dapur->count(), 'url' => route('staf.fnb.papan'), 'awas' => false],
            ['label' => 'Terlambat', 'nilai' => $terlambat, 'url' => route('staf.fnb.papan'), 'awas' => $terlambat > 0],
            ['label' => 'Menunggu pembayaran', 'nilai' => $menungguBayar,
                'url' => $user->can('take-fnb-order') ? route('staf.fnb.kasir') : route('staf.fnb.papan'), 'awas' => false],
            ['label' => 'Menu habis', 'nilai' => $habis, 'url' => route('staf.fnb.ketersediaan', ['saring' => 'habis']), 'awas' => $habis > 0],
            // Peringatan: pengembalian dana yang masih menunggu (antrean di Keuangan > Pembayaran)
            ...($refund = PembayaranMidtrans::jumlahPerluRefund($user))
                ? [['label' => 'Perlu pengembalian dana', 'nilai' => $refund, 'url' => route('staf.keuangan.pembayaran', ['tab' => 'refund']), 'awas' => true]] : [],
        ];
    }

    /** Kartu Housekeeping: tugas saya hari ini yang selesai / semua. */
    private function tugasSaya(User $user): array
    {
        $tugas = HousekeepingTask::whereDate('task_date', today()->toDateString())->where('status', '!=', 'cancelled')
            ->whereHas('assignees', fn ($q) => $q->where('user_id', $user->id))->pluck('status');

        return [[
            'label' => 'Tugas saya hari ini',
            'nilai' => $tugas->filter(fn ($s) => in_array($s, ['completed', 'verified'], true))->count().' / '.$tugas->count(),
            'url' => route('staf.housekeeping.tugas-saya'),
            'awas' => false,
        ]];
    }

    private function butir(int $jumlah, string $teks, string $route): ?array
    {
        return $jumlah > 0 ? ['jumlah' => $jumlah, 'teks' => $teks, 'url' => route($route)] : null;
    }
}
