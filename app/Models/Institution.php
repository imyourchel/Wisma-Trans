<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Instansi pelanggan (B2B) beserta skema penagihan bawaannya. */
class Institution extends Model
{
    public const SKEMA = [
        'upfront' => 'Bayar di muka',
        'monthly_fixed_date' => 'Tagihan bulanan',
        'due_after_checkout' => 'Jatuh tempo setelah check-out',
    ];

    protected $fillable = [
        'code', 'name', 'address', 'phone', 'email', 'primary_contact_id',
        'default_billing_scheme', 'billing_day', 'due_days',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'primary_contact_id');
    }

    /** Misalnya "Tagihan bulanan, tiap tanggal 25" atau "Jatuh tempo 14 hari setelah check-out". */
    public function ringkasSkema(?string $skema = null): string
    {
        $skema ??= $this->default_billing_scheme;

        return match ($skema) {
            'monthly_fixed_date' => 'Tagihan bulanan'.($this->billing_day ? ', tiap tanggal '.$this->billing_day : ''),
            'due_after_checkout' => 'Jatuh tempo '.($this->due_days ?? '?').' hari setelah check-out',
            default => self::SKEMA[$skema] ?? (string) $skema,
        };
    }
}
