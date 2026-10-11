<?php

namespace App\Services;

use App\Contracts\BayarOnline;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\Nomor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\CoreApi;
use Midtrans\Transaction;
use Throwable;

/**
 * Pembayaran online lewat Midtrans Core API: pesanan makanan (lunas) dan uang muka reservasi kamar.
 * Tamu memilih metode di halaman pesanan, lalu nomor virtual account atau kode QRIS ditampilkan
 * dengan tampilan Wisma Trans sendiri.
 *
 * Setiap percobaan bayar memakai order_id Midtrans sendiri, misalnya WT-ORD202609-0132-K3J9 atau
 * WT-RSV0016-DP-K3J9, karena order_id yang sudah dipakai tidak dapat dipakai lagi. Semua percobaan berakhir
 * pada batas bayar yang sama. Status diterapkan dari notifikasi Midtrans maupun dari pengecekan langsung,
 * dan aman diterapkan berulang kali.
 */
class PembayaranMidtrans
{
    /** Penanda pada pembayaran asal yang membuat kelebihan bayar (bayar ganda, pesanan batal, QR lama). */
    public const CATATAN_PERLU_REFUND = 'Pembayaran ganda/pesanan batal, perlu pengembalian dana';

    /** Jumlah pengembalian dana yang masih menunggu (refund pending), sebatas nota yang boleh dilihat $user. */
    public static function jumlahPerluRefund(?User $user = null): int
    {
        return Payment::where('transaction_type', 'refund')->where('status', 'pending')
            ->when($user, fn ($q) => $q->whereHas('invoice', fn ($n) => $n->terlihatOleh($user)))->count();
    }

    /** Metode yang ditawarkan: [nama, keterangan singkat]. */
    public const METODE = [
        'qris' => ['QRIS', 'GoPay, OVO, DANA, ShopeePay, LinkAja, dan m-banking'],
        'bca' => ['BCA Virtual Account', 'BCA mobile, KlikBCA, atau ATM BCA'],
        'bni' => ['BNI Virtual Account', 'BNI Mobile Banking atau ATM BNI'],
        'bri' => ['BRI Virtual Account', 'BRImo atau ATM BRI'],
        'mandiri' => ['Mandiri Bill Payment', "Livin' by Mandiri atau ATM Mandiri"],
        'permata' => ['Permata Virtual Account', 'PermataMobile X, ATM, atau bank lain'],
    ];

    public function __construct()
    {
        Config::$serverKey = (string) config('services.midtrans.server_key');
        Config::$clientKey = (string) config('services.midtrans.client_key');
        Config::$isProduction = (bool) config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;
        // Batas waktu agar halaman tidak menggantung saat Midtrans lambat menjawab
        Config::$curlOptions = [CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => []];
    }

    public static function siap(): bool
    {
        return filled(config('services.midtrans.server_key')) && filled(config('services.midtrans.client_key'));
    }

    /**
     * Kunci cache per tagihan memakai token akses (acak), bukan id atau kode,
     * agar tidak tertukar dengan data lain yang kebetulan bernomor sama, misalnya di database salinan.
     */
    public static function kunci(BayarOnline $x, string $jenis): string
    {
        return "midtrans.{$jenis}.{$x->kunciBayar()}";
    }

    /** Tagihan yang sedang berjalan (nomor VA atau QR), atau null bila tamu belum memilih metode. */
    public function tagihanAktif(BayarOnline $x): ?array
    {
        return Cache::get(self::kunci($x, 'tagihan'));
    }

