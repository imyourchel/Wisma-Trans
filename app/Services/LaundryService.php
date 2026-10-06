<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReservationRoom;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\Nomor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Laundry kiloan. Alur: diterima (menunggu ditimbang) → ditimbang dan dicuci (in_progress) → siap (ready) → diserahkan (delivered).
 *  - Tamu menginap: dijemput dari kamar dan diantar kembali; ditagihkan ke kamar atau dibayar sekarang.
 *  - Tamu luar: cucian diantar ke resepsionis dan diambil di sana; dibayar setelah ditimbang (online atau tunai).
 * Berat baru diketahui saat ditimbang, jadi nota bayar-sekarang dibuat pada tahap itu.
 */
class LaundryService
{
    /** Berat minimal yang ditagihkan, sesuai keterangan layanan "minimal 2 kg". */
    public const MIN_KG = 2;

    public function __construct(private NotaService $nota) {}

    public static function layanan(): ?Service
    {
        return Service::where('category', 'laundry')->where('is_active', true)->orderBy('id')->first();
    }

    /** Pesanan laundry baru. $kamar diisi untuk tamu menginap (baris reservasi yang sedang check-in). */
    public function terima(Customer $pemesan, ?ReservationRoom $kamar, bool $tagihKeKamar, ?float $perkiraanKg, ?string $catatan, ?User $oleh = null): ServiceOrder
    {
        $layanan = self::layanan();
        if (! $layanan || $layanan->price === null) {
            throw ValidationException::withMessages(['layanan' => 'Layanan laundry sedang tidak tersedia.']);
        }
        if ($kamar && ($kamar->status !== 'checked_in' || $kamar->reservation?->status !== 'checked_in')) {
            throw ValidationException::withMessages(['kamar' => 'Kamar ini tidak sedang ditempati.']);
        }
        $kg = max(self::MIN_KG, round((float) ($perkiraanKg ?? self::MIN_KG), 1));

        return DB::transaction(function () use ($pemesan, $kamar, $tagihKeKamar, $kg, $catatan, $oleh, $layanan) {
            $order = ServiceOrder::create([
                'order_code' => Nomor::pesanan(),
                'access_token' => ServiceOrder::tokenBaru(),
                'order_type' => $kamar ? 'in_house' : 'outside_guest',
                'customer_id' => $pemesan->id,
                'reservation_room_id' => $kamar?->id,
                // Tamu menginap: dijemput dan diantar ke kamar. Tamu luar: diantar dan diambil di resepsionis
                'serving_type' => $kamar ? 'room_service' : 'take_away',
                'payment_mode' => $kamar && $tagihKeKamar ? 'charge_to_room' : 'pay_now',
                'order_date' => now(),
                'status' => 'pending',
                'created_by' => $oleh?->id,
                'note' => $catatan,
            ]);
            // Perkiraan berat sampai cucian ditimbang
            $order->items()->create([
                'service_id' => $layanan->id,
                'quantity' => $kg,
                'unit_price' => $layanan->price,
                'subtotal' => $kg * (float) $layanan->price,
                'note' => 'Perkiraan '.self::kg($kg),
            ]);

            return $order;
        });
    }

    /** Hasil timbang: tagihan dihitung minimal MIN_KG; pesanan bayar-sekarang dibuatkan nota. */
    public function timbang(ServiceOrder $order, float $beratKg, User $oleh): ServiceOrder
    {
        if ($order->status !== 'pending') {
            throw ValidationException::withMessages(['berat' => 'Cucian ini sudah ditimbang.']);
        }
        if ($beratKg <= 0 || $beratKg > 100) {
            throw ValidationException::withMessages(['berat' => 'Isi berat antara 0,1 dan 100 kg.']);
        }
        $berat = round($beratKg, 1);
        $ditagih = max(self::MIN_KG, $berat);

        return DB::transaction(function () use ($order, $berat, $ditagih, $oleh) {
            $item = $order->items()->with('service')->firstOrFail();
            $subtotal = $ditagih * (float) $item->unit_price;
            $item->update([
                'quantity' => $ditagih,
                'subtotal' => $subtotal,
                'note' => 'Berat '.self::kg($berat).($ditagih > $berat ? ' (dihitung minimal '.self::kg(self::MIN_KG).')' : ''),
            ]);

            if ($order->payment_mode === 'pay_now') {
                $nota = Invoice::create([
                    'invoice_number' => Nomor::nota(),
                    'invoice_type' => 'service',
                    'customer_id' => $order->customer_id,
                    'reservation_id' => $order->reservationRoom?->reservation_id,
                    'payment_term' => 'immediate',
                    'issue_date' => today()->toDateString(),
                    'status' => 'issued',
                    'issued_by' => $oleh->id,
                    'note' => 'Laundry '.$order->order_code,
                ]);
                $nota->items()->create([
                    'item_type' => 'laundry',
                    'description' => $item->service->name.' ('.self::kg($berat).')',
                    'service_date' => today()->toDateString(),
                    'quantity' => $ditagih,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $subtotal,
                ]);
                $this->nota->hitungUlang($nota);
                $order->invoice_id = $nota->id;
            }
            $order->status = 'in_progress';
            $order->save();

            return $order->fresh(['items.service', 'invoice', 'customer']);
        });
    }

    public function siap(ServiceOrder $order): void
    {
        if ($order->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => 'Cucian belum ditimbang atau sudah siap.']);
        }
        $order->update(['status' => 'ready']);
    }

    /** Serahkan cucian: pesanan bayar-sekarang harus sudah lunas. */
    public function serahkan(ServiceOrder $order): void
    {
        if ($order->status !== 'ready') {
            throw ValidationException::withMessages(['status' => 'Cucian belum siap.']);
        }
        if ($order->payment_mode === 'pay_now' && ! $order->sudahDibayar()) {
            throw ValidationException::withMessages(['status' => 'Tagihan laundry belum dibayar. Catat pembayaran tunai dulu.']);
        }
        $order->update(['status' => 'delivered']);
    }

    /** Pembayaran tunai di resepsionis untuk laundry bayar-sekarang. */
    public function bayarTunai(ServiceOrder $order, User $oleh): void
    {
        $nota = $order->invoice;
        if (! $nota || $order->sudahDibayar() || $nota->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Tidak ada tagihan yang perlu dibayar.']);
        }
        DB::transaction(function () use ($nota, $oleh, $order) {
            Payment::create([
                'payment_code' => Nomor::pembayaran(),
                'invoice_id' => $nota->id,
                'transaction_type' => 'payment',
                'payment_purpose' => 'service',
                'amount' => $nota->sisa(),
                'method' => 'cash',
                'channel' => 'manual',
                'status' => 'success',
                'paid_at' => now(),
                'received_by' => $oleh->id,
                'note' => 'Laundry '.$order->order_code,
            ]);
            $nota->paid_amount = $nota->total_amount;
            $this->nota->hitungUlang($nota);
        });
    }

    public function batalkan(ServiceOrder $order, string $alasan): void
    {
        if ($order->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Cucian yang sudah ditimbang tidak bisa dibatalkan.']);
        }
        $order->update(['status' => 'cancelled', 'note' => mb_substr(trim(($order->note ? $order->note."\n" : '').'Dibatalkan: '.$alasan), 0, 255)]);
    }

    /** 1.5 menjadi "1,5 kg". */
    public static function kg(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',').' kg';
    }
}
