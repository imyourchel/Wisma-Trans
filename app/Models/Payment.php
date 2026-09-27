<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHODS = [
        'cash' => 'Tunai', 'bank_transfer' => 'Transfer bank', 'qris' => 'QRIS / dompet digital',
        'debit_card' => 'Kartu debit', 'credit_card' => 'Kartu kredit', 'virtual_account' => 'Virtual account',
        'retail_outlet' => 'Gerai ritel',
    ];

    protected $fillable = [
        'payment_code', 'invoice_id', 'billing_statement_id', 'transaction_type', 'payment_purpose', 'amount',
        'method', 'channel', 'status', 'paid_at', 'midtrans_order_id', 'midtrans_transaction_id',
        'gateway_response', 'received_by', 'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
