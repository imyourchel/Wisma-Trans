<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat akun tamu. Pendaftaran mandiri selalu berperan customer;
 * akun staf hanya dibuat Admin.
 */
class RegisterCustomer
{
    /**
     * @param  array{name: string, email: string, phone: string, password?: string|null, email_verified?: bool}  $data
     * @return array{0: User, 1: int} akun baru dan jumlah pesanan lama yang ikut terhubung
     */
    public function handle(array $data, string $errorBag = 'default'): array
    {
        // Pelanggan yang pernah memesan tanpa akun dikenali dari nomor teleponnya
        $existing = Customer::withTrashed()->where('phone', $data['phone'])->first();
        if ($existing?->user_id) {
            throw ValidationException::withMessages([
                'phone' => 'Sudah dipakai akun lain. Silakan masuk.',
            ])->errorBag($errorBag);
        }

        $user = DB::transaction(function () use ($data, $existing) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'] ?? null,
                'is_active' => true,
            ]);
            if ($data['email_verified'] ?? false) {
                $user->email_verified_at = now();
            }
            $user->save();

            $user->roles()->attach(Role::where('code', Role::CUSTOMER)->value('id'), ['assigned_at' => now()]);

            if ($existing) {
                $existing->restore();
                $existing->update(['user_id' => $user->id, 'email' => $existing->email ?? $data['email']]);
            } else {
                Customer::create([
                    'user_id' => $user->id,
                    'full_name' => $data['name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                ]);
            }

            return $user;
        });

        return [$user, $existing?->reservations()->count() ?? 0];
    }

    public static function message(int $linkedOrders): string
    {
        $message = 'Akun dibuat.';
        if ($linkedOrders > 0) {
            $message .= " {$linkedOrders} pesanan sebelumnya dengan nomor ini sudah terhubung ke akun Anda.";
        }

        return $message;
    }
}
