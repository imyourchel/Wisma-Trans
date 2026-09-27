<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id', 'item_type', 'description', 'service_date', 'quantity', 'unit_price',
        'discount_type', 'discount_value', 'discount_amount', 'subtotal',
    ];
}
