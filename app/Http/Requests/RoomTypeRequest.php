<?php

namespace App\Http\Requests;

use App\Http\Controllers\Staf\Admin\MasterController;
use App\Models\RoomType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Tambah dan ubah tipe kamar (UC-MK01). Foto tidak dibatasi jumlah maupun ukurannya. */
class RoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-rooms') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => Str::upper(trim((string) $this->input('code')))]);
    }

    public function rules(): array
    {
        /** @var RoomType|null $tipe */
        $tipe = $this->route('tipeKamar');

        return [
            'code' => ['required', 'regex:/^[A-Z0-9]+$/', 'max:20', Rule::unique('room_types', 'code')->ignore($tipe)],
            'name' => ['required', 'string', 'max:50', Rule::unique('room_types', 'name')->ignore($tipe)],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:10'],
            'fasilitas' => ['nullable', 'array'],
            'fasilitas.*' => ['integer', Rule::exists('facilities', 'id')->where('category', 'room')],
            ...MasterController::aturanGaleri(null, null),
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya boleh huruf dan angka, tanpa spasi.',
            'code.unique' => 'Kode tipe kamar sudah dipakai.',
            'name.unique' => 'Nama tipe kamar sudah dipakai.',
            'base_price.max' => 'Harga dasar maksimal Rp99.999.999.',
            'fasilitas.*.exists' => 'Pilih fasilitas kategori kamar.',
            ...MasterController::pesanGaleri(null, null),
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama',
            'description' => 'deskripsi',
            'base_price' => 'harga dasar',
            'max_capacity' => 'kapasitas',
        ];
    }

    /** Kolom yang disimpan ke room_types (tanpa fasilitas dan foto). */
    public function dataTipe(): array
    {
        return collect($this->validated())->only(['code', 'name', 'description', 'base_price', 'max_capacity'])->all();
    }
}
