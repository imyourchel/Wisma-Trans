<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Staf\Admin\MasterController;
use App\Models\FacilityUnit;
use App\Models\Hall;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
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

    private function butir(int $jumlah, string $teks, string $route): ?array
    {
        return $jumlah > 0 ? ['jumlah' => $jumlah, 'teks' => $teks, 'url' => route($route)] : null;
    }
}
