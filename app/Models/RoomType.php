<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class RoomType extends Model
{
    protected $fillable = ['code', 'name', 'description', 'base_price', 'max_capacity', 'photos'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'photos' => 'array',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'room_type_facilities');
    }

    /**
     * URL foto yang berkasnya benar-benar ada, sesuai urutan galeri (foto pertama = utama).
     *
     * @return list<string>
     */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->filter(fn ($p) => is_string($p) && str_contains($p, '/') && Storage::disk('public')->exists($p))
            ->map(fn ($p) => asset('storage/'.$p))
            ->values()
            ->all();
    }

    /** Foto pertama = foto utama. Nama berkas data contoh (std-1.jpg) belum punya berkas, jadi memakai gambar pengganti. */
    protected function mainPhotoUrl(): Attribute
    {
        return Attribute::get(function () {
            $utama = $this->photos[0] ?? null;

            return $utama && str_contains($utama, '/') && Storage::disk('public')->exists($utama)
                ? asset('storage/'.$utama)
                : asset('ds/img/bangunan_depan.png');
        });
    }
}
