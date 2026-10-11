<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    /**
     * Periode tagihan bulanan yang memuat $tanggal: (tanggal potong bulan lalu + 1) sampai tanggal potong bulan ini,
     * inklusif; tagihan diterbitkan pada tanggal potong. Bila billing_day tidak ada di bulan itu (misalnya 31 di Februari),
     * dipakai tanggal terakhir bulan tersebut. Null bila tanggal tagih instansi belum diatur.
     *
     * @return array{mulai: CarbonImmutable, akhir: CarbonImmutable, terbit: CarbonImmutable}|null
     */
    public function periodeTagihan(CarbonInterface $tanggal): ?array
    {
        if (! $this->billing_day) {
            return null;
        }
        $hari = CarbonImmutable::parse($tanggal->toDateString());
        $potong = fn (CarbonImmutable $bulan) => $bulan->startOfMonth()->setDay(min((int) $this->billing_day, $bulan->daysInMonth));
        $akhir = $potong($hari);
        if ($hari->gt($akhir)) {
            $akhir = $potong($hari->startOfMonth()->addMonth());
        }
        $mulai = $potong($akhir->startOfMonth()->subMonth())->addDay();

        return ['mulai' => $mulai, 'akhir' => $akhir, 'terbit' => $akhir];
    }

    /** "periode 26 Sep – 25 Okt 2026, diterbitkan 25 Okt" */
    public static function teksPeriode(array $p): string
    {
        $mulai = $p['mulai']->isSameYear($p['akhir']) ? $p['mulai']->translatedFormat('j M') : $p['mulai']->translatedFormat('j M Y');

        return 'periode '.$mulai.' – '.$p['akhir']->translatedFormat('j M Y').', diterbitkan '.$p['terbit']->translatedFormat('j M');
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
