<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali perhitungan prioritas pembersihan (AHP-SAW). Boleh dihitung berkali-kali sehari;
 * batch terbaru pada tanggal itu yang berlaku.
 */
class CleaningPriorityBatch extends Model
{
    protected $fillable = ['batch_date', 'generated_at', 'generated_by', 'ahp_session_id', 'note'];

    protected function casts(): array
    {
        return ['batch_date' => 'date', 'generated_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CleaningPriorityItem::class)->orderBy('priority_rank');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** Batch terbaru pada tanggal itu, atau null bila belum pernah dihitung. */
    public static function terbaru(DateTimeInterface $tanggal): ?self
    {
        return self::whereDate('batch_date', $tanggal->format('Y-m-d'))->orderByDesc('generated_at')->orderByDesc('id')->first();
    }
}
