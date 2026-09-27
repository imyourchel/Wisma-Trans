<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'setting_key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $fillable = ['setting_key', 'value', 'type', 'description', 'updated_by'];

    /** Nilai pengaturan operasional; di-cache sebentar karena dibaca di tiap halaman. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('settings.all', 300, fn () => static::query()->pluck('value', 'setting_key')->all());

        return $all[$key] ?? $default;
    }
}
