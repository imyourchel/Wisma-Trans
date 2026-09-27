<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'invoice_type', 'customer_id', 'reservation_id', 'institution_id', 'billing_statement_id',
        'payment_term', 'issue_date', 'period_start', 'period_end', 'due_date', 'subtotal', 'discount_type',
        'discount_value', 'discount_amount', 'total_amount', 'paid_amount', 'status', 'issued_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
