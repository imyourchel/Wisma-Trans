<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Layanan dan menu: makanan-minuman, laundry, dan perlengkapan seperti extra bed.
 * Harga kosong berarti "sesuai ukuran" dan diisi staf di kasir.
 * Mengubah harga di sini tidak mengubah pesanan lama karena harga disalin saat transaksi.
 */
class LayananController extends MasterController
{
    public function index(): View
    {
        return view('staf.admin.layanan', [
            'layanan' => Service::orderBy('category')->orderByDesc('is_active')->orderBy('menu_group')->orderBy('name')->get(),
            'kelompok' => Service::whereNotNull('menu_group')->distinct()->orderBy('menu_group')->pluck('menu_group'),
            'satuan' => Service::distinct()->orderBy('unit')->pluck('unit'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request);
        $data['photo'] = $request->file('foto')?->store('layanan', 'public');
        Service::create($data + ['is_active' => true]);

        return $this->selesai($request, "{$data['name']} ditambahkan.");
    }

    public function update(Request $request, Service $layanan): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request, $layanan);

        if ($request->hasFile('foto') || $request->boolean('hapus_foto')) {
            if ($layanan->photo && str_contains($layanan->photo, '/')) {
                Storage::disk('public')->delete($layanan->photo);
            }
            $data['photo'] = $request->file('foto')?->store('layanan', 'public');
        }
        $layanan->update($data);

        return $this->selesai($request, "{$data['name']} disimpan.");
    }

    public function toggle(Service $layanan): RedirectResponse
    {
        return $this->balikAktif($layanan, $layanan->name);
    }

    private function validasi(Request $request, ?Service $layanan = null): array
    {
        $request->merge([
            'code' => Str::upper(trim((string) $request->input('code'))),
            'price' => filled($request->input('price')) ? $request->input('price') : null,
        ]);

        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('services', 'code')->ignore($layanan)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(Service::CATEGORIES))],
            'menu_group' => ['nullable', 'string', 'max:50'],
            'department' => ['required', Rule::in(array_keys(Service::DEPARTMENTS))],
            'unit' => ['required', 'string', 'max:20'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'foto.mimes' => 'Foto harus JPG, PNG, atau WEBP.',
            'foto.max' => 'Ukuran foto maksimal 4 MB.',
        ]);
        unset($data['foto']);

        return $data;
    }
}
