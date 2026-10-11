<?php

namespace App\Http\Requests\Maintenance;

use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Catat pelaksanaan satu jadwal perawatan rutin. */
class PelaksanaanRequest extends FormRequest
{
    use PengerjaRules;

    public function authorize(): bool
    {
        return $this->user()?->can('manage-maintenance') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $biaya = trim((string) $this->input('cost'));
        $this->merge(['cost' => $biaya === '' ? null : str_replace('.', '', $biaya)]);
    }

    public function rules(): array
    {
        return $this->aturanPengerja() + [
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'catatan' => ['nullable', 'string', 'max:1000', MaintenanceRequest::aturanBukanJalurCepat()],
        ];
    }

    public function messages(): array
    {
        return $this->pesanPengerja() + [
            'tanggal.required' => 'Isi tanggal perawatan.',
            'tanggal.date' => 'Tanggal tidak valid.',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh melewati hari ini.',
            'cost.numeric' => 'Biaya harus berupa angka.',
            'cost.min' => 'Biaya tidak boleh negatif.',
            'catatan.max' => 'Maksimal 1000 karakter.',
        ];
    }
}
