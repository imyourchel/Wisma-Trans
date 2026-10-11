<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nilai satu kriteria untuk satu kamar: mentah, ternormalisasi, dan terbobot. */
class CleaningPriorityScore extends Model
{
    public $timestamps = false;

    protected $fillable = ['cleaning_priority_item_id', 'criteria_id', 'raw_value', 'normalized_value', 'weighted_value'];

    protected function casts(): array
    {
        return ['raw_value' => 'decimal:4', 'normalized_value' => 'decimal:8', 'weighted_value' => 'decimal:8'];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(CleaningCriterion::class, 'criteria_id');
    }
}
