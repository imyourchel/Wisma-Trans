<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait RedirectsAfterLogin
{
    /** Halaman tamu asal (misalnya formulir pesan kamar) yang dituju lagi setelah masuk atau daftar. */
    private static string $kunciKembali = 'kembali_tamu';

    /** Halaman yang tidak dituju lagi: halaman masuk/daftar itu sendiri dan alur Google. */
    private static array $bukanTujuan = ['login', 'register', 'masuk', 'daftar', 'auth', 'keluar', 'lupa-sandi', 'atur-ulang-sandi'];

    /**
     * Simpan alamat halaman asal dari isian/parameter "kembali". Hanya jalur di situs ini yang diterima
     * (host lain diabaikan), lalu dibangun ulang dengan url() supaya tidak bisa diarahkan ke situs luar.
     */
    protected function simpanKembali(Request $request): void
    {
        $url = trim((string) $request->input('kembali'));
        $bagian = $url !== '' ? parse_url($url) : false;
        if (! $bagian || str_starts_with($url, '//') || ! in_array($bagian['scheme'] ?? 'https', ['http', 'https'], true)
            || (isset($bagian['host']) && $bagian['host'] !== $request->getHost())) {
            return;
        }
        $jalur = '/'.ltrim($bagian['path'] ?? '/', '/');
        if (in_array(Str::before(ltrim($jalur, '/'), '/'), self::$bukanTujuan, true) || str_starts_with($jalur, '/staf')) {
            return;
        }

        $request->session()->put(self::$kunciKembali, url($jalur).(isset($bagian['query']) ? '?'.$bagian['query'] : ''));
    }

    /** Tamu: kembali ke halaman asal, lalu halaman yang dituju sebelumnya, lalu beranda. */
    protected function redirectTamu(string $message): RedirectResponse
    {
        $kembali = session()->pull(self::$kunciKembali);

        return ($kembali ? redirect()->to($kembali) : redirect()->intended(route('home')))->with('status', $message);
    }

    /** Staf ke panel staf, tamu kembali ke halaman asal atau halaman yang dituju sebelumnya. */
    protected function redirectAfterLogin(User $user, ?string $message = null): RedirectResponse
    {
        $user->loadMissing('roles');
        $firstName = Str::before($user->name, ' ');

        if ($user->isStaff()) {
            session()->forget(self::$kunciKembali);

            return redirect()->intended(route('staf.dashboard'))->with('status', $message ?? "Selamat datang, {$firstName}.");
        }

        return $this->redirectTamu($message ?? "Selamat datang kembali, {$firstName}.");
    }
}
