<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tugas membersihkan satu kamar pada satu tanggal. Satu kamar bisa mendapat lebih dari satu tugas sehari.
 * Status: assigned → in_progress → completed → verified, atau cancelled.
 */
class HousekeepingTask extends Model
{
    public const STATUS = [
        'assigned' => 'Belum dimulai',
        'in_progress' => 'Sedang dibersihkan',
        'completed' => 'Selesai',
        'verified' => 'Terverifikasi',
        'cancelled' => 'Dibatalkan',
    ];

    /** Tugas yang masih berjalan; kamar dengan tugas ini tidak dibagi lagi. */
    public const TERBUKA = ['assigned', 'in_progress'];

    protected $fillable = [
        'room_id', 'task_date', 'cleaning_priority_item_id', 'workload_point', 'assignment_method', 'status',
        'started_at', 'completed_at', 'assigned_by', 'verified_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'task_date' => 'date',
            'workload_point' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CleaningPriorityItem::class, 'cleaning_priority_item_id');
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(HousekeepingTaskAssignee::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function label(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    public function dikerjakanOleh(User $user): bool
    {
        return $this->assignees()->where('user_id', $user->id)->exists();
    }

    /** Nama penerima tugas, dipisah koma. */
    public function namaStaf(): string
    {
        return $this->assignees->map(fn ($a) => $a->user?->name)->filter()->implode(', ');
    }
}
