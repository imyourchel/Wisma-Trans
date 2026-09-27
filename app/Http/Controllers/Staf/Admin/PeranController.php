<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Role;
use App\Support\MenuStaf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Peran bersifat tetap karena kodenya dipakai aplikasi untuk mengatur akses.
 * Admin hanya dapat mengganti nama tampilannya.
 */
class PeranController extends MasterController
{
    public function index(): View
    {
        $roles = Role::withCount(['users' => fn ($q) => $q->where('is_active', true)])->get()
            ->sortBy(fn (Role $r) => array_search($r->code, [...Role::STAFF, Role::CUSTOMER]))->values();

        // Kelompok menu yang dilihat tiap peran, dibaca dari definisi sidebar
        $akses = $roles->mapWithKeys(fn (Role $r) => [$r->code => collect(MenuStaf::definisi())
            ->filter(fn ($grup) => collect($grup['anak'] ?? [$grup])->contains(fn ($item) => in_array($r->code, $item['peran'] ?? [])))
            ->pluck('label')->all()]);

        return view('staf.admin.peran', ['roles' => $roles, 'akses' => $akses]);
    }

    public function update(Request $request, Role $peran): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:50']]);
        $peran->update($data);

        return $this->selesai($request, "Nama peran {$peran->code} disimpan.");
    }
}
