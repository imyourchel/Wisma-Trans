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

    /**
     * Simpan nilai pengaturan sebagai baris di tabel settings; baris yang belum ada dibuat dengan $type dan $description.
     * Cache pengaturan langsung dibuang supaya nilai baru terbaca di semua halaman.
     */
    public static function set(string $key, string $value, ?int $oleh = null, string $type = 'string', ?string $description = null): void
    {
        $s = static::find($key) ?? new static(['setting_key' => $key, 'type' => $type, 'description' => $description]);
        if (! $s->exists || $s->value !== $value) {
            $s->fill(['value' => $value, 'updated_by' => $oleh])->save();
        }
        Cache::forget('settings.all');
    }
}
