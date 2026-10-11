<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penerima tugas housekeeping dan bagian bebannya (poin). */
class HousekeepingTaskAssignee extends Model
{
    public $timestamps = false;

    protected $fillable = ['housekeeping_task_id', 'user_id', 'workload_share'];

    protected function casts(): array
    {
        return ['workload_share' => 'decimal:2'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(HousekeepingTask::class, 'housekeeping_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
