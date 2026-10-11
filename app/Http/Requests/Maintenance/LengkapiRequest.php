<?php

namespace App\Http\Requests\Maintenance;

use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Manager melengkapi laporan jalur cepat: pengerja, biaya, dan catatan tambahan. Laporan lain tidak bisa diedit. */
class LengkapiRequest extends FormRequest
{
    use PengerjaRules;

    public function authorize(): bool
    {
        $laporan = $this->route('laporan');

        return ($this->user()?->can('manage-maintenance') ?? false)
            && $laporan instanceof MaintenanceRequest && $laporan->isJalurCepat();
    }

    protected function prepareForValidation(): void
    {
        // "250.000" dari isian rupiah dibaca 250000
        $biaya = trim((string) $this->input('cost'));
        $this->merge(['cost' => $biaya === '' ? null : str_replace('.', '', $biaya)]);
    }

    public function rules(): array
    {
        return $this->aturanPengerja() + [
            // Wajib: laporan yang sudah dilengkapi selalu keluar dari kartu "Jalur cepat belum dilengkapi" (whereNull('cost'))
            'cost' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return $this->pesanPengerja() + [
            'cost.required' => 'Isi biaya; tulis 0 bila tanpa biaya.',
            'cost.numeric' => 'Biaya harus berupa angka.',
            'cost.min' => 'Biaya tidak boleh negatif.',
            'cost.max' => 'Biaya terlalu besar.',
            'catatan.max' => 'Maksimal 1000 karakter.',
        ];
    }
}
