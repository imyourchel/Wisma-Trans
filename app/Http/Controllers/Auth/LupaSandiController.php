<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAfterLogin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HomeController;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Lupa kata sandi lewat tautan email. Token disimpan di cache (config/auth.php).
 * Jawaban permintaan tautan selalu sama agar tidak membocorkan email mana yang terdaftar.
 */
class LupaSandiController extends Controller
{
    use RedirectsAfterLogin;

    public function kirim(Request $request): JsonResponse|RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email', 'max:150']], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
        ]);

        $kunci = 'lupa-sandi|'.$request->ip();
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $menit = (int) ceil(RateLimiter::availableIn($kunci) / 60);
            throw ValidationException::withMessages([
                'email' => "Terlalu banyak permintaan. Silakan coba lagi dalam {$menit} menit.",
            ]);
        }
        RateLimiter::hit($kunci, 600);

        // Akun nonaktif tidak dikirimi tautan. Permintaan ulang dalam 60 detik diabaikan oleh broker.
        Password::sendResetLink(['email' => $request->input('email'), 'is_active' => true]);

        $pesan = 'Jika email tersebut terdaftar, tautan untuk membuat kata sandi baru sudah dikirim.';

        return $request->expectsJson()
            ? response()->json(['pesan' => $pesan])
            : back()->with('status', $pesan);
    }

    /** Halaman dari tautan email, tampil sebagai modal di atas beranda. */
    public function create(Request $request, string $token): View
    {
        $email = Str::lower((string) $request->query('email'));
        $user = User::where('email', $email)->where('is_active', true)->first();

        return app(HomeController::class)()->with([
            'title' => 'Atur ulang kata sandi',
            'aturUlang' => [
                'token' => $token,
                'email' => $email,
                'berlaku' => $user && Password::tokenExists($user, $token),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'password.required' => 'Wajib diisi.',
            'password.min' => 'Minimal 8 karakter.',
            'password.max' => 'Maksimal 72 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sesuai.',
        ]);

        $user = null;
        $status = Password::reset(
            ['email' => Str::lower($data['email']), 'is_active' => true] + $request->only('password', 'password_confirmation', 'token'),
            function (User $u, string $password) use (&$user) {
                $u->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($u));
                $user = $u;
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'Tautan ini sudah tidak berlaku. Silakan minta tautan baru dari halaman masuk.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $redirect = $this->redirectAfterLogin($user, 'Kata sandi baru sudah disimpan. Selamat datang, '.Str::before($user->name, ' ').'.');

        return $request->expectsJson()
            ? response()->json(['redirect' => $redirect->getTargetUrl()])
            : $redirect;
    }
}
