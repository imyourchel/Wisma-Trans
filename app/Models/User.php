<?php

namespace App\Models;

use App\Notifications\AturUlangKataSandi;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('assigned_at');
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Benar bila pengguna punya salah satu peran yang disebut. */
    public function hasRole(string ...$codes): bool
    {
        return $this->roles->whereIn('code', $codes)->isNotEmpty();
    }

    /** Email atur ulang kata sandi berbahasa Indonesia. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new AturUlangKataSandi($token));
    }

    /** Staf = punya peran apa pun selain customer. */
    public function isStaff(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->code !== Role::CUSTOMER);
    }
}
