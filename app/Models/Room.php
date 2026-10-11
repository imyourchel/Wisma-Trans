<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kolom status sengaja tidak fillable: status hanya diganti lewat App\Services\RoomStatusService
 * supaya dirty_since dan room_status_logs selalu ikut tercatat.
 */
class Room extends Model
{
    public const BED_TYPES = ['single' => 'Single', 'twin' => 'Twin', 'double' => 'Double', 'king' => 'King'];

    protected $fillable = ['room_number', 'room_type_id', 'floor', 'bed_type', 'is_active'];

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'dirty_since' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(RoomStatusLog::class);
    }

    public function facilityUnits(): HasMany
    {
        return $this->hasMany(FacilityUnit::class);
    }

    /**
     * Laporan kerusakan terbuka yang membuat kamar ini tidak bisa dipakai (blocks_room), paling lama dulu.
     * Selama ada, kamar tidak boleh menjadi Siap. Kosong bila kolom blocks_room belum ada di basis data.
     *
     * @return Collection<int, MaintenanceRequest>
     */
    public function laporanMenahan(): Collection
    {
        if (! MaintenanceRequest::adaKolomMenahan()) {
            return new Collection;
        }

        return $this->maintenanceRequests()->whereIn('status', ['reported', 'in_progress'])->where('blocks_room', true)
            ->orderBy('reported_at')->orderBy('id')->get();
    }

    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class);
    }

    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /**
     * Pemesanan kamar ini yang belum lewat tanggal keluarnya, paling awal dulu.
     *
     * @param  list<string>  $status  status reservation_rooms
     * @return Collection<int, ReservationRoom>
     */
    public function pemesananBerjalan(array $status = ['booked', 'checked_in']): Collection
    {
        return $this->reservationRooms()->with('reservation')->whereIn('status', $status)
            ->whereDate('check_out_date', '>=', today())->orderBy('check_in_date')->orderBy('id')->get();
    }

    /** Laporan perawatan kamar ini: lewat unit fasilitas di kamar, atau dilaporkan langsung untuk kamar. */
    public function maintenanceRequests(): Builder
    {
        return MaintenanceRequest::query()->where(fn ($q) => $q->where('room_id', $this->id)
            ->orWhereHas('unit', fn ($u) => $u->where('room_id', $this->id)));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function bedTypeLabel(): string
    {
        return self::BED_TYPES[$this->bed_type] ?? $this->bed_type;
    }

    /** Lama kamar menunggu dibersihkan, misalnya "kotor 2 jam 45 mnt". */
    public function dirtyDuration(): ?string
    {
        if (! $this->status?->isDirty() || ! $this->dirty_since) {
            return null;
        }

        // Data contoh bisa bertanggal lebih maju dari hari ini
        if ($this->dirty_since->isFuture()) {
            return 'kotor sejak '.$this->dirty_since->translatedFormat('d M, H.i');
        }

        $menit = (int) $this->dirty_since->diffInMinutes(now());
        $hari = intdiv($menit, 1440);
        $jam = intdiv($menit % 1440, 60);
        $sisa = $menit % 60;

        return 'kotor '.match (true) {
            $hari > 0 => "{$hari} hari {$jam} jam",
            $jam > 0 => "{$jam} jam {$sisa} mnt",
            default => "{$sisa} mnt",
        };
    }
}
