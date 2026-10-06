<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class BatalkanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-maintenance') ?? false;
    }

    public function rules(): array
    {
        return ['alasan' => ['required', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return ['alasan.required' => 'Isi alasan pembatalan.', 'alasan.max' => 'Maksimal 500 karakter.'];
    }
}
