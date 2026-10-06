<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    public const CATEGORIES = ['room' => 'Kamar', 'public' => 'Umum'];

    protected $fillable = ['name', 'category', 'is_maintainable'];

    protected function casts(): array
    {
        return ['is_maintainable' => 'boolean'];
    }

    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class, 'room_type_facilities');
    }

    public function units(): HasMany
    {
        return $this->hasMany(FacilityUnit::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }
}