    /**
     * Buat tagihan untuk metode pilihan. Tagihan dengan metode yang sama dipakai ulang; memilih metode lain
     * mengakhiri tagihan sebelumnya di Midtrans agar tamu tidak membayar dua kali.
     *
     * @return array{metode: string, order_id: string, jumlah: int, nomor: ?string, kode_perusahaan: ?string, qr: ?string}
     */
    public function buatTagihan(BayarOnline $x, string $metode): array
    {
        $lama = $this->tagihanAktif($x);
        // Dipakai ulang hanya bila jumlahnya masih sama; sisa nota bisa berubah setelah pembayaran lain
        if ($lama && $lama['metode'] === $metode && $lama['jumlah'] === $x->jumlahBayar()) {
            return $lama;
        }

        $idMidtrans = $x->awalanMidtrans().'-'.Str::upper(Str::random(4));
        $jumlah = $x->jumlahBayar();
        $sisaMenit = max(1, (int) ceil(now()->diffInSeconds($x->batasBayar(), false) / 60));
        $pembayar = $x->pembayar();

        $hasil = (array) CoreApi::charge([
            ...$this->parameterMetode($metode, $x),
            'transaction_details' => ['order_id' => $idMidtrans, 'gross_amount' => $jumlah],
            'item_details' => $x->rincianBayar(),
            'customer_details' => array_filter([
                'first_name' => Str::limit((string) $pembayar?->full_name, 50, ''),
                'phone' => $pembayar?->phone,
                'email' => $pembayar?->email,
            ]),
            'custom_expiry' => ['order_time' => now()->format('Y-m-d H:i:s O'), 'expiry_duration' => $sisaMenit, 'unit' => 'minute'],
        ]);

        $tagihan = ['metode' => $metode, 'order_id' => $idMidtrans, 'jumlah' => $jumlah, ...self::bacaHasil($hasil)];
        Cache::put(self::kunci($x, 'tagihan'), $tagihan, $x->batasBayar());
        $this->catatPercobaan($x, $idMidtrans, $jumlah);

        if ($lama) {
            $this->akhiri($lama['order_id']);
        }

        return $tagihan;
    }

    /**
     * Ingat setiap percobaan bayar beserta jumlahnya. Status dari Midtrans dicocokkan dengan jumlah percobaan itu,
     * bukan dengan sisa saat ini, karena sisa nota bisa berubah setelah tamu terlanjur membayar QR lama.
     */
    public function catatPercobaan(BayarOnline $x, string $idMidtrans, int $jumlah): void
    {
        $sampai = $x->batasBayar()->copy()->addDays(2);
        Cache::put(self::kunci($x, 'percobaan'), array_values(array_unique([
            ...Cache::get(self::kunci($x, 'percobaan'), []), $idMidtrans,
        ])), $sampai);
        Cache::put(self::kunci($x, 'jumlah'), [...Cache::get(self::kunci($x, 'jumlah'), []), $idMidtrans => $jumlah], $sampai);
    }

    /** Akhiri tagihan yang sedang berjalan, misalnya saat reservasi dibatalkan sebelum dibayar. */
    public function akhiriTagihan(BayarOnline $x): void
    {
        if ($lama = $this->tagihanAktif($x)) {
            Cache::forget(self::kunci($x, 'tagihan'));
            $this->akhiri($lama['order_id']);
        }
    }

    private function akhiri(string $idMidtrans): void
    {
        try {
            Transaction::expire($idMidtrans);
        } catch (Throwable $e) {
            Log::warning('Tagihan Midtrans lama gagal diakhiri', ['order_id' => $idMidtrans, 'pesan' => $e->getMessage()]);
        }
    }

    private function parameterMetode(string $metode, BayarOnline $x): array
    {
        return match ($metode) {
            'qris' => ['payment_type' => 'qris', 'qris' => ['acquirer' => 'gopay']],
            'mandiri' => ['payment_type' => 'echannel', 'echannel' => ['bill_info1' => 'Pembayaran', 'bill_info2' => Str::limit($x->keteranganBayar(), 30, '')]],
            default => ['payment_type' => 'bank_transfer', 'bank_transfer' => ['bank' => $metode]],
        };
    }

    /** Ambil nomor bayar dari jawaban charge Midtrans. */
    private static function bacaHasil(array $h): array
    {
        $qr = collect($h['actions'] ?? [])->map(fn ($a) => (array) $a)->firstWhere('name', 'generate-qr-code');

        return [
            'nomor' => $h['bill_key'] ?? $h['permata_va_number'] ?? ((array) ($h['va_numbers'][0] ?? []))['va_number'] ?? null,
            'kode_perusahaan' => $h['biller_code'] ?? null,
            'qr' => $qr['url'] ?? null,
        ];
    }

