<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Hall extends Model
{
    protected $fillable = ['name', 'capacity', 'description', 'photos', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'photos' => 'array'];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class);
    }

    /** URL foto yang berkasnya benar-benar ada, sesuai urutan galeri. Nama berkas data contoh belum punya berkas. */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->filter(fn ($p) => is_string($p) && str_contains($p, '/') && Storage::disk('public')->exists($p))
            ->map(fn ($p) => asset('storage/'.$p))
            ->values()
            ->all();
    }

    public function fotoUtama(): string
    {
        return $this->photoUrls()[0] ?? asset('ds/img/bangunan_depan.png');
    }
}
