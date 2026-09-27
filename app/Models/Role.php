<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const CUSTOMER = 'customer';

    public const ADMIN = 'admin';

    /** Peran staf, urut seperti di laporan. Customer tidak termasuk. */
    public const STAFF = ['admin', 'manager', 'front_office', 'fnb', 'housekeeping'];

    protected $fillable = ['code', 'name'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withPivot('assigned_at');
    }
}
