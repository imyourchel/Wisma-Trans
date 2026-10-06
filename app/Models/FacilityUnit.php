<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu unit fisik fasilitas, misalnya AC di kamar 101, yang dijadwalkan perawatannya. */
class FacilityUnit extends Model
{
    protected $fillable = ['facility_id', 'room_id', 'area_label', 'unit_label', 'installed_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'installed_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }
}
