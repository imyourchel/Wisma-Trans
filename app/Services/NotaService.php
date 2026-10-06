<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\ReservationRoom;

/**
 * Perhitungan nota:
 *   subtotal baris = jumlah × harga satuan − diskon baris
 *   subtotal nota  = Σ subtotal baris
 *   total          = subtotal nota − diskon nota (persen dari subtotal, atau nominal)
 * Status nota mengikuti jumlah terbayar; nota yang dibatalkan tidak diubah.
 */
class NotaService
{
    /** Susun ulang baris kamar nota menginap dari baris reservasi (satu baris per kamar), lalu hitung ulang total. */
    public function susunKamar(Invoice $nota, Reservation $reservasi): Invoice
    {
        $reservasi->loadMissing('rooms.room', 'rooms.roomType');
        $nota->items()->where('item_type', 'room')->delete();

        $reservasi->rooms
            ->whereIn('status', ['booked', 'checked_in', 'checked_out', 'moved'])
            ->filter(fn (ReservationRoom $r) => $r->malam() > 0)
            ->sortBy(fn (ReservationRoom $r) => [$r->check_in_date->toDateString(), $r->id])
            ->each(fn (ReservationRoom $r) => $nota->items()->create([
                'item_type' => 'room',
                'description' => ($r->room ? 'Kamar '.$r->room->room_number : $r->roomType->name).', '.$r->malam().' malam',
                'service_date' => $r->check_in_date->toDateString(),
                'quantity' => $r->malam(),
                'unit_price' => $r->price_per_night,
                'discount_type' => $r->discount_type,
                'discount_value' => $r->discount_value,
                'discount_amount' => $r->potongan(),
                'subtotal' => $r->biaya(),
            ]));

        return $this->hitungUlang($nota);
    }

    public function hitungUlang(Invoice $nota): Invoice
    {
        $subtotal = (float) $nota->items()->sum('subtotal');
        $diskon = match ($nota->discount_type) {
            'percent' => round($subtotal * (float) $nota->discount_value / 100),
            'amount' => min($subtotal, (float) $nota->discount_value),
            default => 0.0,
        };
        $total = $subtotal - $diskon;
        $dibayar = (float) $nota->paid_amount;

        $nota->fill(['subtotal' => $subtotal, 'discount_amount' => $diskon, 'total_amount' => $total]);
        if ($nota->status !== 'cancelled') {
            $nota->status = match (true) {
                $total > 0 && $dibayar >= $total => 'paid',
                $dibayar > 0 => 'partially_paid',
                $nota->status === 'open' => 'open',
                default => 'issued',
            };
        }
        $nota->save();

        return $nota;
    }
}
