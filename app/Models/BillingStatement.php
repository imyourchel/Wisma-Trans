<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tagihan bulanan instansi yang menggabungkan beberapa nota kredit (BS/…). */
class BillingStatement extends Model
{
    public const STATUS = [
        'draft' => 'Draft', 'issued' => 'Terbit', 'partially_paid' => 'Dibayar sebagian', 'paid' => 'Lunas',
        'overdue' => 'Lewat jatuh tempo', 'cancelled' => 'Dibatalkan',
    ];

    protected $fillable = [
        'statement_number', 'institution_id', 'period_start', 'period_end', 'issue_date', 'due_date',
        'total_amount', 'paid_amount', 'status', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(BillingReminder::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function sisa(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    public function label(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** "26 Sep – 25 Okt 2026" */
    public function rentang(): string
    {
        return $this->period_start->translatedFormat($this->period_start->isSameYear($this->period_end) ? 'j M' : 'j M Y')
            .' – '.$this->period_end->translatedFormat('j M Y');
    }
}
