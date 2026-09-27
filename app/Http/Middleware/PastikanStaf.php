<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman staf: harus masuk, akun aktif, dan punya peran staf.
 * Pemakaian: ->middleware('staf') atau ->middleware('staf:admin,manager').
 */
class PastikanStaf
{
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login'));
        }

        // Akun yang dinonaktifkan admin langsung keluar, walaupun sesinya masih ada
        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home')->with('error', 'Akun Anda dinonaktifkan. Hubungi admin.');
        }

        $user->loadMissing('roles');
        if (! $user->isStaff()) {
            abort(403, 'Halaman ini khusus staf Wisma Trans.');
        }

        if ($peran && $user->roles->whereIn('code', $peran)->isEmpty()) {
            abort(403, 'Halaman ini bukan untuk peran Anda.');
        }

        return $next($request);
    }
}
