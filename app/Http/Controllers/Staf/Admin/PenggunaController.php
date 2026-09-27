<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Role;
use App\Models\User;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Akun staf. Akun tamu dibuat sendiri oleh tamu lewat pendaftaran, jadi tidak tampil di sini. */
class PenggunaController extends MasterController
{
    public function index(): View
    {
        $users = User::with('roles')
            ->whereHas('roles', fn ($q) => $q->whereIn('code', Role::STAFF))
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        return view('staf.admin.pengguna', [
            'users' => $users,
            'peran' => $this->peranStaf(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request);

        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?: null,
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->roles()->attach($data['peran'], ['assigned_at' => now()]);
        });

        return $this->selesai($request, "Akun {$data['name']} dibuat.");
    }

    public function update(Request $request, User $pengguna): JsonResponse|RedirectResponse
    {
        $data = $this->validasi($request, $pengguna);

        $adminId = Role::where('code', Role::ADMIN)->value('id');
        if ($pengguna->is($request->user()) && ! in_array($adminId, $data['peran'])) {
            throw ValidationException::withMessages(['peran' => 'Anda tidak dapat melepas peran Admin dari akun sendiri.']);
        }

        DB::transaction(function () use ($pengguna, $data) {
            $pengguna->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?: null,
            ]);
            if (filled($data['password'] ?? null)) {
                $pengguna->password = $data['password'];
            }
            $pengguna->save();

            // Hanya peran staf yang diatur di sini; peran customer (bila ada) dibiarkan.
            // Tanggal penetapan peran lama tidak berubah.
            $staf = Role::whereIn('code', Role::STAFF)->pluck('id');
            $sekarang = $pengguna->roles()->pluck('roles.id');
            $pengguna->roles()->detach($staf->diff($data['peran'])->intersect($sekarang)->all());
            $pengguna->roles()->attach(collect($data['peran'])->diff($sekarang)->all(), ['assigned_at' => now()]);
        });

        $pesan = filled($data['password'] ?? null)
            ? "Data {$data['name']} disimpan dan kata sandinya diganti."
            : "Data {$data['name']} disimpan.";

        return $this->selesai($request, $pesan);
    }

    public function toggle(Request $request, User $pengguna): RedirectResponse
    {
        if ($pengguna->is($request->user())) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun sendiri.');
        }

        return $this->balikAktif($pengguna, 'Akun '.$pengguna->name);
    }

    private function validasi(Request $request, ?User $user = null): array
    {
        $request->merge([
            'name' => PersonName::normalize((string) $request->input('name')),
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => filled($request->input('phone')) ? Phone::normalizeInput((string) $request->input('phone')) : null,
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'email' => ['required', 'max:150', new EmailWajar, Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', new NomorHp, Rule::unique('users', 'phone')->ignore($user)],
            'peran' => ['required', 'array', 'min:1'],
            'peran.*' => ['integer', Rule::in($this->peranStaf()->pluck('id')->all())],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:72'],
        ], [
            'name.min' => 'Minimal 3 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'peran.required' => 'Pilih minimal satu peran.',
            'peran.min' => 'Pilih minimal satu peran.',
        ]);
    }

    private function peranStaf()
    {
        return Role::whereIn('code', Role::STAFF)->get()
            ->sortBy(fn (Role $r) => array_search($r->code, Role::STAFF))->values();
    }
}
