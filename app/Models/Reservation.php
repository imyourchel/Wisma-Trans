<?php

namespace App\Models;

use App\Contracts\BayarOnline;
use App\Support\TokenAkses;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reservasi kamar: online (tamu perorangan), langsung di resepsionis (walk-in), atau pengajuan instansi.
 * Perubahan status hanya lewat App\Services\Reservasi\ReservasiService supaya reservation_logs selalu tercatat.
 */
class Reservation extends Model implements BayarOnline
{
    public const STATUS = [
        'pending_payment' => 'Menunggu uang muka',
        'waiting_approval' => 'Menunggu persetujuan',
        'confirmed' => 'Terkonfirmasi',
        'checked_in' => 'Menginap',
        'checked_out' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        'rejected' => 'Ditolak',
        'no_show' => 'Tidak datang',
    ];

    public const SKEMA_LAYANAN = [
        'satu_nota' => 'Layanan ikut nota kamar',
        'dua_nota' => 'Nota kamar dan layanan terpisah',
        'tamu_bayar' => 'Layanan dibayar tamu',
    ];

    protected $fillable = [
        'reservation_code', 'customer_id', 'institution_id', 'billing_scheme', 'service_billing_scheme',
        'booking_channel', 'status', 'special_request', 'dp_amount_required', 'dp_due_at', 'id_card_held',
        'id_card_holder_name', 'access_token', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'dp_amount_required' => 'decimal:2',
            'dp_due_at' => 'datetime',
            'id_card_held' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ReservationLog::class);
    }

    public function request(): HasOne
    {
        return $this->hasOne(ReservationRequest::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Nota menginap utama (dibayar saat check-out). Nota periode menginap panjang instansi (period_start terisi)
     * adalah nota terpisah dan tidak termasuk di sini.
     */
    public function stayInvoice(): HasOne
    {
        // Syarat jenis harus di dalam ofMany: bila di luar, nota layanan yang lebih baru membuat hasilnya kosong
        return $this->hasOne(Invoice::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('invoice_type', 'stay')->whereNull('period_start'));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** Baris kamar yang masih berlaku (bukan yang sudah dipindah atau dibatalkan). */
    public function kamarBerlaku(): Collection
    {
        return $this->rooms->whereNotIn('status', ['moved', 'cancelled'])->values();
    }

    /** Baris yang menentukan tanggal menginap: semua kecuali yang dibatalkan, atau semua baris bila reservasi sudah batal. */
    private function barisTanggal(): Collection
    {
        $aktif = $this->rooms->where('status', '!=', 'cancelled');

        return $aktif->isNotEmpty() ? $aktif : $this->rooms;
    }

    public function masuk(): ?Carbon
    {
        $awal = $this->barisTanggal()->min(fn ($r) => $r->check_in_date->toDateString());

        return $awal ? Carbon::parse($awal) : ($this->request ? Carbon::parse($this->request->check_in_date) : null);
    }

    public function keluar(): ?Carbon
    {
        $akhir = $this->barisTanggal()->max(fn ($r) => $r->check_out_date->toDateString());

        return $akhir ? Carbon::parse($akhir) : ($this->request ? Carbon::parse($this->request->check_out_date) : null);
    }

    public function token(): string
    {
        return TokenAkses::tampil($this->access_token);
    }

    /** Kode bukti pemesanan instansi = token akses utuh (LK7Q-2M9X), disebutkan atau ditunjukkan di resepsionis. */
    public function kodeBukti(): string
    {
        return $this->token();
    }

    /** Kode kamar untuk memesan makanan dan laundry selama menginap = token akses (LK7Q-2M9X). */
    public function kodeKamar(): string
    {
        return $this->token();
    }

    /** Tautan Resto yang langsung mencocokkan kode kamar. */
    public function urlPesanMakanan(): string
    {
        return route('menu', ['t' => $this->access_token]);
    }

    /** Tautan Laundry yang langsung mencocokkan kode kamar. */
    public function urlPesanLaundry(): string
    {
        return route('laundry', ['t' => $this->access_token]);
    }

    /** Alamat cek pesanan yang dikirim lewat WhatsApp dan email (berisi token akses). */
    public function urlStatus(): string
    {
        return route('reservasi.status', ['kode' => $this->reservation_code, 't' => $this->access_token]);
    }

    /**
     * Daftar Check-in: reservasi confirmed yang punya kamar booked dengan masa menginap mencakup hari ini
     * (termasuk tamu yang dijadwalkan masuk hari sebelumnya dan belum datang). Dipakai juga kartu Dashboard.
     */
    public function scopeMenungguCheckIn(Builder $q): Builder
    {
        return $q->where('status', 'confirmed')->whereHas('rooms', fn ($b) => $b->where('status', 'booked')
            ->where('check_in_date', '<=', today()->toDateString())->where('check_out_date', '>', today()->toDateString()));
    }

    public function instansi(): bool
    {
        return $this->institution_id !== null || $this->request()->exists();
    }

    /* ---------------- Pembayaran uang muka lewat Midtrans ---------------- */

    public function kunciBayar(): string
    {
        return (string) $this->access_token;
    }

    public function awalanMidtrans(): string
    {
        return 'WT-'.str_replace('-', '', $this->reservation_code).'-DP';
    }

    public function jumlahBayar(): int
    {
        return (int) round((float) $this->dp_amount_required);
    }

    public function batasBayar(): CarbonInterface
    {
        return $this->dp_due_at ?? now();
    }

    public function rincianBayar(): array
    {
        return [[
            'id' => 'DP-'.$this->reservation_code,
            'price' => $this->jumlahBayar(),
            'quantity' => 1,
            'name' => 'Uang muka reservasi '.$this->reservation_code,
        ]];
    }

    public function keteranganBayar(): string
    {
        return 'Uang muka '.$this->reservation_code;
    }

    public function pembayar(): ?Customer
    {
        return $this->customer;
    }

    public function notaBayar(): ?Invoice
    {
        return $this->stayInvoice;
    }

    /** Uang muka dianggap dibayar bila sudah ada pembayaran uang muka yang berhasil. */
    public function sudahDibayar(): bool
    {
        return $this->dpDibayar() >= $this->jumlahBayar() && $this->jumlahBayar() > 0;
    }

    /** Jumlah uang muka yang sudah diterima (pembayaran berhasil). */
    public function dpDibayar(): float
    {
        return (float) Payment::whereIn('invoice_id', $this->invoices()->select('id'))
            ->where('payment_purpose', 'down_payment')->where('transaction_type', 'payment')->where('status', 'success')
            ->sum('amount');
    }
}
