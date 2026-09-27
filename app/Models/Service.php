<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    public const CATEGORIES = ['fnb' => 'Makanan & minuman', 'laundry' => 'Laundry', 'amenity' => 'Perlengkapan'];

    public const DEPARTMENTS = ['fnb' => 'F&B', 'housekeeping' => 'Housekeeping', 'front_office' => 'Front Office'];

    protected $fillable = ['code', 'name', 'description', 'category', 'menu_group', 'department', 'unit', 'price', 'photo', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Menu berlabel "Baru" bila ditambahkan dalam 1 bulan terakhir. */
    public function isNew(): bool
    {
        return $this->created_at !== null && $this->created_at->gte(now()->subMonth());
    }

    /**
     * Id menu F&B paling laris berdasarkan jumlah terjual (pesanan batal tidak dihitung).
     * Bila jumlahnya sama, menu yang dipesan di lebih banyak pesanan didahulukan. Di-cache 10 menit.
     *
     * @return list<int>
     */
    public static function terlarisIds(int $jumlah = 5): array
    {
        return Cache::remember("menu.terlaris.$jumlah", 600, fn () => DB::table('service_order_items as i')
            ->join('service_orders as o', 'o.id', '=', 'i.service_order_id')
            ->join('services as s', 's.id', '=', 'i.service_id')
            ->where('s.category', 'fnb')
            ->where('s.is_active', true)
            ->where('o.status', '!=', 'cancelled')
            ->groupBy('i.service_id')
            ->orderByRaw('SUM(i.quantity) DESC, COUNT(DISTINCT i.service_order_id) DESC, i.service_id')
            ->limit($jumlah)
            ->pluck('i.service_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /** Termasuk 5 menu paling laris (bertanda bintang). */
    public function isTerlaris(): bool
    {
        return in_array($this->id, self::terlarisIds(), true);
    }

    /** Foto yang diunggah admin, atau null bila belum ada. */
    public function fotoUrl(): ?string
    {
        return $this->photo && str_contains($this->photo, '/') && Storage::disk('public')->exists($this->photo)
            ? asset('storage/'.$this->photo)
            : null;
    }
}
