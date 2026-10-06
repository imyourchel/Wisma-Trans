<?php

namespace App\Http\Requests\Maintenance;

use App\Models\FacilityUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tambah dan ubah jadwal perawatan rutin. Kombinasi fasilitas + area harus unik. */
class JadwalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-maintenance') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vendor_name' => filled($this->input('vendor_name')) ? trim((string) $this->input('vendor_name')) : null,
            'note' => filled($this->input('note')) ? trim((string) $this->input('note')) : null,
        ]);
    }

    /** Area yang punya unit fasilitas (kamar per lantai maupun area umum). */
    public static function daftarArea(): array
    {
        return FacilityUnit::distinct()->orderBy('area_label')->pluck('area_label')->all();
    }

    public function rules(): array
    {
        $jadwal = $this->route('jadwal');

        return [
            'facility_id' => ['required', 'integer', Rule::exists('facilities', 'id')->where('is_maintainable', true),
                Rule::unique('maintenance_schedules', 'facility_id')->where('area_label', (string) $this->input('area_label'))->ignore($jadwal)],
            'area_label' => ['required', 'string', Rule::in(self::daftarArea())],
            'interval_months' => ['required', 'integer', 'min:1', 'max:24'],
            'next_due_date' => ['required', 'date'],
            'vendor_name' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'facility_id.required' => 'Pilih fasilitas.',
            'facility_id.exists' => 'Fasilitas ini tidak dijadwalkan perawatannya.',
            'facility_id.unique' => 'Jadwal untuk fasilitas dan area ini sudah ada.',
            'area_label.required' => 'Pilih area.',
            'area_label.in' => 'Area tidak ditemukan.',
            'interval_months.*' => 'Interval 1 sampai 24 bulan.',
            'next_due_date.required' => 'Isi tanggal perawatan.',
            'next_due_date.date' => 'Tanggal tidak valid.',
            'vendor_name.max' => 'Maksimal 100 karakter.',
            'note.max' => 'Maksimal 255 karakter.',
        ];
    }
}
