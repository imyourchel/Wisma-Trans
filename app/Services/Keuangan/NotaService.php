<?php

namespace App\Services\Keuangan;

use App\Models\BillingReminder;
use App\Models\BillingStatement;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Perhitungan nota:
 *   subtotal baris = jumlah × harga satuan − diskon baris
 *   subtotal nota  = Σ subtotal baris
 *   total          = subtotal nota − diskon nota (persen dari subtotal, atau nominal)
 * Status nota mengikuti jumlah terbayar; nota kredit yang lewat jatuh tempo dan masih bersisa berstatus overdue.
 * Nota yang dibatalkan tidak diubah. Nota yang lunas atau batal membatalkan pengingat penagihan yang masih menunggu.
 */
class NotaService
{
    /**
     * Susun ulang baris kamar nota menginap dari baris reservasi (satu baris per kamar), lalu hitung ulang total.
     * $mulai/$akhir membatasi malam yang ditagih (malam tanggal d = menginap dari d ke d+1, inklusif), dipakai untuk
     * nota periode menginap panjang instansi dan nota check-out setelah periode terakhir.
     */
    public function susunKamar(Invoice $nota, Reservation $reservasi, ?CarbonInterface $mulai = null, ?CarbonInterface $akhir = null): Invoice
    {
        $reservasi->loadMissing('rooms.room', 'rooms.roomType');
        $nota->items()->where('item_type', 'room')->delete();
        $terbatas = $mulai !== null || $akhir !== null;

        $reservasi->rooms
            ->whereIn('status', ['booked', 'checked_in', 'checked_out', 'moved'])
            ->map(fn (ReservationRoom $r) => $terbatas ? self::potong($r, $mulai, $akhir) : $r)
            ->filter(fn (?ReservationRoom $r) => $r && $r->malam() > 0)
            ->sortBy(fn (ReservationRoom $r) => [$r->check_in_date->toDateString(), $r->id])
            ->each(fn (ReservationRoom $r) => $nota->items()->create([
                'item_type' => 'room',
                'description' => ($r->room ? 'Kamar '.$r->room->room_number : $r->roomType->name).', '.$r->malam().' malam'
                    .($terbatas ? ' ('.$r->rentang().')' : ''),
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

    /** Salinan baris reservasi yang dipotong ke malam $mulai..$akhir; null bila tidak ada malam di rentang itu. */
    public static function potong(ReservationRoom $r, ?CarbonInterface $mulai, ?CarbonInterface $akhir): ?ReservationRoom
    {
        $masuk = $mulai ? $r->check_in_date->copy()->max(Carbon::parse($mulai->toDateString())) : $r->check_in_date->copy();
        $keluar = $akhir ? $r->check_out_date->copy()->min(Carbon::parse($akhir->toDateString())->addDay()) : $r->check_out_date->copy();
        if (! $keluar->gt($masuk)) {
            return null;
        }
        $salinan = clone $r;
        $salinan->check_in_date = $masuk->startOfDay();
        $salinan->check_out_date = $keluar->startOfDay();

        return $salinan;
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
                // Kredit lewat jatuh tempo tetap overdue walau dibayar sebagian (penyaring Nota dan kartu Dashboard)
                $nota->payment_term === 'credit' && $nota->due_date?->lt(today()) && $total > $dibayar => 'overdue',
                $dibayar > 0 => 'partially_paid',
                $nota->status === 'open' => 'open',
                default => 'issued',
            };
        }
        $nota->save();
        if (in_array($nota->status, ['paid', 'cancelled'], true)) {
            BillingReminder::batalkanUntuk($nota);
        }

        return $nota;
    }

    /** Total dan status tagihan bulanan dari nota anggotanya dan pembayaran statement. Draft dan batal tidak berubah status. */
    public function hitungTagihanBulanan(BillingStatement $s): BillingStatement
    {
        $total = (float) $s->invoices()->where('status', '!=', 'cancelled')->sum('total_amount');
        $dibayar = (float) $s->paid_amount;
        $s->total_amount = $total;
        if (! in_array($s->status, ['draft', 'cancelled'], true)) {
            $s->status = match (true) {
                $total > 0 && $dibayar >= $total => 'paid',
                $s->due_date?->lt(today()) && $total > $dibayar => 'overdue',
                $dibayar > 0 => 'partially_paid',
                default => 'issued',
            };
        }
        $s->save();
        if (in_array($s->status, ['paid', 'cancelled'], true)) {
            BillingReminder::batalkanUntuk($s);
        }

        return $s;
    }
}
