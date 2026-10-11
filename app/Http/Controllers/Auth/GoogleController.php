<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterCustomer;
use App\Http\Controllers\Auth\Concerns\RedirectsAfterLogin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tamu\HomeController;
use App\Models\SocialAccount;
use App\Models\User;
use App\Rules\NomorHp;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleController extends Controller
{
    use RedirectsAfterLogin;

    private const PENDING = 'google_pending';

    /** ?kembali=: halaman tamu asal, dituju lagi setelah masuk atau daftar lewat Google. */
    public function redirect(Request $request): RedirectResponse
    {
        $kosong = array_keys(array_filter([
            'GOOGLE_CLIENT_ID' => blank(config('services.google.client_id')),
            'GOOGLE_CLIENT_SECRET' => blank(config('services.google.client_secret')),
        ]));

        if ($kosong) {
            // Saat pengembangan, sebutkan kunci .env yang belum diisi
            $pesan = app()->isLocal()
                ? 'Masuk dengan Google belum dikonfigurasi: isi '.implode(' dan ', $kosong).' di .env, lalu jalankan php artisan config:clear.'
                : 'Masuk dengan Google belum tersedia. Silakan masuk menggunakan email dan kata sandi.';

            return redirect()->route('home')->with('error', $pesan);
        }

        $this->simpanKembali($request);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('home')->with('error', 'Proses masuk dengan Google dibatalkan atau gagal. Silakan coba lagi.');
        }

        $email = Str::lower((string) $google->getEmail());
        if ($email === '' || ! ($google->user['email_verified'] ?? false)) {
            return redirect()->route('home')->with('error', 'Email akun Google Anda belum terverifikasi.');
        }

        $social = SocialAccount::where('provider', 'google')->where('provider_user_id', $google->getId())->first();

        if ($social) {
            $user = $social->user; // null bila akunnya sudah dihapus
        } else {
            // Akun yang emailnya sama dihubungkan ke Google, termasuk akun staf
            $user = User::where('email', $email)->first();
            if ($user) {
                if ($user->socialAccounts()->where('provider', 'google')->exists()) {
                    return redirect()->route('home')->with('error', 'Akun ini sudah terhubung dengan akun Google lain.');
                }
                $this->linkGoogle($user, $google->getId(), $email);
            }
        }

        if (($social && ! $user) || User::onlyTrashed()->where('email', $email)->exists()) {
            return redirect()->route('home')->with('error', 'Akun Anda tidak aktif. Hubungi resepsionis.');
        }

        if ($user) {
            if (! $user->is_active) {
                return redirect()->route('home')->with('error', 'Akun Anda dinonaktifkan. Hubungi resepsionis.');
            }

            Auth::login($user, remember: true);
            $request->session()->regenerate();

            return $this->redirectAfterLogin($user);
        }

        // Belum punya akun: lengkapi nomor telepon dulu sebelum akun customer dibuat
        $request->session()->put(self::PENDING, [
            'id' => $google->getId(),
            'email' => $email,
            'name' => $google->getName() ?: Str::before($email, '@'),
        ]);

        return redirect()->route('auth.google.lengkapi');
    }

    public function create(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! $pending) {
            return redirect()->route('home');
        }

        // Tampil sebagai modal di atas beranda, sama seperti modal Masuk/Daftar
        return app(HomeController::class)()->with(['googlePending' => $pending, 'title' => 'Lengkapi pendaftaran']);
    }

    public function store(Request $request, RegisterCustomer $register): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! $pending) {
            return redirect()->route('home')->with('error', 'Sesi pendaftaran Google berakhir. Silakan ulangi.');
        }

        $request->merge([
            'name' => PersonName::normalize((string) $request->input('name')),
            'phone' => Phone::normalizeInput((string) $request->input('phone')),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'phone' => ['required', new NomorHp, 'unique:users,phone'],
        ], [
            'name.required' => 'Wajib diisi.',
            'name.min' => 'Minimal 3 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'phone.required' => 'Wajib diisi.',
            'phone.unique' => 'Sudah terdaftar. Silakan masuk.',
        ]);

        // Email bisa saja terdaftar selagi formulir ini terbuka
        if (User::withTrashed()->where('email', $pending['email'])->exists()) {
            $request->session()->forget(self::PENDING);

            return redirect()->route('login')->with('error', 'Email ini sudah terdaftar. Silakan masuk.');
        }

        [$user, $linkedOrders] = DB::transaction(function () use ($register, $data, $pending) {
            $result = $register->handle([
                'name' => $data['name'],
                'email' => $pending['email'],
                'phone' => $data['phone'],
                'password' => null,
                'email_verified' => true,
            ]);
            $this->linkGoogle($result[0], $pending['id'], $pending['email']);

            return $result;
        });

        $request->session()->forget(self::PENDING);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return $this->redirectTamu(RegisterCustomer::message($linkedOrders));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING);

        return redirect()->route('home');
    }

    private function linkGoogle(User $user, string $googleId, string $email): void
    {
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => $googleId,
            'provider_email' => $email,
        ]);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
