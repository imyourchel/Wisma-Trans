<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Satu kamar dalam reservasi. room_id kosong sampai nomor kamar ditetapkan (reservasi online saat check-in).
 * Harga per malam disalin saat memesan, jadi tidak ikut berubah bila harga master diubah.
 * Pindah kamar: baris lama berstatus moved, baris baru menunjuk moved_from_id.
 */
class ReservationRoom extends Model
{
    public const STATUS = [
        'booked' => 'Dipesan',
        'checked_in' => 'Menginap',
        'moved' => 'Dipindah',
        'checked_out' => 'Sudah keluar',
        'cancelled' => 'Dibatalkan',
    ];

    protected $fillable = [
        'reservation_id', 'room_type_id', 'room_id', 'check_in_date', 'check_out_date', 'price_per_night',
        'discount_type', 'discount_value', 'actual_check_in_at', 'actual_check_out_at', 'status', 'moved_from_id',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'price_per_night' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'actual_check_in_at' => 'datetime',
            'actual_check_out_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function movedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'moved_from_id');
    }

    public function malam(): int
    {
        return (int) $this->check_in_date->diffInDays($this->check_out_date);
    }

    /** "6–8 Okt 2026", "30 Sep – 2 Okt 2026", atau "30 Des 2026 – 2 Jan 2027". */
    public function rentang(): string
    {
        [$masuk, $keluar] = [$this->check_in_date, $this->check_out_date];

        return match (true) {
            $masuk->isSameMonth($keluar) => $masuk->format('j').'–'.$keluar->translatedFormat('j M Y'),
            $masuk->isSameYear($keluar) => $masuk->translatedFormat('j M').' – '.$keluar->translatedFormat('j M Y'),
            default => $masuk->translatedFormat('j M Y').' – '.$keluar->translatedFormat('j M Y'),
        };
    }

    /** "6–8 Okt 2026 (RSV-0008)", untuk pesan penolakan dan peringatan. */
    public function keterangan(): string
    {
        return $this->rentang().' ('.$this->reservation?->reservation_code.')';
    }

    /** Potongan rupiah untuk seluruh malam baris ini (persen dari harga, atau nominal). */
    public function potongan(): float
    {
        $kotor = (float) $this->price_per_night * $this->malam();

        return match ($this->discount_type) {
            'percent' => round($kotor * (float) $this->discount_value / 100),
            'amount' => min($kotor, (float) $this->discount_value),
            default => 0.0,
        };
    }

    /**
     * Kunci reservasi dan baris kamar (panggil di dalam transaksi), lalu pastikan tamu masih menginap.
     * Check-out mengunci reservasi yang sama, jadi pesanan baru tidak bisa lolos setelah nota digabung.
     */
    public static function kunciMenginap(int $id): self
    {
        $baris = self::find($id);
        $r = $baris ? Reservation::whereKey($baris->reservation_id)->lockForUpdate()->first() : null;
        $baris = $baris ? self::whereKey($id)->lockForUpdate()->first() : null;
        if (! $baris || $baris->status !== 'checked_in' || $r?->status !== 'checked_in') {
            throw ValidationException::withMessages(['kamar' => 'Kamar ini tidak sedang ditempati.']);
        }

        return $baris->setRelation('reservation', $r);
    }

    /** Biaya baris ini setelah potongan. */
    public function biaya(): float
    {
        return (float) $this->price_per_night * $this->malam() - $this->potongan();
    }
}
