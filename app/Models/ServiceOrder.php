<?php

namespace App\Models;

use App\Contracts\BayarOnline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Pesanan layanan (makanan, laundry). Tamu luar memesan makanan lewat halaman Resto. */
class ServiceOrder extends Model implements BayarOnline
{
    public const SERVING = ['take_away' => 'Ambil di tempat', 'dine_in' => 'Makan di tempat', 'room_service' => 'Antar ke kamar'];

    protected $fillable = [
        'order_code', 'access_token', 'order_type', 'customer_id', 'reservation_room_id', 'serving_type',
        'invoice_id', 'payment_mode', 'order_date', 'status', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return ['order_date' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Kamar dan periode menginap yang dipesankan; kosong untuk tamu luar. */
    public function reservationRoom(): BelongsTo
    {
        return $this->belongsTo(ReservationRoom::class);
    }

    /** Kode untuk URL: ORD/2026/10/0011 menjadi ORD-2026-10-0011. */
    public function kodeUrl(): string
    {
        return str_replace('/', '-', $this->order_code);
    }

    public static function dariKodeUrl(string $kode): ?self
    {
        return static::where('order_code', str_replace('-', '/', $kode))->first();
    }

    public function sudahDibayar(): bool
    {
        return $this->invoice?->status === 'paid';
    }

    /**
     * Batas waktu bayar online. Makanan: dihitung dari waktu pesan. Laundry: tagihan baru ada setelah ditimbang,
     * jadi dihitung 24 jam dari nota dibuat (lewat dari itu tetap bisa dibayar tunai saat mengambil).
     */
    public function batasBayar(): Carbon
    {
        if ($this->jenisLayanan() === 'laundry') {
            return ($this->invoice?->created_at ?? $this->order_date)->copy()->addDay();
        }

        return $this->order_date->copy()->addMinutes((int) config('services.midtrans.batas_bayar_menit', 60));
    }

    /**
     * Pesanan online yang tidak dibayar melewati batas waktu dibatalkan, walau tamu belum sempat memilih metode bayar.
     * Pesanan tamu menginap yang ditagihkan ke kamar tidak punya batas bayar. Laundry tidak dibatalkan:
     * tagihannya dibuat setelah ditimbang dan bisa dibayar tunai saat cucian diambil.
     */
    public function batalkanBilaKedaluwarsa(): self
    {
        if ($this->order_type === 'outside_guest' && $this->payment_mode === 'pay_now' && $this->jenisLayanan() !== 'laundry'
            && $this->status === 'pending' && ! $this->sudahDibayar() && $this->batasBayar()->addMinutes(5)->isPast()) {
            $this->update(['status' => 'cancelled']);
            $this->invoice?->update(['status' => 'cancelled']);
        }

        return $this;
    }

    /**
     * Keadaan pesanan untuk tamu: [kunci, label]. Kunci dipakai penyaring di halaman Pesanan saya.
     *
     * @return array{0: string, 1: string}
     */
    public function keadaanTamu(): array
    {
        $laundry = $this->jenisLayanan() === 'laundry';
        if ($laundry) {
            return match (true) {
                $this->status === 'cancelled' => ['batal', 'Dibatalkan'],
                $this->status === 'pending' => ['diproses', $this->order_type === 'in_house' ? 'Menunggu dijemput' : 'Menunggu ditimbang'],
                $this->payment_mode === 'pay_now' && ! $this->sudahDibayar() && $this->status !== 'delivered' => ['belum-bayar', 'Menunggu pembayaran'],
                $this->status === 'in_progress' => ['diproses', 'Sedang dicuci'],
                $this->status === 'ready' => ['diproses', $this->order_type === 'in_house' ? 'Siap diantar' : 'Siap diambil'],
                default => ['selesai', 'Selesai'],
            };
        }

        return match (true) {
            $this->status === 'cancelled' => ['batal', 'Dibatalkan'],
            $this->payment_mode === 'pay_now' && ! $this->sudahDibayar() => ['belum-bayar', 'Menunggu pembayaran'],
            $this->status === 'delivered' => ['selesai', 'Selesai'],
            $this->status === 'ready' => ['diproses', $this->serving_type === 'take_away' ? 'Siap diambil' : 'Siap disajikan'],
            $this->status === 'in_progress' => ['diproses', 'Sedang disiapkan'],
            default => ['diproses', $this->payment_mode === 'pay_now' ? 'Dibayar, menunggu dapur' : 'Menunggu dapur'],
        };
    }

    /** laundry bila ada layanan laundry di pesanan, selain itu makanan. */
    public function jenisLayanan(): string
    {
        return $this->items->contains(fn ($i) => $i->service?->category === 'laundry') ? 'laundry' : 'makanan';
    }

    /** Alamat status pesanan yang dibagikan kepada tamu (berisi token akses). */
    public function urlStatus(): string
    {
        return route($this->jenisLayanan() === 'laundry' ? 'laundry.status' : 'pesanan.status', ['kode' => $this->kodeUrl(), 't' => $this->access_token]);
    }

    /* ---------------- Pembayaran online lewat Midtrans ---------------- */

    public function kunciBayar(): string
    {
        return (string) $this->access_token;
    }

    /** ORD/2026/09/0132 menjadi WT-ORD202609-0132. */
    public function awalanMidtrans(): string
    {
        return 'WT-'.str_replace('/', '', substr($this->order_code, 0, 11)).'-'.substr($this->order_code, -4);
    }

    public function jumlahBayar(): int
    {
        return (int) round((float) $this->invoice?->total_amount);
    }

    public function rincianBayar(): array
    {
        // Midtrans hanya menerima jumlah bulat; berat laundry (misalnya 3,5 kg) dikirim sebagai satu baris senilai subtotal
        return $this->loadMissing('items.service')->items->map(fn ($i) => fmod((float) $i->quantity, 1.0) == 0.0 ? [
            'id' => $i->service->code,
            'price' => (int) $i->unit_price,
            'quantity' => (int) $i->quantity,
            'name' => Str::limit($i->service->name, 50, ''),
        ] : [
            'id' => $i->service->code,
            'price' => (int) round((float) $i->subtotal),
            'quantity' => 1,
            'name' => Str::limit($i->service->name.' '.rtrim(rtrim(number_format((float) $i->quantity, 2, ',', ''), '0'), ',').' '.$i->service->unit, 50, ''),
        ])->values()->all();
    }

    public function keteranganBayar(): string
    {
        return ($this->jenisLayanan() === 'laundry' ? 'Laundry ' : 'Pesanan ').$this->order_code;
    }

    public function pembayar(): ?Customer
    {
        return $this->customer;
    }

    public function notaBayar(): ?Invoice
    {
        return $this->invoice;
    }

    public static function tokenBaru(): string
    {
        return Str::random(40);
    }
}
