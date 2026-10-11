<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Laporan kerusakan (corrective) atau catatan perawatan rutin (preventive).
 * Status: reported → in_progress → completed, atau cancelled. Laporan selesai/batal tidak bisa diubah lagi.
 * blocks_room: pelapor mencentang "Kamar tidak bisa dipakai". Hanya laporan seperti ini yang menahan kamar tetap Perbaikan.
 * Jalur cepat: laporan penahan ditutup atas persetujuan Manager; ditandai lewat awalan resolution_note (tanpa kolom baru).
 */
class MaintenanceRequest extends Model
{
    /** Satu-satunya pemetaan label status (nilai ENUM di database tetap). Pakai labelStatus() atau labelUntuk(). */
    public const STATUS = ['reported' => 'Belum dikerjakan', 'in_progress' => 'Sedang dikerjakan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    public const PRIORITAS = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'];

    public const JENIS = ['corrective' => 'Perbaikan', 'preventive' => 'Perawatan rutin'];

    /** Awalan resolution_note laporan jalur cepat. Periksa hanya lewat isJalurCepat() dan scope jalurCepat(). */
    public const CATATAN_JALUR_CEPAT = 'Jalur cepat';

    protected $fillable = [
        'request_code', 'maintenance_type', 'maintenance_schedule_id', 'facility_unit_id', 'room_id', 'damage_description',
        'photos', 'priority', 'blocks_room', 'reported_by', 'reported_at', 'status', 'handled_by', 'vendor_name', 'started_at',
        'completed_at', 'cost', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'reported_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cost' => 'decimal:2',
            'blocks_room' => 'boolean',
        ];
    }

    /** Kolom blocks_room baru ditambahkan lewat SQL; sebelum ada, semua laporan terbuka dianggap menahan kamar (perilaku lama). */
    public static function adaKolomMenahan(): bool
    {
        static $ada = null;

        return $ada ??= Schema::hasColumn('maintenance_requests', 'blocks_room');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'maintenance_schedule_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FacilityUnit::class, 'facility_unit_id');
    }

    /** Kamar yang dilaporkan tanpa unit tertentu. Pakai kamar() untuk kamar dari unit juga. */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Kamar terkait: dari unit fasilitas di kamar, atau kamar yang dilaporkan langsung. */
    public function kamar(): ?Room
    {
        return $this->unit?->room ?? $this->room;
    }

    /** "AC Kamar 201", "Kamar 213 · lainnya", "AC · Lantai 2", atau "Area umum". */
    public function lokasi(): string
    {
        if ($this->maintenance_type === 'preventive' && $this->schedule) {
            return $this->schedule->facility?->name.' · '.$this->schedule->area_label;
        }
        if ($this->unit) {
            return $this->unit->unit_label;
        }
        if ($this->room) {
            return 'Kamar '.$this->room->room_number;
        }

        return 'Area umum';
    }

    /**
     * Lokasi untuk daftar Laporan Kerusakan staf: "AC-201", "Kamar 213 · lainnya", "Area umum: Dapur".
     * Nama area laporan "lainnya" di area umum tercatat di awal deskripsi ("Area Dapur: ...").
     */
    public function lokasiLaporan(): string
    {
        return match (true) {
            $this->unit !== null => $this->unit->unit_label,
            $this->room !== null => 'Kamar '.$this->room->room_number.' · lainnya',
            (bool) preg_match('/^Area (.+?): /u', (string) $this->damage_description, $m) => 'Area umum: '.$m[1],
            default => 'Area umum',
        };
    }

    /** Deskripsi tanpa awalan nama area (sudah tampil di lokasi). */
    public function deskripsiTanpaArea(): string
    {
        return $this->unit || $this->room ? (string) $this->damage_description : (string) preg_replace('/^Area .+?: /u', '', (string) $this->damage_description);
    }

    /** Pengerja yang boleh dilihat semua staf: nama staf internal, atau "Vendor" tanpa namanya. */
    public function pengerjaUntukStaf(): ?string
    {
        return $this->vendor_name ? 'Vendor' : $this->handler?->name;
    }

    /** Area perawatan untuk penyaring: area unit, lantai kamar, atau area jadwal. */
    public function area(): ?string
    {
        return $this->unit?->area_label ?? $this->schedule?->area_label ?? ($this->room ? 'Lantai '.$this->room->floor : null);
    }

    /** Pengerja: nama staf internal atau vendor. */
    public function pengerja(): ?string
    {
        return $this->vendor_name ? 'Vendor: '.$this->vendor_name : $this->handler?->name;
    }

    /** "Belum dikerjakan", "Sedang dikerjakan", "Selesai", atau "Dibatalkan". */
    public function labelStatus(): string
    {
        return self::labelUntuk((string) $this->status);
    }

    public static function labelUntuk(string $status): string
    {
        return self::STATUS[$status] ?? $status;
    }

    /** Ditutup lewat jalur cepat: selesai dengan resolution_note berawalan "Jalur cepat". */
    public function isJalurCepat(): bool
    {
        return $this->status === 'completed' && str_starts_with((string) $this->resolution_note, self::CATATAN_JALUR_CEPAT);
    }

    public function scopeJalurCepat(Builder $query): void
    {
        $query->where('status', 'completed')->where('resolution_note', 'like', self::CATATAN_JALUR_CEPAT.'%');
    }

    /** Teks setelah awalan jalur cepat ("Jalur cepat — freon diisi ulang" → "freon diisi ulang"), atau null. */
    public function catatanTambahan(): ?string
    {
        if (! $this->isJalurCepat()) {
            return null;
        }
        $sisa = trim(preg_replace('/^\s*[—-]?\s*/u', '', Str::after((string) $this->resolution_note, self::CATATAN_JALUR_CEPAT)));

        return $sisa !== '' ? $sisa : null;
    }

    /**
     * Aturan validasi catatan selesai biasa (Selesaikan, Catat perawatan): tidak boleh diawali "Jalur cepat"
     * (tanpa peduli huruf besar/kecil), supaya laporan biasa tidak terbaca sebagai jalur cepat dan ikut bisa diedit.
     */
    public static function aturanBukanJalurCepat(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal) {
            if (Str::startsWith(Str::lower(ltrim((string) $nilai)), Str::lower(self::CATATAN_JALUR_CEPAT))) {
                $gagal('Awalan "'.self::CATATAN_JALUR_CEPAT.'" hanya untuk laporan yang dibuka lewat jalur cepat.');
            }
        };
    }

    /** resolution_note jalur cepat: awalan selalu ada, catatan tambahan opsional, supaya penandanya tidak terhapus. */
    public static function catatanJalurCepat(?string $tambahan = null): string
    {
        $tambahan = filled($tambahan) ? trim($tambahan) : null;

        return self::CATATAN_JALUR_CEPAT.($tambahan ? ' — '.$tambahan : '');
    }

    public function terbuka(): bool
    {
        return in_array($this->status, ['reported', 'in_progress'], true);
    }

    /** @return list<string> */
    public function fotoUrls(): array
    {
        return collect($this->photos ?? [])
            ->filter(fn ($p) => is_string($p) && Storage::disk('public')->exists($p))
            ->map(fn ($p) => asset('storage/'.$p))->values()->all();
    }
}
