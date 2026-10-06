<?php

namespace App\Http\Requests\Maintenance;

use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Laporan kerusakan dari staf operasional. unit berisi id unit fasilitas atau "lainnya". */
class LaporRequest extends FormRequest
{
    public const MAKS_FOTO = 3;

    public function authorize(): bool
    {
        return $this->user()?->can('report-damage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'deskripsi' => trim((string) $this->input('deskripsi')),
            'tidak_bisa_dipakai' => $this->boolean('tidak_bisa_dipakai'),
            'tetap_kirim' => $this->boolean('tetap_kirim'),
        ]);
    }

    /**
     * Kode laporan yang masih terbuka (baru atau sedang dikerjakan) per unit, untuk peringatan laporan ganda.
     *
     * @return array<int, list<string>> id unit => kode laporan
     */
    public static function laporanTerbukaPerUnit(): array
    {
        return MaintenanceRequest::whereNotNull('facility_unit_id')->whereIn('status', ['reported', 'in_progress'])
            ->orderBy('reported_at')->get(['facility_unit_id', 'request_code'])
            ->groupBy('facility_unit_id')->map(fn ($g) => $g->pluck('request_code')->all())->all();
    }

    /** Area umum yang punya unit fasilitas aktif. */
    public static function daftarArea(): array
    {
        return FacilityUnit::whereNull('room_id')->where('is_active', true)->distinct()->orderBy('area_label')->pluck('area_label')->all();
    }

    public function rules(): array
    {
        $kamar = $this->input('lokasi') === 'kamar';

        return [
            'lokasi' => ['required', Rule::in(['kamar', 'area'])],
            'room_id' => [$kamar ? 'required' : 'nullable', 'integer', Rule::exists('rooms', 'id')->where('is_active', true)],
            'area_label' => [$kamar ? 'nullable' : 'required', 'string', Rule::in(self::daftarArea())],
            'unit' => ['required', 'string'],
            'deskripsi' => ['required', 'string', 'max:1000'],
            'priority' => ['required', Rule::in(array_keys(MaintenanceRequest::PRIORITAS))],
            'tidak_bisa_dipakai' => ['boolean'],
            'tetap_kirim' => ['boolean'],
            'foto' => ['nullable', 'array', 'max:'.self::MAKS_FOTO],
            'foto.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * Unit yang dipilih harus berada di kamar atau area yang dipilih.
     * Unit yang masih punya laporan terbuka perlu konfirmasi (tetap_kirim) supaya tidak terjadi laporan ganda.
     */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty() || $this->input('unit') === 'lainnya') {
                return;
            }
            $unit = $this->unitTerpilih();
            $cocok = $unit && $unit->is_active && ($this->input('lokasi') === 'kamar'
                ? (int) $unit->room_id === (int) $this->input('room_id')
                : $unit->room_id === null && $unit->area_label === $this->input('area_label'));
            if (! $cocok) {
                $v->errors()->add('unit', 'Pilih unit fasilitas di lokasi ini, atau pilih Lainnya.');

                return;
            }
            $terbuka = self::laporanTerbukaPerUnit()[$unit->id] ?? [];
            if ($terbuka && ! $this->boolean('tetap_kirim')) {
                $v->errors()->add('tetap_kirim', "{$unit->unit_label} sudah dilaporkan (".implode(', ', $terbuka).') dan belum selesai. Centang "Tetap kirim" bila kerusakannya berbeda.');
            }
        }];
    }

    public function unitTerpilih(): ?FacilityUnit
    {
        return $this->input('unit') === 'lainnya' ? null : FacilityUnit::with('room')->find((int) $this->input('unit'));
    }

    public function messages(): array
    {
        return [
            'lokasi.required' => 'Pilih kamar atau area umum.',
            'room_id.required' => 'Pilih kamar.',
            'room_id.exists' => 'Kamar tidak ditemukan.',
            'area_label.required' => 'Pilih area.',
            'area_label.in' => 'Area tidak ditemukan.',
            'unit.required' => 'Pilih unit fasilitas, atau Lainnya.',
            'deskripsi.required' => 'Jelaskan kerusakannya.',
            'deskripsi.max' => 'Maksimal 1000 karakter.',
            'priority.*' => 'Pilih prioritas.',
            'foto.max' => 'Maksimal '.self::MAKS_FOTO.' foto.',
            'foto.*.image' => 'Berkas harus berupa foto.',
            'foto.*.mimes' => 'Foto harus JPG, PNG, atau WebP.',
            'foto.*.max' => 'Ukuran tiap foto maksimal 2 MB.',
        ];
    }
}
