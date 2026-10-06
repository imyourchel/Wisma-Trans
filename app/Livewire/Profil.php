<?php

namespace App\Livewire;

use App\Models\HallBooking;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Halaman /profil untuk semua akun. Tamu mengubah data diri (ikut memperbarui data pelanggannya) dan kata sandi;
 * staf memakai halaman yang sama di panel staf. Akun Google tanpa kata sandi bisa membuat kata sandi di sini.
 */
class Profil extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $gender = '';

    public string $date_of_birth = '';

    public string $address = '';

    public string $sandiLama = '';

    public string $sandiBaru = '';

    public string $sandiBaru_confirmation = '';

    public function mount(): void
    {
        $user = $this->pengguna();
        $c = $user->customer;
        $this->fill([
            'name' => $user->name,
            'phone' => (string) ($user->phone ?? $c?->phone),
            'email' => $user->email,
            'gender' => (string) $c?->gender,
            'date_of_birth' => (string) $c?->date_of_birth?->toDateString(),
            'address' => (string) $c?->address,
        ]);
    }

    private function pengguna(): User
    {
        return Auth::user()->loadMissing('customer', 'socialAccounts', 'roles');
    }

    public function simpanData(): void
    {
        $user = $this->pengguna();
        $this->name = PersonName::normalize($this->name);
        $this->email = Str::lower(trim($this->email));
        $this->phone = Phone::normalizeInput($this->phone);

        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'phone' => [$user->customer ? 'required' : 'nullable', new NomorHp, Rule::unique('users', 'phone')->ignore($user->id)],
            'email' => ['required', 'max:150', new EmailWajar, Rule::unique('users', 'email')->ignore($user->id)],
            'gender' => ['nullable', 'in:L,P'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Wajib diisi.',
            'name.min' => 'Minimal 3 huruf.',
            'name.max' => 'Maksimal 100 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'phone.required' => 'Wajib diisi.',
            'phone.unique' => 'Nomor ini sudah dipakai akun lain.',
            'email.required' => 'Wajib diisi.',
            'email.max' => 'Maksimal 150 karakter.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'date_of_birth.date' => 'Tanggal tidak valid.',
            'date_of_birth.before' => 'Tanggal lahir harus sebelum hari ini.',
            'date_of_birth.after' => 'Tanggal tidak valid.',
            'address.max' => 'Maksimal 255 karakter.',
        ]);

        DB::transaction(function () use ($user) {
            $emailBerubah = $user->email !== $this->email;
            $user->fill(['name' => $this->name, 'phone' => $this->phone ?: null, 'email' => $this->email]);
            if ($emailBerubah) {
                $user->email_verified_at = null;
            }
            $user->save();

            // Data pelanggan mengikuti akun supaya nota dan pesan WhatsApp memakai data terbaru
            $user->customer?->update([
                'full_name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'gender' => $this->gender ?: null,
                'date_of_birth' => $this->date_of_birth ?: null,
                'address' => trim($this->address) ?: null,
            ]);
        });

        $this->dispatch('toast', teks: 'Data diri sudah disimpan.');
    }

    public function simpanSandi(): void
    {
        $user = $this->pengguna();
        $adaSandi = $user->password !== null;

        $this->validate([
            'sandiLama' => $adaSandi ? ['required', 'current_password'] : [],
            'sandiBaru' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'sandiLama.required' => 'Wajib diisi.',
            'sandiLama.current_password' => 'Kata sandi saat ini salah.',
            'sandiBaru.required' => 'Wajib diisi.',
            'sandiBaru.min' => 'Minimal 8 karakter.',
            'sandiBaru.max' => 'Maksimal 72 karakter.',
            'sandiBaru.confirmed' => 'Konfirmasi kata sandi tidak sesuai.',
        ]);
        if ($adaSandi && Hash::check($this->sandiBaru, $user->password)) {
            $this->addError('sandiBaru', 'Kata sandi baru harus berbeda dari yang sekarang.');

            return;
        }

        $user->forceFill(['password' => $this->sandiBaru, 'remember_token' => Str::random(60)])->save();
        $this->reset('sandiLama', 'sandiBaru', 'sandiBaru_confirmation');
        $this->dispatch('toast', teks: $adaSandi ? 'Kata sandi sudah diganti.' : 'Kata sandi sudah dibuat. Sekarang Anda juga bisa masuk dengan email atau nomor HP.');
    }

    public function render(): View
    {
        $user = $this->pengguna();
        $staf = $user->isStaff();
        $c = $user->customer;

        $data = [
            'user' => $user,
            'staf' => $staf,
            'google' => $user->socialAccounts->firstWhere('provider', 'google'),
            'adaSandi' => $user->password !== null,
            'jumlah' => $c ? [
                'reservasi' => $c->reservations()->count(),
                'layanan' => ServiceOrder::where('customer_id', $c->id)->count(),
                'aula' => HallBooking::where('customer_id', $c->id)->count(),
            ] : null,
        ];

        return $staf
            ? view('livewire.profil', $data)->layout('components.layouts.staf', ['title' => 'Profil'])
            : view('livewire.profil', $data)->layout('components.layouts.guest', ['title' => 'Profil']);
    }
}
