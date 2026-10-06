<?php

namespace App\Services;

use App\Enums\RoomStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ketersediaan kamar untuk rentang menginap.
 *  - sisa(): per tipe. Tamu online memesan tipe, bukan nomor kamar, jadi yang dihitung jumlah kamar siap jual
 *    tipe itu dikurangi pemakaian tertinggi di salah satu malamnya (termasuk pesanan yang belum punya nomor kamar).
 *  - kamarBebas(): per nomor kamar, untuk reservasi langsung, persetujuan instansi, check-in, dan pindah kamar.
 * Kamar nonaktif dan kamar berstatus perbaikan (maintenance) tidak dihitung tersedia.
 */
class KetersediaanKamar
{
    /** Status reservasi yang masih menahan kamar. Menunggu bayar hanya menahan selama batas uang muka belum lewat. */
    private const RESERVASI_MENAHAN = ['waiting_approval', 'confirmed', 'checked_in'];

    /** Status baris kamar yang masih memakai kamar (moved = sudah dipindah ke baris baru). */
    private const KAMAR_MENAHAN = ['booked', 'checked_in'];

    /**
     * @param  list<int>|null  $tipe  batasi ke tipe tertentu; null = semua tipe
     * @param  int|null  $kecuali  id reservasi yang tidak dihitung (misalnya saat reservasi itu sendiri diubah tanggalnya)
     * @return array<int, int> id tipe => sisa kamar yang bisa dipesan untuk semua malam di rentang itu
     */
    public function sisa(CarbonInterface $masuk, CarbonInterface $keluar, ?array $tipe = null, ?int $kecuali = null): array
    {
        $total = $this->kamarSiapJual($tipe)
            ->groupBy('room_type_id')
            ->selectRaw('room_type_id, COUNT(*) AS n')
            ->pluck('n', 'room_type_id')
            ->map(fn ($n) => (int) $n);

        $pakai = $this->pemakaian($masuk, $keluar, $kecuali)
            ->when($tipe !== null, fn ($q) => $q->whereIn('rr.room_type_id', $tipe))
            ->get(['rr.room_type_id', 'rr.check_in_date', 'rr.check_out_date'])
            ->groupBy('room_type_id');

        $malam = [];
        for ($d = $masuk->copy(); $d->lt($keluar); $d = $d->addDay()) {
            $malam[] = $d->toDateString();
        }

        return $total->map(function (int $jumlah, $idTipe) use ($pakai, $malam) {
            $baris = $pakai->get($idTipe, collect());
            $tertinggi = collect($malam)->max(fn ($t) => $baris->filter(fn ($b) => $b->check_in_date <= $t && $b->check_out_date > $t)->count()) ?? 0;

            return max(0, $jumlah - $tertinggi);
        })->all();
    }

    /**
     * Kamar yang tidak dipakai reservasi lain pada rentang itu, urut nomor.
     *
     * @param  list<int>|null  $tipe
     * @param  int|null  $kecuali  id reservasi yang barisnya diabaikan
     * @return Collection<int, object{id: int, room_number: string, room_type_id: int, status: string, floor: int}>
     */
    public function kamarBebas(CarbonInterface $masuk, CarbonInterface $keluar, ?array $tipe = null, ?int $kecuali = null): Collection
    {
        $terpakai = $this->pemakaian($masuk, $keluar, $kecuali)->whereNotNull('rr.room_id')->pluck('rr.room_id');

        return $this->kamarSiapJual($tipe)
            ->whereNotIn('id', $terpakai)
            ->orderBy('floor')->orderByRaw('CHAR_LENGTH(room_number)')->orderBy('room_number')
            ->get(['id', 'room_number', 'room_type_id', 'status', 'floor']);
    }

    /** Apakah satu kamar bebas pada rentang itu (baris reservasi $kecuali tidak dihitung). */
    public function bebas(int $roomId, CarbonInterface $masuk, CarbonInterface $keluar, ?int $kecuali = null): bool
    {
        return ! $this->pemakaian($masuk, $keluar, $kecuali)->where('rr.room_id', $roomId)->exists();
    }

    private function kamarSiapJual(?array $tipe)
    {
        return DB::table('rooms')->where('is_active', true)
            ->where('status', '!=', RoomStatus::Maintenance->value)
            ->when($tipe !== null, fn ($q) => $q->whereIn('room_type_id', $tipe));
    }

    /** Baris kamar yang menahan kamar dan beririsan dengan rentang: menginap sebelum tanggal keluar dan keluar setelah tanggal masuk. */
    private function pemakaian(CarbonInterface $masuk, CarbonInterface $keluar, ?int $kecuali)
    {
        return DB::table('reservation_rooms as rr')
            ->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->whereIn('rr.status', self::KAMAR_MENAHAN)
            ->where(fn ($q) => $q->whereIn('r.status', self::RESERVASI_MENAHAN)
                ->orWhere(fn ($q) => $q->where('r.status', 'pending_payment')
                    ->where(fn ($q) => $q->whereNull('r.dp_due_at')->orWhere('r.dp_due_at', '>', now()))))
            ->where('rr.check_in_date', '<', $keluar->toDateString())
            ->where('rr.check_out_date', '>', $masuk->toDateString())
            ->when($kecuali !== null, fn ($q) => $q->where('r.id', '!=', $kecuali));
    }
}
