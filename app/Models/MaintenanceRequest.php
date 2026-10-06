<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Laporan kerusakan (corrective) atau catatan perawatan rutin (preventive).
 * Status: reported → in_progress → completed, atau cancelled. Laporan selesai/batal tidak bisa diubah lagi.
 * blocks_room: pelapor mencentang "Kamar tidak bisa dipakai". Hanya laporan seperti ini yang menahan kamar tetap Perbaikan.
 */
class MaintenanceRequest extends Model
{
    public const STATUS = ['reported' => 'Baru dilaporkan', 'in_progress' => 'Sedang dikerjakan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    public const PRIORITAS = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'];

    public const JENIS = ['corrective' => 'Perbaikan', 'preventive' => 'Perawatan rutin'];

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
