<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu kamar dalam batch prioritas: nilai preferensi SAW (V) dan peringkatnya. */
class CleaningPriorityItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['cleaning_priority_batch_id', 'room_id', 'preference_score', 'priority_rank'];

    protected function casts(): array
    {
        return ['preference_score' => 'decimal:8', 'priority_rank' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CleaningPriorityBatch::class, 'cleaning_priority_batch_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(CleaningPriorityScore::class);
    }
}
