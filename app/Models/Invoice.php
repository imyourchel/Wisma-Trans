<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** Jumlah yang sudah diterima: pembayaran berhasil dikurangi pengembalian dana. */
    public function terbayar(): float
    {
        $sukses = $this->payments()->where('status', 'success');

        return (float) (clone $sukses)->where('transaction_type', 'payment')->sum('amount')
            - (float) (clone $sukses)->where('transaction_type', 'refund')->sum('amount');
    }

    public function sisa(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
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
