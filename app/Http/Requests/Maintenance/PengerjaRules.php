<?php

namespace App\Http\Requests\Maintenance;

use App\Models\User;
use App\Services\MaintenanceService;
use Illuminate\Validation\Rule;

/** Isian "dikerjakan oleh": staf internal (handled_by) atau vendor luar (vendor_name). */
trait PengerjaRules
{
    protected function aturanPengerja(): array
    {
        $staf = $this->input('pengerja') === 'staf';

        return [
            'pengerja' => ['required', Rule::in(['staf', 'vendor'])],
            'handled_by' => [$staf ? 'required' : 'nullable', 'integer', Rule::in(MaintenanceService::stafPengerja()->pluck('id')->all())],
            'vendor_name' => [$staf ? 'nullable' : 'required', 'string', 'max:100'],
        ];
    }

    protected function pesanPengerja(): array
    {
        return [
            'pengerja.*' => 'Pilih staf internal atau vendor luar.',
            'handled_by.required' => 'Pilih staf yang mengerjakan.',
            'handled_by.in' => 'Staf tidak ditemukan atau tidak aktif.',
            'vendor_name.required' => 'Isi nama vendor.',
            'vendor_name.max' => 'Maksimal 100 karakter.',
        ];
    }

    public function staf(): ?User
    {
        return $this->input('pengerja') === 'staf' ? User::find((int) $this->input('handled_by')) : null;
    }

    public function vendor(): ?string
    {
        return $this->input('pengerja') === 'vendor' ? trim((string) $this->input('vendor_name')) : null;
    }
}
