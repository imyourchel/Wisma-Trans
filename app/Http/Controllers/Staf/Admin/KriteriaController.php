<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\CleaningCriterion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Kriteria prioritas pembersihan. Bobotnya ditetapkan Manager di menu Bobot Kriteria. */
class KriteriaController extends MasterController
{
    public function index(): View
    {
        return view('staf.admin.kriteria', ['kriteria' => CleaningCriterion::orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request);
        CleaningCriterion::create($data + ['is_active' => true]);

        return $this->selesai($request, "Kriteria {$data['code']} ditambahkan.");
    }

    public function update(Request $request, CleaningCriterion $kriteria): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request, $kriteria);
        $kriteria->update($data);

        return $this->selesai($request, "Kriteria {$data['code']} disimpan.");
    }

    public function toggle(CleaningCriterion $kriteria): RedirectResponse
    {
        return $this->balikAktif($kriteria, 'Kriteria '.$kriteria->code);
    }

    private function validasi(Request $request, ?CleaningCriterion $kriteria = null): array
    {
        $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'alpha_dash', 'max:10', Rule::unique('cleaning_criteria', 'code')->ignore($kriteria)],
            'name' => ['required', 'string', 'max:80'],
            'attribute_type' => ['required', Rule::in(array_keys(CleaningCriterion::TYPES))],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
