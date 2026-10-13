<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kehadiran dan batas beban staf Housekeeping pada satu tanggal. Tanpa baris = hadir dengan batas otomatis.
 * Tugas rangkap tidak disimpan di sini: dibaca dari peran staf (user_roles), lihat BatasBebanStaf.
 */
class StaffAvailability extends Model
{
    protected $fillable = ['user_id', 'work_date', 'is_available', 'max_workload', 'note'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'is_available' => 'boolean', 'max_workload' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
