<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengingat penagihan instansi, satu baris per kanal. Tahap internal = ringkasan ke Manager;
 * tahap to_institution = pesan ke instansi yang baru dikirim setelah diperiksa Manager.
 * Sasarannya nota kredit (invoice_id) atau tagihan bulanan (billing_statement_id).
 */
class BillingReminder extends Model
{
    public const STATUS = [
        'pending' => 'Menunggu diperiksa', 'verified' => 'Diperiksa', 'sent' => 'Terkirim', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan',
    ];

    public const KANAL = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'letter' => 'Surat'];

    protected $fillable = [
        'invoice_id', 'billing_statement_id', 'reminder_stage', 'scheduled_date', 'channel', 'recipient',
        'status', 'verified_by', 'verified_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['scheduled_date' => 'date', 'verified_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function billingStatement(): BelongsTo
    {
        return $this->belongsTo(BillingStatement::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Tagihan sasaran pengingat ini. */
    public function tagihan(): Invoice|BillingStatement|null
    {
        return $this->billing_statement_id ? $this->billingStatement : $this->invoice;
    }

    /** Batalkan pengingat yang masih menunggu untuk tagihan yang sudah lunas atau dibatalkan. */
    public static function batalkanUntuk(Invoice|BillingStatement $t): void
    {
        self::where($t instanceof Invoice ? 'invoice_id' : 'billing_statement_id', $t->id)
            ->where('status', 'pending')->update(['status' => 'cancelled']);
    }
}
