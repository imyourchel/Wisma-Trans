<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jadwal perawatan rutin satu jenis fasilitas di satu area, misalnya AC Lantai 2 tiap 6 bulan. */
class MaintenanceSchedule extends Model
{
    /** Jadwal yang perawatan berikutnya dalam sekian hari ditandai "Perlu dirawat minggu ini". */
    public const HARI_SEGERA = 7;

    protected $fillable = ['facility_id', 'area_label', 'interval_months', 'last_maintenance_date', 'next_due_date', 'vendor_name', 'is_active', 'note'];

    protected function casts(): array
    {
        return [
            'last_maintenance_date' => 'date',
            'next_due_date' => 'date',
            'interval_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    /** lewat (terlambat dirawat) | segera (perlu dirawat dalam HARI_SEGERA hari) | normal */
    public function penanda(): string
    {
        $hariIni = today();

        return match (true) {
            $this->next_due_date->lt($hariIni) => 'lewat',
            $this->next_due_date->lte($hariIni->copy()->addDays(self::HARI_SEGERA)) => 'segera',
            default => 'normal',
        };
    }

    /** Berapa hari perawatan terlambat (0 bila belum terlambat). */
    public function hariLewat(): int
    {
        return $this->penanda() === 'lewat' ? (int) $this->next_due_date->diffInDays(today()) : 0;
    }

    /** Unit aktif yang tercakup: fasilitas dan area sama. */
    public function jumlahUnit(): int
    {
        return FacilityUnit::where('facility_id', $this->facility_id)->where('area_label', $this->area_label)->where('is_active', true)->count();
    }
}
