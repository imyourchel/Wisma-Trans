<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Fasilitas tidak punya status aktif di basis data, jadi hanya dapat ditambah dan diubah. */
class FasilitasController extends MasterController
{
    public function index(): View
    {
        $fasilitas = Facility::orderBy('category')->orderBy('name')->get();
        $unit = DB::table('facility_units')->where('is_active', true)
            ->selectRaw('facility_id, count(*) as jumlah')->groupBy('facility_id')->pluck('jumlah', 'facility_id');

        return view('staf.admin.fasilitas', ['fasilitas' => $fasilitas, 'jumlahUnit' => $unit]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request);
        Facility::create($data);

        return $this->selesai($request, "Fasilitas {$data['name']} ditambahkan.");
    }

    public function update(Request $request, Facility $fasilitas): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request, $fasilitas);
        $fasilitas->update($data);

        return $this->selesai($request, "Fasilitas {$data['name']} disimpan.");
    }

    private function validasi(Request $request, ?Facility $fasilitas = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('facilities', 'name')->ignore($fasilitas)],
            'category' => ['required', Rule::in(array_keys(Facility::CATEGORIES))],
            'is_maintainable' => ['required', 'boolean'],
        ]);

        return ['is_maintainable' => (bool) $data['is_maintainable']] + $data;
    }
}
