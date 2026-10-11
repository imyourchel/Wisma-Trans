<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemesanan aula. Harga sewa tidak tetap: dirundingkan dengan penyewa. rental_price 0 berarti belum disepakati;
 * Manager menetapkan harga akhir saat menyetujui. Perubahan status lewat App\Services\Layanan\AulaService.
 */
class HallBooking extends Model
{
    public const STATUS = [
        'pending' => 'Menunggu persetujuan',
        'confirmed' => 'Terkonfirmasi',
        'completed' => 'Selesai',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
    ];

    protected $fillable = [
        'booking_code', 'hall_id', 'customer_id', 'reservation_id', 'invoice_id', 'start_at', 'end_at',
        'participant_count', 'rental_price', 'purpose', 'status', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime', 'rental_price' => 'decimal:2'];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    public function hargaDisepakati(): bool
    {
        return (float) $this->rental_price > 0;
    }

    /** Misalnya "Sab, 17 Okt 2026 · 18.00–22.00" atau "Sab, 17 Okt 18.00 – Min, 18 Okt 2026 12.00". */
    public function jadwal(): string
    {
        return $this->start_at->isSameDay($this->end_at)
            ? $this->start_at->translatedFormat('D, j M Y · H.i').'–'.$this->end_at->format('H.i')
            : $this->start_at->translatedFormat('D, j M H.i').' – '.$this->end_at->translatedFormat('D, j M Y H.i');
    }
}
