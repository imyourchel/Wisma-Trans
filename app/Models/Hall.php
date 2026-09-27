<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hall extends Model
{
    protected $fillable = ['name', 'capacity', 'description', 'photos', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'photos' => 'array'];
    }
}
