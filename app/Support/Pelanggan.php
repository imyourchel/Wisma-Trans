<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Data pelanggan dari isian tamu (nama, nomor WhatsApp, email).
 * Tamu dikenali dari nomor HP; akun yang sedang masuk memakai data pelanggannya sendiri.
 * Akun yang belum punya data pelanggan ditautkan ke pelanggan bernomor HP itu, supaya pesanannya tampil
 * di halaman Pesanan saya. Nomor milik akun lain ditolak: pesanan itu akan tampil di akun orang lain.
 */
final class Pelanggan
{
    /**
     * @param  array{name: string, phone: string, email?: ?string}  $data  sudah dinormalisasi dan divalidasi
     */
    public static function dariIsian(array $data, ?User $user, string $kolomHp = 'phone'): Customer
    {
        if ($punya = $user?->customer) {
            return $punya;
        }

        $customer = Customer::withTrashed()->firstOrNew(['phone' => $data['phone']]);
        if ($user && $customer->user_id !== null && $customer->user_id !== $user->id) {
            throw ValidationException::withMessages([$kolomHp => 'Nomor ini terdaftar di akun lain. Gunakan nomor WhatsApp Anda sendiri.']);
        }
        if ($user && $customer->user_id === null) {
            $customer->user_id = $user->id;
        }
        if (! $customer->exists) {
            $customer->fill(['full_name' => $data['name'], 'email' => $data['email'] ?? null, 'is_active' => true]);
        } elseif (! $customer->email && ! empty($data['email'])) {
            $customer->email = $data['email'];
        }
        $customer->save();
        if ($customer->trashed()) {
            $customer->restore();
        }

        return $customer;
    }
}
