<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat reservasi: perubahan status dan serah terima KTP. changed_by kosong berarti dilakukan sistem atau tamu. */
class ReservationLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENT = [
        'status_change' => 'Perubahan status',
        'id_card_received' => 'KTP diterima',
        'id_card_returned' => 'KTP dikembalikan',
    ];

    protected $fillable = ['reservation_id', 'event_type', 'status', 'changed_by', 'changed_at', 'note'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
