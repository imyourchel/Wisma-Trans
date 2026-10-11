<?php

namespace App\Models;

use App\Services\Dashboard\RingkasanDashboard;
use Illuminate\Database\Eloquent\Builder;
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

    protected static function booted(): void
    {
        // Pembayaran dicatat (tunai, transfer, atau Midtrans): angka "Bulan ini" di Dashboard dihitung ulang
        static::saved(fn () => RingkasanDashboard::lupakanBulan());
    }

    public const TUJUAN = [
        'down_payment' => 'Uang muka', 'settlement' => 'Pelunasan', 'service' => 'Layanan', 'hall' => 'Aula', 'other' => 'Lainnya',
    ];

    public const STATUS = [
        'pending' => 'Menunggu', 'success' => 'Berhasil', 'failed' => 'Gagal', 'expired' => 'Kedaluwarsa',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function billingStatement(): BelongsTo
    {
        return $this->belongsTo(BillingStatement::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** Pembayaran yang boleh dilihat: Manager dan FO semua; F&B hanya pembayaran nota yang boleh ia lihat. */
    public function scopeTerlihatOleh(Builder $q, User $user): void
    {
        if (! $user->hasRole('manager', 'front_office')) {
            $q->whereHas('invoice', fn ($n) => $n->terlihatOleh($user));
        }
    }

    /** Jumlah bertanda: refund negatif. */
    public function jumlahBertanda(): float
    {
        return $this->transaction_type === 'refund' ? -(float) $this->amount : (float) $this->amount;
    }

    /** Kode pembayaran asal dari catatan refund ("· asal PAY-0012"). */
    public function kodeAsal(): ?string
    {
        return preg_match('/asal (PAY-\d+)/', (string) $this->note, $m) ? $m[1] : null;
    }
}
