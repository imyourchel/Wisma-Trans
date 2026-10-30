<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah dan ubah kamar (UC-MK02). Kolom status sengaja tidak ada di aturan,
 * jadi walau dikirim tidak ikut tersimpan; status hanya diganti di Papan Status Kamar.
 */
class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-rooms') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['room_number' => strtoupper(trim((string) $this->input('room_number')))]);
    }

    public function rules(): array
    {
        return [
            'room_number' => ['required', 'alpha_num:ascii', 'max:10', Rule::unique('rooms', 'room_number')->ignore($this->route('kamar'))],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'floor' => ['required', 'integer', 'min:1', 'max:10'],
            'bed_type' => ['required', Rule::in(array_keys(Room::BED_TYPES))],
        ];
    }

    /**
     * Tipe kamar yang sedang dipesan atau ditempati tidak boleh diganti, supaya ketersediaan per tipe tetap benar.
     * Nomor, lantai, dan jenis kasur tetap boleh diubah.
     */
    public function after(): array
    {
        return [function (Validator $v) {
            $kamar = $this->route('kamar');
            if (! $kamar instanceof Room || $v->errors()->has('room_type_id') || (int) $this->input('room_type_id') === (int) $kamar->room_type_id) {
                return;
            }
            if ($pesan = $kamar->pemesananBerjalan(['booked', 'checked_in'])->first()) {
                $v->errors()->add('room_type_id', "Tipe tidak bisa diubah: kamar {$kamar->room_number} sudah dipesan untuk {$pesan->keterangan()}.");
            }
        }];
    }

    public function messages(): array
    {
        return [
            'room_number.alpha_num' => 'Nomor kamar hanya boleh huruf dan angka.',
            'room_number.unique' => 'Nomor kamar sudah dipakai.',
            'room_type_id.exists' => 'Tipe kamar tidak ditemukan.',
            'bed_type.in' => 'Pilih salah satu jenis kasur: '.implode(', ', Room::BED_TYPES).'.',
        ];
    }

    public function attributes(): array
    {
        return [
            'room_number' => 'nomor kamar',
            'room_type_id' => 'tipe kamar',
            'floor' => 'lantai',
            'bed_type' => 'jenis kasur',
        ];
    }
}
