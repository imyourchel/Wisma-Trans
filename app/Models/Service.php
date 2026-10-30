<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    public const CATEGORIES = ['fnb' => 'Makanan & minuman', 'laundry' => 'Laundry', 'amenity' => 'Perlengkapan'];

    public const DEPARTMENTS = ['fnb' => 'F&B', 'housekeeping' => 'Housekeeping', 'front_office' => 'Front Office'];

    /** Tambahan pemesanan kamar (App\Services\Reservasi\TambahanKamar): harga dan keterangan dibaca dari baris layanan ini. */
    public const EXTRA_BED = 'EXB01';

    public const SARAPAN_TAMBAHAN = 'SRP01';

    /**
     * Layanan F&B yang hanya dijual sebagai tambahan pemesanan kamar, bukan menu kafe: tidak tampil di Resto, Kasir,
     * Ketersediaan Menu, dan Papan Pesanan, dan ditolak bila dipesan lewat Resto atau Kasir. Kategorinya tetap fnb,
     * jadi pendapatannya tetap tercatat sebagai F&B. Admin tetap mengelolanya di Data Master.
     */
    public const KODE_TAMBAHAN_KAMAR = [self::SARAPAN_TAMBAHAN];

    protected $fillable = ['code', 'name', 'description', 'category', 'menu_group', 'department', 'unit', 'price', 'photo', 'is_active', 'is_sold_out'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
        ];
    }

    /**
     * Kolom is_sold_out ditambahkan lewat SQL terpisah. Selama belum dijalankan di sebuah basis data,
     * penanda habis tidak dipakai (semua menu dianggap tersedia) agar halaman tidak galat.
     */
    public static function adaPenandaHabis(): bool
    {
        static $ada = null;

        return $ada ??= Schema::hasColumn('services', 'is_sold_out');
    }

    /** Menu kafe: F&B aktif selain tambahan pemesanan kamar. */
    public function scopeMenuKafe(Builder $q): void
    {
        $q->where('category', 'fnb')->where('is_active', true)->whereNotIn('code', self::KODE_TAMBAHAN_KAMAR);
    }

    /** Sedang ditandai habis oleh F&B. */
    public function habis(): bool
    {
        return (bool) ($this->is_sold_out ?? false);
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
            ->whereNotIn('s.code', self::KODE_TAMBAHAN_KAMAR)
            ->whereNull('o.service_start_date')
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
