<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReservationRoom;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Setting;
use App\Models\User;
use App\Support\Nomor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pesanan makanan dan minuman di sisi staf F&B.
 * Alur dapur: pending (antre) → in_progress (dimasak) → ready (siap) → delivered (diserahkan), atau cancelled.
 *  - Pesanan bayar-sekarang baru masuk dapur setelah lunas; yang ditagihkan ke kamar langsung masuk.
 *  - Pesanan yang menunggu lebih dari fnb_max_wait_minutes sejak masuk dapur ditandai terlambat.
 */
class PesananMakananService
{
    /** Metode pembayaran yang bisa dicatat kasir. */
    public const METODE_KASIR = ['cash' => 'Tunai', 'qris' => 'QRIS', 'debit_card' => 'Kartu debit', 'bank_transfer' => 'Transfer bank'];

    public function __construct(private NotaService $nota) {}

    /** Pesanan makanan saja (tanpa laundry). */
    public static function query(): Builder
    {
        return ServiceOrder::query()->whereDoesntHave('items.service', fn ($q) => $q->where('category', 'laundry'));
    }

    /** Pesanan yang sudah boleh dikerjakan dapur. */
    public static function diDapur(): Builder
    {
        return self::query()->whereIn('status', ['pending', 'in_progress', 'ready'])
            ->where(fn ($q) => $q->where('payment_mode', 'charge_to_room')->orWhereHas('invoice', fn ($i) => $i->where('status', 'paid')));
    }

    public static function batasTunggu(): int
    {
        return max(1, (int) Setting::get('fnb_max_wait_minutes', 20));
    }

    /** Waktu pesanan mulai antre di dapur: saat lunas untuk bayar-sekarang, saat dipesan untuk tagihan kamar. */
    public static function mulaiAntre(ServiceOrder $o): Carbon
    {
        if ($o->payment_mode === 'pay_now') {
            $lunas = $o->invoice?->payments?->where('status', 'success')->where('transaction_type', 'payment')->sortBy('paid_at')->first();
            if ($lunas?->paid_at) {
                return $lunas->paid_at->copy();
            }
        }

        return $o->order_date->copy();
    }

    /** Menit sejak masuk dapur. */
    public static function menitTunggu(ServiceOrder $o): int
    {
        return (int) floor(self::mulaiAntre($o)->diffInMinutes(now(), true));
    }

    /** Belum siap padahal sudah menunggu lebih dari batas. */
    public static function terlambat(ServiceOrder $o): bool
    {
        return in_array($o->status, ['pending', 'in_progress'], true) && self::menitTunggu($o) > self::batasTunggu();
    }

    /**
     * Pesanan dari kasir. $baris: [['id' => service_id, 'qty' => int, 'harga' => ?float (menu harga pasar), 'catatan' => ?string]].
     * Tamu menginap memakai $kamar (baris reservasi yang sedang check-in); tamu luar memakai $pemesan dan selalu bayar sekarang.
     * Bayar sekarang langsung dicatat lunas dengan $metode.
     */
    public function buatDariKasir(array $baris, ?ReservationRoom $kamar, ?Customer $pemesan, string $penyajian, bool $tagihKeKamar,
        ?string $metode, ?string $catatan, User $oleh): ServiceOrder
    {
        if ($baris === []) {
            throw ValidationException::withMessages(['keranjang' => 'Pilih minimal satu menu.']);
        }
        if ($kamar && ($kamar->status !== 'checked_in' || $kamar->reservation?->status !== 'checked_in')) {
            throw ValidationException::withMessages(['kamar' => 'Kamar ini tidak sedang ditempati.']);
        }
        if (! $kamar && $penyajian === 'room_service') {
            throw ValidationException::withMessages(['penyajian' => 'Antar ke kamar hanya untuk tamu menginap.']);
        }
        $bayarSekarang = ! ($kamar && $tagihKeKamar);
        if ($bayarSekarang && ! array_key_exists((string) $metode, self::METODE_KASIR)) {
            throw ValidationException::withMessages(['metode' => 'Pilih metode pembayaran.']);
        }

        $menu = Service::whereIn('id', array_column($baris, 'id'))->where('category', 'fnb')->where('is_active', true)->get()->keyBy('id');
        $rinci = [];
        foreach ($baris as $b) {
            $m = $menu->get((int) $b['id']);
            if (! $m || $m->habis()) {
                throw ValidationException::withMessages(['keranjang' => ($m?->name ?? 'Sebagian menu').' sedang tidak tersedia.']);
            }
            // Harga tetap dari basis data; menu harga pasar diisi kasir sesuai ukuran
            $harga = $m->price !== null ? (float) $m->price : (float) ($b['harga'] ?? 0);
            if ($harga <= 0) {
                throw ValidationException::withMessages(['keranjang' => "Isi harga {$m->name} sesuai ukuran."]);
            }
            $rinci[] = ['menu' => $m, 'qty' => max(1, (int) $b['qty']), 'harga' => $harga, 'catatan' => trim((string) ($b['catatan'] ?? '')) ?: null];
        }

        return DB::transaction(function () use ($rinci, $kamar, $pemesan, $penyajian, $bayarSekarang, $metode, $catatan, $oleh) {
            $customer = $kamar ? $kamar->reservation->customer : $pemesan;
            $total = collect($rinci)->sum(fn ($r) => $r['harga'] * $r['qty']);

            $invoice = $bayarSekarang ? Invoice::create([
                'invoice_number' => Nomor::nota(),
                'invoice_type' => 'service',
                'customer_id' => $customer->id,
                'payment_term' => 'immediate',
                'issue_date' => now()->toDateString(),
                'subtotal' => $total,
                'total_amount' => $total,
                'paid_amount' => 0,
                'status' => 'issued',
                'issued_by' => $oleh->id,
                'note' => 'Pesanan kasir',
            ]) : null;

            $order = ServiceOrder::create([
                'order_code' => Nomor::pesanan(),
                'access_token' => ServiceOrder::tokenBaru(),
                'order_type' => $kamar ? 'in_house' : 'outside_guest',
                'customer_id' => $customer->id,
                'reservation_room_id' => $kamar?->id,
                'serving_type' => $penyajian,
                'invoice_id' => $invoice?->id,
                'payment_mode' => $bayarSekarang ? 'pay_now' : 'charge_to_room',
                'order_date' => now(),
                'status' => 'pending',
                'created_by' => $oleh->id,
                'note' => $catatan,
            ]);

            foreach ($rinci as $r) {
                $order->items()->create([
                    'service_id' => $r['menu']->id, 'quantity' => $r['qty'], 'unit_price' => $r['harga'],
                    'subtotal' => $r['harga'] * $r['qty'], 'note' => $r['catatan'],
                ]);
                $invoice?->items()->create([
                    'item_type' => 'fnb', 'description' => Str::limit($r['menu']->name, 150, ''), 'service_date' => now()->toDateString(),
                    'quantity' => $r['qty'], 'unit_price' => $r['harga'], 'subtotal' => $r['harga'] * $r['qty'],
                ]);
            }

            if ($invoice) {
                $this->catatBayar($order->setRelation('invoice', $invoice), $metode, $oleh);
            }

            return $order;
        });
    }

