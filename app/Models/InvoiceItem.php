<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public const UPDATED_AT = null;

    public const JENIS = [
        'room' => 'Kamar', 'fnb' => 'Makanan & minuman', 'laundry' => 'Laundry', 'amenity' => 'Perlengkapan',
        'hall' => 'Sewa aula', 'late_checkout' => 'Terlambat check-out', 'other' => 'Lainnya',
    ];

    protected $fillable = [
        'invoice_id', 'item_type', 'description', 'service_date', 'quantity', 'unit_price',
        'discount_type', 'discount_value', 'discount_amount', 'subtotal',
    ];

    protected function casts(): array
    {
        return ['service_date' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
