<?php

namespace App\Models;

use App\Contracts\BayarOnline;
use App\Services\PembayaranService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Nota (folio). Nota menginap dan nota layanan menginap bisa dilunasi di meja check-out, termasuk lewat
 * QRIS Midtrans (awalan WT-INV2026100012, jumlah = sisa saat QR dibuat, tujuan settlement).
 */
class Invoice extends Model implements BayarOnline
{
    /** Batas QR pelunasan di meja resepsionis. */
    public const MENIT_QR = 30;

    protected $fillable = [
        'invoice_number', 'invoice_type', 'customer_id', 'reservation_id', 'institution_id', 'billing_statement_id',
        'payment_term', 'issue_date', 'period_start', 'period_end', 'due_date', 'subtotal', 'discount_type',
        'discount_value', 'discount_amount', 'total_amount', 'paid_amount', 'status', 'issued_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
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

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function billingStatement(): BelongsTo
    {
        return $this->belongsTo(BillingStatement::class);
    }

    /** Pesanan yang notanya ini: pesanan bayar-sekarang, atau pesanan tagihan kamar yang digabung saat check-out. */
    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(BillingReminder::class);
    }

    public function hallBooking(): HasOne
    {
        return $this->hasOne(HallBooking::class);
    }

    /** Nota yang boleh dilihat: Manager dan FO semua nota; F&B hanya nota layanan yang memuat baris makanan. */
    public function scopeTerlihatOleh(Builder $q, User $user): void
    {
        if (! $user->hasRole('manager', 'front_office')) {
            $q->where('invoice_type', 'service')->whereHas('items', fn ($i) => $i->where('item_type', 'fnb'));
        }
    }

    /** "Menginap", "Layanan", atau "Aula". */
    public function jenis(): string
    {
        return ['stay' => 'Menginap', 'service' => 'Layanan', 'hall' => 'Aula'][$this->invoice_type] ?? $this->invoice_type;
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

    /* ---------------- Pelunasan lewat Midtrans (QRIS di meja check-out) ---------------- */

    /** Tidak ada token di tabel nota; kunci dibentuk dengan HMAC supaya tidak bisa ditebak dari nomor nota. */
    public function kunciBayar(): string
    {
        return hash_hmac('sha256', $this->id.'|'.$this->invoice_number, (string) config('app.key'));
    }

    /** INV/2026/10/0012 menjadi WT-INV2026100012. */
    public function awalanMidtrans(): string
    {
        return 'WT-INV'.str_replace('/', '', substr($this->invoice_number, 4));
    }

    /** Jumlah QR yang ditetapkan layar check-out (sisa menurut pilihan FO, misalnya biaya terlambat dibebaskan). */
    public ?int $jumlahTetap = null;

    /** Sisa tagihan saat ini, termasuk layanan yang belum digabung ke nota selama tamu masih menginap. */
    public function jumlahBayar(): int
    {
        return $this->jumlahTetap ?? (int) round(app(PembayaranService::class)->sisaTagihan($this));
    }

    public function batasBayar(): CarbonInterface
    {
        return now()->addMinutes(self::MENIT_QR);
    }

    public function rincianBayar(): array
    {
        return [[
            'id' => 'INV-'.$this->id,
            'price' => $this->jumlahBayar(),
            'quantity' => 1,
            'name' => 'Pelunasan '.$this->invoice_number,
        ]];
    }

    public function keteranganBayar(): string
    {
        return 'Nota '.$this->invoice_number;
    }

    public function pembayar(): ?Customer
    {
        return $this->customer;
    }

    public function notaBayar(): ?Invoice
    {
        return $this;
    }

    public function sudahDibayar(): bool
    {
        return $this->jumlahBayar() <= 0;
    }
}
