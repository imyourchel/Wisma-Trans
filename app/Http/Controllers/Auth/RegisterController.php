<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    /**
     * Formulir di modal mengirim lewat fetch (JSON), jadi galat dikembalikan
     * sebagai 422 tanpa memuat ulang halaman. Pesan dibuat singkat karena
     * tampil di samping label kolomnya.
     */
    public function store(Request $request, RegisterCustomer $register): RedirectResponse|JsonResponse
    {
        $request->merge([
            'name' => PersonName::normalize((string) $request->input('name')),
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => Phone::normalizeInput((string) $request->input('phone')),
        ]);

        $data = $request->validateWithBag('register', [
            'name' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'phone' => ['required', new NomorHp, 'unique:users,phone'],
            'email' => ['required', 'max:150', new EmailWajar, 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'password_confirmation' => ['required', 'same:password'],
        ], [
            'name.required' => 'Wajib diisi.',
            'name.min' => 'Minimal 3 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'name.max' => 'Maksimal 100 huruf.',
            'phone.required' => 'Wajib diisi.',
            'phone.unique' => 'Sudah terdaftar. Silakan masuk.',
            'email.required' => 'Wajib diisi.',
            'email.max' => 'Maksimal 150 karakter.',
            'email.unique' => 'Sudah terdaftar. Silakan masuk.',
            'password.required' => 'Wajib diisi.',
            'password.min' => 'Minimal 8 karakter.',
            'password.max' => 'Maksimal 72 karakter.',
            'password_confirmation.required' => 'Wajib diisi.',
            'password_confirmation.same' => 'Tidak sesuai.',
        ]);
        unset($data['password_confirmation']);

        [$user, $linkedOrders] = $register->handle($data, 'register');

        Auth::login($user);
        $request->session()->regenerate();

        $redirect = redirect()->intended(route('home'))->with('status', RegisterCustomer::message($linkedOrders));

        return $request->expectsJson()
            ? response()->json(['redirect' => $redirect->getTargetUrl()])
            : $redirect;
    }
}
