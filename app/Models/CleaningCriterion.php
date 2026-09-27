<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kriteria penentuan prioritas pembersihan kamar (AHP). */
class CleaningCriterion extends Model
{
    public const TYPES = ['benefit' => 'Benefit', 'cost' => 'Cost'];

    protected $table = 'cleaning_criteria';

    protected $fillable = ['code', 'name', 'attribute_type', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
