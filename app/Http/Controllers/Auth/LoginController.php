<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAfterLogin;
use App\Http\Controllers\Controller;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    use RedirectsAfterLogin;

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validateWithBag('login', [
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Email atau nomor telepon wajib diisi.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);

        $throttleKey = Str::lower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $detik = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'login' => "Terlalu banyak percobaan masuk. Silakan coba lagi dalam {$detik} detik.",
            ])->errorBag('login');
        }

        // Satu isian menerima email maupun nomor telepon
        $login = trim($data['login']);
        $credentials = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? ['email' => Str::lower($login)]
            : ['phone' => Phone::normalize($login)];
        $credentials['password'] = $data['password'];

        // Akun Google tanpa kata sandi otomatis gagal di sini dan harus masuk lewat Google
        if (! Auth::attemptWhen($credentials, fn ($user) => $user->is_active, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                'login' => 'Email/nomor telepon atau kata sandi salah.',
            ])->errorBag('login');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        // Modal masuk mengirim lewat fetch: galat dijawab 422 tanpa memuat ulang halaman
        $redirect = $this->redirectAfterLogin($request->user());

        return $request->expectsJson()
            ? response()->json(['redirect' => $redirect->getTargetUrl()])
            : $redirect;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Anda sudah keluar.');
    }
}
