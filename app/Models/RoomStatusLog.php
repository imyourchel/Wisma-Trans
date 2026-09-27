<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat status kamar; status = status SETELAH perubahan. */
class RoomStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['room_id', 'status', 'changed_by', 'changed_at', 'note'];

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function changedBy(): BelongsTo
    {
        // Pengubah yang sudah dihapus tidak dimuat, sehingga tampil sebagai "Sistem"
        return $this->belongsTo(User::class, 'changed_by');
    }
}
