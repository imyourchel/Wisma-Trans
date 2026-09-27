<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

trait RedirectsAfterLogin
{
    /** Staf ke panel staf, tamu kembali ke halaman yang dituju sebelumnya. */
    protected function redirectAfterLogin(User $user, ?string $message = null): RedirectResponse
    {
        $user->loadMissing('roles');
        $firstName = Str::before($user->name, ' ');

        if ($user->isStaff()) {
            return redirect()->intended(route('staf.dashboard'))->with('status', $message ?? "Selamat datang, {$firstName}.");
        }

        return redirect()->intended(route('home'))->with('status', $message ?? "Selamat datang kembali, {$firstName}.");
    }
}