    /** Pembayaran manual (tunai, QRIS, kartu, transfer) untuk pesanan bayar-sekarang. */
    public function bayar(ServiceOrder $order, string $metode, User $oleh): void
    {
        if (! array_key_exists($metode, self::METODE_KASIR)) {
            throw ValidationException::withMessages(['metode' => 'Pilih metode pembayaran.']);
        }
        if (! $order->invoice || $order->sudahDibayar() || $order->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Tidak ada tagihan yang perlu dibayar.']);
        }
        DB::transaction(fn () => $this->catatBayar($order, $metode, $oleh));
    }

    private function catatBayar(ServiceOrder $order, string $metode, User $oleh): void
    {
        $nota = $order->invoice;
        Payment::create([
            'payment_code' => Nomor::pembayaran(),
            'invoice_id' => $nota->id,
            'transaction_type' => 'payment',
            'payment_purpose' => 'service',
            'amount' => $nota->sisa(),
            'method' => $metode,
            'channel' => 'manual',
            'status' => 'success',
            'paid_at' => now(),
            'received_by' => $oleh->id,
            'note' => 'Pesanan '.$order->order_code,
        ]);
        $nota->paid_amount = $nota->total_amount;
        $this->nota->hitungUlang($nota);
    }

    public function mulai(ServiceOrder $order): void
    {
        $this->pastikanDiDapur($order, 'pending', 'Pesanan ini sudah dimasak atau belum masuk dapur.');
        $order->update(['status' => 'in_progress']);
    }

    public function siap(ServiceOrder $order): void
    {
        $this->pastikanDiDapur($order, 'in_progress', 'Pesanan belum dimasak.');
        $order->update(['status' => 'ready']);
    }

    public function serahkan(ServiceOrder $order): void
    {
        $this->pastikanDiDapur($order, 'ready', 'Pesanan belum siap.');
        $order->update(['status' => 'delivered']);
    }

    /** Hanya pesanan yang belum dimasak dan belum dibayar; pesanan lunas dikembalikan lewat refund di Keuangan. */
    public function batalkan(ServiceOrder $order, string $alasan): void
    {
        if ($order->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Pesanan yang sudah dimasak tidak bisa dibatalkan.']);
        }
        if ($order->sudahDibayar()) {
            throw ValidationException::withMessages(['status' => 'Pesanan ini sudah dibayar. Pembatalan perlu pengembalian dana lewat menu Keuangan.']);
        }
        DB::transaction(function () use ($order, $alasan) {
            $order->update(['status' => 'cancelled', 'note' => mb_substr(trim(($order->note ? $order->note."\n" : '').'Dibatalkan: '.$alasan), 0, 255)]);
            $order->invoice?->update(['status' => 'cancelled']);
        });
    }

    private function pastikanDiDapur(ServiceOrder $order, string $status, string $pesan): void
    {
        $bolehMasuk = $order->payment_mode === 'charge_to_room' || $order->sudahDibayar();
        if ($order->status !== $status || ! $bolehMasuk) {
            throw ValidationException::withMessages(['status' => $bolehMasuk ? $pesan : 'Pesanan ini belum dibayar, jadi belum boleh diproses dapur.']);
        }
    }
}
