<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAfterLogin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HomeController;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Phone;
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
 * Lupa kata sandi lewat tautan di email atau WhatsApp. Token disimpan di cache (config/auth.php).
 * Jawaban permintaan tautan selalu sama agar tidak membocorkan email atau nomor mana yang terdaftar.
 */
class LupaSandiController extends Controller
{
    use RedirectsAfterLogin;

    /**
     * Isian boleh email atau nomor HP, sama seperti saat masuk. Lewat email: tautan dikirim ke email itu.
     * Lewat nomor HP: tautan dikirim ke WhatsApp nomor itu dan ke email akunnya.
     */
    public function kirim(Request $request, WhatsAppService $wa): JsonResponse|RedirectResponse
    {
        $isian = trim((string) $request->input('login', $request->input('email')));
        $pakaiEmail = str_contains($isian, '@');
        $request->merge(['login' => $pakaiEmail ? Str::lower($isian) : Phone::normalizeInput($isian)]);
        $request->validate(['login' => ['required', 'max:150', $pakaiEmail ? 'email' : 'regex:/'.Phone::MOBILE_PATTERN.'/']], [
            'login.required' => 'Email atau nomor WhatsApp wajib diisi.',
            'login.email' => 'Format email tidak valid.',
            'login.regex' => 'Nomor WhatsApp tidak valid. Contoh: 081234567890.',
        ]);

        $kunci = 'lupa-sandi|'.$request->ip();
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $menit = (int) ceil(RateLimiter::availableIn($kunci) / 60);
            throw ValidationException::withMessages([
                'login' => "Terlalu banyak permintaan. Silakan coba lagi dalam {$menit} menit.",
            ]);
        }
        RateLimiter::hit($kunci, 600);

        if ($pakaiEmail) {
            // Akun nonaktif tidak dikirimi tautan. Permintaan ulang dalam 60 detik diabaikan oleh broker.
            Password::sendResetLink(['email' => $request->input('login'), 'is_active' => true]);
            $pesan = 'Jika email tersebut terdaftar, tautan untuk membuat kata sandi baru sudah dikirim.';
        } else {
            $this->kirimLewatWhatsApp($request->input('login'), $wa);
            $pesan = 'Jika nomor tersebut terdaftar, tautan untuk membuat kata sandi baru sudah dikirim ke WhatsApp dan email akun Anda.';
        }

        return $request->expectsJson()
            ? response()->json(['pesan' => $pesan])
            : back()->with('status', $pesan);
    }

    /** Akun dicari seperti saat masuk dengan nomor HP. Token yang sama dipakai untuk tautan WhatsApp dan email. */
    private function kirimLewatWhatsApp(string $hp, WhatsAppService $wa): void
    {
        $user = User::where('phone', $hp)->where('is_active', true)->first();
        $gudang = Password::broker()->getRepository();
        if (! $user || $gudang->recentlyCreatedToken($user)) {
            return;
        }

        $token = $gudang->create($user);
        $tautan = route('password.reset', ['token' => $token, 'email' => $user->email]);
        $menit = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $wa->kirim($user->phone, 'Halo '.Str::before($user->name, ' ').", berikut tautan untuk membuat kata sandi baru akun Wisma Trans Anda:\n\n{$tautan}\n\n"
            ."Tautan berlaku {$menit} menit. Abaikan pesan ini bila Anda tidak memintanya.");
        $user->sendPasswordResetNotification($token);
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
