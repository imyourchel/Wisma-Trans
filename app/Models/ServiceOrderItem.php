<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Harga disalin saat memesan; perubahan harga menu tidak mengubah pesanan lama. */
class ServiceOrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['service_order_id', 'service_id', 'quantity', 'unit_price', 'subtotal', 'note'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
