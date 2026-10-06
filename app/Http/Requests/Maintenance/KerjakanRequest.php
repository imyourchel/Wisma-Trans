<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

/** Manager menugaskan laporan baru ke staf internal atau vendor. */
class KerjakanRequest extends FormRequest
{
    use PengerjaRules;

    public function authorize(): bool
    {
        return $this->user()?->can('manage-maintenance') ?? false;
    }

    public function rules(): array
    {
        return $this->aturanPengerja();
    }

    public function messages(): array
    {
        return $this->pesanPengerja();
    }
}
