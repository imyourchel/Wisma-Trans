<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\TokenAkses;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Kode kamar = token akses reservasi (LK7Q-2M9X). Tamu menginap memakainya untuk memesan makanan (Resto) dan laundry
 * ke kamarnya. Hasil cocok disimpan di session (id reservasi) sehingga satu kali cocok berlaku untuk Resto dan Laundry
 * selama sesi; setiap pesanan tetap memeriksa ulang bahwa reservasinya masih check-in.
 */
class KodeKamar
{
    public const SESI = 'kode_kamar';

    public const TIDAK_COCOK = 'Kode kamar tidak cocok.';

    public const BUKAN_MENGINAP = 'Kode ini hanya berlaku selama Anda menginap.';

    public const TIDAK_BOLEH = 'Kamar ini tidak bisa dipakai untuk memesan. Cocokkan ulang kode kamar.';

    /** Batas percobaan per IP per menit. */
    public const BATAS = 10;

    /**
     * Cocokkan isian tamu ("lk7q-2m9x", "LK7Q2M9X"), lalu simpan di session.
     *
     * @throws ValidationException kunci "kode_kamar"; status 429 bila terlalu banyak percobaan
     */
    public function cocokkan(string $kode, string $ip): Reservation
    {
        $kunci = 'kode-kamar:'.$ip;
        if (RateLimiter::tooManyAttempts($kunci, self::BATAS)) {
            throw ValidationException::withMessages(['kode_kamar' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.'])->status(429);
        }
        RateLimiter::hit($kunci, 60);

        $token = TokenAkses::rapikan($kode);
        $r = strlen($token) === 8 ? Reservation::where('access_token', $token)->first() : null;
        if (! $r || ! hash_equals((string) $r->access_token, $token)) {
            throw ValidationException::withMessages(['kode_kamar' => self::TIDAK_COCOK]);
        }
        if ($r->status !== 'checked_in') {
            throw ValidationException::withMessages(['kode_kamar' => self::BUKAN_MENGINAP]);
        }
        session()->put(self::SESI, $r->id);

        return $r;
    }

    public function lupakan(): void
    {
        session()->forget(self::SESI);
    }

    /** Id reservasi hasil cocok yang tersimpan di session (belum tentu masih check-in). */
    public function reservasiSesi(): ?int
    {
        $id = session(self::SESI);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Kamar yang sedang ditempati: dari reservasi hasil cocok, ditambah reservasi milik akun yang masuk.
     * Hanya id baris, nomor kamar, dan tanggal keluar; tanpa nama tamu.
     *
     * @return Collection<int, array{id: int, nomor: string, keluar: string}>
     */
    public function kamarUntuk(?User $user, ?int $reservasiId): Collection
    {
        if (! $reservasiId && ! $user) {
            return collect();
        }

        return Reservation::with(['rooms' => fn ($q) => $q->where('status', 'checked_in')->with('room')])
            ->where('status', 'checked_in')
            ->where(fn ($q) => $q->whereKey($reservasiId ?? 0)
                ->when($user, fn ($q) => $q->orWhereHas('customer', fn ($c) => $c->where('user_id', $user->id))))
            ->get()
            ->flatMap(fn (Reservation $r) => $r->rooms->map(fn (ReservationRoom $b) => [
                'id' => $b->id,
                'nomor' => (string) $b->room?->room_number,
                'keluar' => $b->check_out_date->translatedFormat('j M'),
            ]))
            ->unique('id')->sortBy('nomor')->values();
    }

    /**
     * Pemeriksaan ulang di server saat pesanan disimpan: baris kamar dan reservasinya sedang check-in,
     * dan reservasi itu hasil cocok di session ini atau milik akun yang masuk.
     */
    public function bolehMemakai(?User $user, ReservationRoom $rr): bool
    {
        $r = $rr->reservation;
        if ($rr->status !== 'checked_in' || ! $r || $r->status !== 'checked_in') {
            return false;
        }

        return $this->reservasiSesi() === $r->id || ($user !== null && $r->customer?->user_id === $user->id);
    }
}
