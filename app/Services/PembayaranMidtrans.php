<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\ServiceOrder;
use App\Support\Nomor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;
use Throwable;

/**
 * Pembayaran pesanan makanan lewat Midtrans Snap.
 *
 * Setiap percobaan bayar memakai order_id Midtrans sendiri, misalnya WT-ORD-202609-0132-K3J9,
 * karena order_id yang sudah kedaluwarsa tidak dapat dipakai lagi. Status diterapkan dari
 * notifikasi Midtrans maupun dari pengecekan langsung, dan aman diterapkan berulang kali.
 */
class PembayaranMidtrans
{
    public function __construct()
    {
        Config::$serverKey = (string) config('services.midtrans.server_key');
        Config::$clientKey = (string) config('services.midtrans.client_key');
        Config::$isProduction = (bool) config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    public static function siap(): bool
    {
        return filled(config('services.midtrans.server_key')) && filled(config('services.midtrans.client_key'));
    }

    public static function urlSnapJs(): string
    {
        return config('services.midtrans.is_production')
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }

    /** Token Snap untuk pesanan. Token yang masih berlaku dipakai ulang agar tidak membuat transaksi ganda. */
    public function tokenSnap(ServiceOrder $order): string
    {
        $cache = "midtrans.snap.{$order->id}";
        if ($simpan = Cache::get($cache)) {
            return $simpan['token'];
        }

        $order->loadMissing('items.service', 'customer', 'invoice');
        $idMidtrans = 'WT-'.str_replace('/', '', substr($order->order_code, 0, 11)).'-'.substr($order->order_code, -4).'-'.Str::upper(Str::random(4));
        $menit = (int) config('services.midtrans.batas_bayar_menit', 60);

        $token = Snap::getSnapToken([
            'transaction_details' => [
                'order_id' => $idMidtrans,
                'gross_amount' => (int) $order->invoice->total_amount,
            ],
            'item_details' => $order->items->map(fn ($i) => [
                'id' => $i->service->code,
                'price' => (int) $i->unit_price,
                'quantity' => (int) $i->quantity,
                'name' => Str::limit($i->service->name, 50, ''),
            ])->values()->all(),
            'customer_details' => array_filter([
                'first_name' => Str::limit($order->customer->full_name, 50, ''),
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
            ]),
            'expiry' => ['unit' => 'minute', 'duration' => $menit],
            'callbacks' => ['finish' => $order->urlStatus()],
        ]);

        // Simpan sedikit lebih singkat dari masa berlaku token di Midtrans
        Cache::put($cache, ['token' => $token, 'order_id' => $idMidtrans], now()->addMinutes($menit - 5));
        Cache::put("midtrans.percobaan.{$order->id}", array_values(array_unique([
            ...Cache::get("midtrans.percobaan.{$order->id}", []), $idMidtrans,
        ])), now()->addDays(2));

        return $token;
    }

    /** Tanyakan status ke Midtrans untuk semua percobaan bayar pesanan ini (cadangan bila notifikasi tidak sampai). */
    public function sinkronkan(ServiceOrder $order): void
    {
        if ($order->sudahDibayar() || ! self::siap()) {
            return;
        }

        foreach (Cache::get("midtrans.percobaan.{$order->id}", []) as $idMidtrans) {
            try {
                $this->terapkan((array) Transaction::status($idMidtrans));
            } catch (Throwable $e) {
                // 404 = tamu belum memilih metode bayar; transaksinya belum ada di Midtrans
                if (! str_contains($e->getMessage(), '404')) {
                    Log::warning('Cek status Midtrans gagal', ['order_id' => $idMidtrans, 'pesan' => $e->getMessage()]);
                }
            }
        }
    }

    /** Terapkan status transaksi Midtrans ke pembayaran, nota, dan pesanan. */
    public function terapkan(array $st): ?ServiceOrder
    {
        if (! preg_match('/^WT-ORD(\d{4})(\d{2})-(\d{4})-/', $st['order_id'] ?? '', $m)) {
            return null;
        }
        $order = ServiceOrder::with('invoice')->where('order_code', "ORD/{$m[1]}/{$m[2]}/{$m[3]}")->first();
        if (! $order || ! $order->invoice) {
            return null;
        }

        $status = $st['transaction_status'] ?? '';
        $berhasil = $status === 'settlement' || ($status === 'capture' && ($st['fraud_status'] ?? 'accept') === 'accept');
        $hasil = match (true) {
            $berhasil => 'success',
            $status === 'pending' => 'pending',
            $status === 'expire' => 'expired',
            default => 'failed',
        };

        DB::transaction(function () use ($order, $st, $hasil, $status) {
            $bayar = Payment::where('midtrans_order_id', $st['order_id'])->lockForUpdate()->first();
            // Pembayaran yang sudah berhasil tidak diubah lagi oleh notifikasi yang datang terlambat
            if ($bayar?->status === 'success') {
                return;
            }

            $bayar ??= new Payment([
                'payment_code' => Nomor::pembayaran(),
                'invoice_id' => $order->invoice_id,
                'transaction_type' => 'payment',
                'payment_purpose' => 'service',
                'channel' => 'online',
                'midtrans_order_id' => $st['order_id'],
            ]);
            $bayar->fill([
                'amount' => (float) ($st['gross_amount'] ?? $order->invoice->total_amount),
                'method' => self::metode($st['payment_type'] ?? ''),
                'status' => $hasil,
                'midtrans_transaction_id' => $st['transaction_id'] ?? null,
                'gateway_response' => json_encode($st),
                'paid_at' => $hasil === 'success' ? ($st['settlement_time'] ?? $st['transaction_time'] ?? now()) : null,
            ])->save();

            if ($hasil === 'success') {
                $order->invoice->update(['paid_amount' => $order->invoice->total_amount, 'status' => 'paid']);
            } elseif ($status === 'expire' && $order->status === 'pending' && ! $order->sudahDibayar()) {
                // Waktu bayar habis: pesanan dibatalkan agar tidak masuk antrean dapur
                $order->update(['status' => 'cancelled']);
                $order->invoice->update(['status' => 'cancelled']);
                Cache::forget("midtrans.snap.{$order->id}");
            }
        });

        return $order->fresh('invoice');
    }

    private static function metode(string $tipe): string
    {
        return match ($tipe) {
            'qris', 'gopay', 'shopeepay', 'other_qris' => 'qris',
            'bank_transfer', 'echannel', 'permata', 'bca_va', 'bni_va', 'bri_va' => 'virtual_account',
            'credit_card' => 'credit_card',
            'cstore' => 'retail_outlet',
            default => 'bank_transfer',
        };
    }
}
