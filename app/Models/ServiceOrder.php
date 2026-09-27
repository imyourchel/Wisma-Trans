<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Pesanan layanan (makanan, laundry). Tamu luar memesan makanan lewat halaman Resto. */
class ServiceOrder extends Model
{
    public const SERVING = ['take_away' => 'Ambil di tempat', 'dine_in' => 'Makan di tempat', 'room_service' => 'Antar ke kamar'];

    protected $fillable = [
        'order_code', 'access_token', 'order_type', 'customer_id', 'reservation_room_id', 'serving_type',
        'invoice_id', 'payment_mode', 'order_date', 'status', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return ['order_date' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Kode untuk URL: ORD/2026/10/0011 menjadi ORD-2026-10-0011. */
    public function kodeUrl(): string
    {
        return str_replace('/', '-', $this->order_code);
    }

    public static function dariKodeUrl(string $kode): ?self
    {
        return static::where('order_code', str_replace('-', '/', $kode))->first();
    }

    public function sudahDibayar(): bool
    {
        return $this->invoice?->status === 'paid';
    }

    /** Alamat status pesanan yang dibagikan kepada tamu (berisi token akses). */
    public function urlStatus(): string
    {
        return route('pesanan.status', ['kode' => $this->kodeUrl(), 't' => $this->access_token]);
    }

    public static function tokenBaru(): string
    {
        return Str::random(40);
    }
}