    /** Tanyakan status ke Midtrans untuk semua percobaan bayar (cadangan bila notifikasi tidak sampai). */
    public function sinkronkan(BayarOnline $x): void
    {
        if ($x->sudahDibayar() || ! self::siap()) {
            return;
        }

        foreach (Cache::get(self::kunci($x, 'percobaan'), []) as $idMidtrans) {
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

    /** Terapkan status transaksi Midtrans ke pembayaran, nota, dan pesanan atau reservasinya. */
    public function terapkan(array $st): ?Model
    {
        $id = $st['order_id'] ?? '';
        $x = match (true) {
            (bool) preg_match('/^WT-ORD(\d{4})(\d{2})-(\d{4})-/', $id, $m) => ServiceOrder::with('invoice')->where('order_code', "ORD/{$m[1]}/{$m[2]}/{$m[3]}")->first(),
            (bool) preg_match('/^WT-RSV(\d{4,})-DP-/', $id, $m) => Reservation::with('stayInvoice')->where('reservation_code', 'RSV-'.$m[1])->first(),
            (bool) preg_match('/^WT-INV(\d{4})(\d{2})(\d{4})-/', $id, $m) => Invoice::where('invoice_number', "INV/{$m[1]}/{$m[2]}/{$m[3]}")->first(),
            default => null,
        };
        if (! $x || ! $x->notaBayar()) {
            return null;
        }
        // Jumlah harus sama dengan tagihan; transaksi yang jumlahnya lain bukan milik data ini.
        // Nota: dicocokkan dengan jumlah percobaan yang tercatat untuk order_id itu (sisa nota bisa sudah berubah)
        $diharapkan = $x instanceof Invoice ? (Cache::get(self::kunci($x, 'jumlah'), [])[$id] ?? null) : $x->jumlahBayar();
        if ($diharapkan === null || (isset($st['gross_amount']) && (int) round((float) $st['gross_amount']) !== $diharapkan)) {
            Log::warning('Status Midtrans diabaikan: jumlah tidak sesuai tagihan', ['order_id' => $id, 'jumlah' => $st['gross_amount'], 'tagihan' => $x->jumlahBayar()]);

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

        $baruBerhasil = DB::transaction(function () use ($x, $st, $hasil, $status, $diharapkan) {
            $nota = $x->notaBayar();
            $bayar = Payment::where('midtrans_order_id', $st['order_id'])->lockForUpdate()->first();
            // Pembayaran yang sudah berhasil tidak diubah lagi oleh notifikasi yang datang terlambat
            if ($bayar?->status === 'success') {
                return false;
            }

            $bayar ??= new Payment([
                'payment_code' => Nomor::pembayaran(),
                'invoice_id' => $nota->id,
                'transaction_type' => 'payment',
                'payment_purpose' => match (true) {
                    $x instanceof Reservation => 'down_payment',
                    $x instanceof Invoice => 'settlement',
                    default => 'service',
                },
                'channel' => 'online',
                'midtrans_order_id' => $st['order_id'],
            ]);
            $bayar->fill([
                'amount' => (float) ($st['gross_amount'] ?? $diharapkan),
                'method' => self::metode($st['payment_type'] ?? ''),
                'status' => $hasil,
                'midtrans_transaction_id' => $st['transaction_id'] ?? null,
                'gateway_response' => json_encode($st),
                'paid_at' => $hasil === 'success' ? ($st['settlement_time'] ?? $st['transaction_time'] ?? now()) : null,
            ])->save();

            return match (true) {
                $x instanceof Reservation => $this->terapkanReservasi($x, $bayar, $hasil),
                $x instanceof Invoice => $this->terapkanNota($x, $bayar, $hasil),
                default => $this->terapkanPesanan($x, $bayar, $hasil, $status),
            };
        });

        if ($baruBerhasil && $x instanceof Reservation) {
            app(NotifikasiReservasi::class)->uangMukaDiterima($x->fresh());
        }

        return $x->fresh();
    }

    private function terapkanPesanan(ServiceOrder $order, Payment $bayar, string $hasil, string $status): bool
    {
        if ($hasil === 'success') {
            // Sudah lunas lewat jalur lain (misalnya tunai di kasir) atau pesanan sudah batal: uang tetap tercatat masuk
            // (paid_amount bertambah, status nota batal tetap batal) dan kelebihannya masuk antrean pengembalian dana
            if ($order->invoice->status === 'paid' || $order->status === 'cancelled' || $order->invoice->status === 'cancelled') {
                $bayar->update(['note' => self::CATATAN_PERLU_REFUND]);
                $nota = $order->invoice;
                $nota->paid_amount = (float) $nota->paid_amount + (float) $bayar->amount;
                app(NotaService::class)->hitungUlang($nota);
                app(PembayaranService::class)->ajukanKelebihan($nota, ($order->status === 'cancelled' ? 'Pesanan batal ' : 'Pembayaran ganda ').$order->order_code.' lewat Midtrans', $bayar);
                Log::warning('Pembayaran Midtrans untuk pesanan yang sudah lunas atau batal', ['pesanan' => $order->order_code, 'order_id' => $bayar->midtrans_order_id]);

                return false;
            }
            $order->invoice->update(['paid_amount' => $order->invoice->total_amount, 'status' => 'paid']);

            return true;
        }
        if ($status === 'expire' && $order->status === 'pending' && ! $order->sudahDibayar() && $order->batasBayar()->subMinute()->isPast()) {
            // Waktu bayar habis: pesanan dibatalkan agar tidak masuk antrean dapur.
            // Tagihan yang diakhiri karena tamu ganti metode sebelum batas waktu tidak membatalkan pesanan.
            $order->update(['status' => 'cancelled']);
            $order->invoice->update(['status' => 'cancelled']);
            Cache::forget(self::kunci($order, 'tagihan'));
        }

        return false;
    }

    /**
     * Pelunasan nota lewat QRIS di meja check-out. Uang yang benar-benar masuk selalu dicatat sebesar gross_amount;
     * bila membuat nota lebih bayar (misalnya QR lama dibayar setelah FO mencatat tunai), pembayaran ditandai
     * perlu pengembalian dana dan selisihnya tampil sebagai kelebihan bayar.
     */
    private function terapkanNota(Invoice $nota, Payment $bayar, string $hasil): bool
    {
        if ($hasil !== 'success') {
            return false;
        }
        $nota = Invoice::whereKey($nota->id)->lockForUpdate()->first();
        $sisa = app(PembayaranService::class)->sisaTagihan($nota);
        $nota->paid_amount = (float) $nota->paid_amount + (float) $bayar->amount;
        app(NotaService::class)->hitungUlang($nota);
        if ((float) $bayar->amount > $sisa || $nota->status === 'cancelled') {
            $bayar->update(['note' => self::CATATAN_PERLU_REFUND]);
            app(PembayaranService::class)->ajukanKelebihan($nota, 'QRIS terbayar melebihi sisa nota '.$nota->invoice_number, $bayar);
            Log::warning('Pembayaran Midtrans membuat nota lebih bayar', ['nota' => $nota->invoice_number, 'order_id' => $bayar->midtrans_order_id]);
        }
        Cache::forget(self::kunci($nota, 'tagihan'));

        return false;
    }

    private function terapkanReservasi(Reservation $r, Payment $bayar, string $hasil): bool
    {
        if ($hasil !== 'success') {
            return false;
        }
        $nota = $r->notaBayar();
        $nota->paid_amount = (float) $nota->paid_amount + (float) $bayar->amount;
        app(NotaService::class)->hitungUlang($nota);
        Cache::forget(self::kunci($r, 'tagihan'));

        if ($r->status === 'pending_payment') {
            app(ReservasiService::class)->uangMukaDiterima($r, 'Uang muka diterima lewat Midtrans ('.(Payment::METHODS[$bayar->method] ?? $bayar->method).')');

            return true;
        }
        // Dibayar setelah reservasi dibatalkan: uang tetap tercatat, resepsionis menindaklanjuti pengembaliannya
        app(ReservasiService::class)->catat($r, $r->status, null, 'Uang muka '.number_format((float) $bayar->amount, 0, ',', '.').' diterima setelah reservasi '.$r->label().'; perlu ditindaklanjuti');

        return false;
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
