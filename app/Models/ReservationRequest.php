<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Isi pengajuan asli instansi, dibekukan sejak dikirim. Kamar dan harga hasil kesepakatan ada di reservation_rooms. */
class ReservationRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['reservation_id', 'check_in_date', 'check_out_date', 'room_count', 'event_name'];

    protected function casts(): array
    {
        return ['check_in_date' => 'date', 'check_out_date' => 'date'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function malam(): int
    {
        return (int) $this->check_in_date->diffInDays($this->check_out_date);
    }
}
