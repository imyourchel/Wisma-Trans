<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Hall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AulaController extends MasterController
{
    public function index(): View
    {
        return view('staf.admin.aula', ['aula' => Hall::orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request);
        Hall::create($data + ['photos' => $this->susunFoto($request, [], 'aula'), 'is_active' => true]);

        return $this->selesai($request, "{$data['name']} ditambahkan.");
    }

    public function update(Request $request, Hall $aula): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request, $aula);
        $aula->update($data + ['photos' => $this->susunFoto($request, $aula->photos ?? [], 'aula')]);

        return $this->selesai($request, "{$data['name']} disimpan.");
    }

    public function toggle(Hall $aula): RedirectResponse
    {
        return $this->balikAktif($aula, $aula->name);
    }

    private function validasi(Request $request, ?Hall $aula = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('halls', 'name')->ignore($aula)],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'description' => ['nullable', 'string', 'max:2000'],
            ...$this->aturanFoto(),
        ], $this->pesanFoto());

        return collect($data)->only(['name', 'capacity', 'description'])->all();
    }
}
