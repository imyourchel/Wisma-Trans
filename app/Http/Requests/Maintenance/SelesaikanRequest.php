<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Laporan yang sedang dikerjakan dinyatakan selesai, beserta biaya dan status kamar sesudahnya. */
class SelesaikanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-maintenance') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // "250.000" dari isian rupiah dibaca 250000
        $biaya = trim((string) $this->input('cost'));
        $this->merge(['cost' => $biaya === '' ? null : str_replace('.', '', $biaya)]);
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:1000'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'status_kamar' => ['nullable', Rule::in(['vacant_dirty', 'available'])],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution_note.required' => 'Tulis catatan perbaikan.',
            'resolution_note.max' => 'Maksimal 1000 karakter.',
            'cost.numeric' => 'Biaya harus berupa angka.',
            'cost.min' => 'Biaya tidak boleh negatif.',
            'cost.max' => 'Biaya terlalu besar.',
            'status_kamar.in' => 'Pilih status kamar setelah perbaikan.',
        ];
    }
}
