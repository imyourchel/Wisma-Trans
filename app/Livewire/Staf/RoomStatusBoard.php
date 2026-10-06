<?php

namespace App\Livewire\Staf;

use App\Enums\RoomStatus;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Services\RoomStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Papan Status Kamar untuk Manager, Front Office, dan Housekeeping (Admin hanya mengelola data master).
 * Manager hanya melihat. Pilihan status mengikuti RoomStatus::allowedTransitions() dan peran pengguna;
 * RoomStatusService memeriksanya lagi saat menyimpan.
 * Papan memuat ulang tiap 30 detik (dihentikan selama detail kamar terbuka).
 * Tampilan kalender menampilkan pemesanan per kamar selama 14 hari (hanya dibaca).
 */
class RoomStatusBoard extends Component
{
    #[Url(as: 'status', except: '')]
    public string $filter = '';

    /** Diisi hanya lewat open(), supaya tidak bisa diganti dari browser. */
    #[Locked]
    public ?int $selectedId = null;

    public string $status = '';

    public string $note = '';

    /** papan | kalender */
    #[Url(as: 'tampilan', except: 'papan')]
    public string $view = 'papan';

    /** Tanggal awal kalender (Y-m-d); kosong = hari ini */
    #[Url(as: 'mulai', except: '')]
    public string $start = '';

    private const HARI_KALENDER = 14;

    public function mount(): void
    {
        Gate::authorize('view-rooms');
    }

    public function filterBy(string $status): void
    {
        $this->filter = $this->filter === $status || RoomStatus::tryFrom($status) === null ? '' : $status;
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['papan', 'kalender'], true) ? $view : 'papan';
    }

    /** Geser kalender maju/mundur; 0 = kembali ke hari ini. */
    public function shiftDays(int $hari): void
    {
        $this->start = $hari === 0 ? '' : $this->startDate()->addDays(max(-60, min(60, $hari)))->toDateString();
    }

    /** Lompat ke tanggal tertentu dari pemilih tanggal; tanggal itu menjadi kolom pertama kalender. */
    public function pickDate(string $tanggal): void
    {
        $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) && checkdate((int) substr($tanggal, 5, 2), (int) substr($tanggal, 8, 2), (int) substr($tanggal, 0, 4));
        $this->start = ! $valid || $tanggal === today()->toDateString() ? '' : $tanggal;
    }

    private function startDate(): Carbon
    {
        try {
            return $this->start !== '' ? Carbon::createFromFormat('Y-m-d', $this->start)->startOfDay() : today();
        } catch (\Throwable) {
            return today();
        }
    }

    /**
     * Pemesanan yang beririsan dengan rentang kalender. Balok menutup malam menginap:
     * dari tanggal check-in sampai sehari sebelum check-out.
     *
     * @return array{hari: Collection, perKamar: Collection, tanpaKamar: Collection, akhir: Carbon}
     */
    private function calendar(): array
    {
        $mulai = $this->startDate();
        $akhir = $mulai->copy()->addDays(self::HARI_KALENDER);

        $baris = DB::table('reservation_rooms as rr')
            ->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('institutions as i', 'i.id', '=', 'r.institution_id')
            ->join('room_types as t', 't.id', '=', 'rr.room_type_id')
            ->where('rr.status', '!=', 'cancelled')
            ->whereNotIn('r.status', ['cancelled', 'rejected', 'no_show'])
            ->where('rr.check_in_date', '<', $akhir->toDateString())
            ->where('rr.check_out_date', '>', $mulai->toDateString())
            ->orderBy('rr.check_in_date')
            ->get(['rr.id', 'rr.room_id', 'rr.check_in_date', 'rr.check_out_date', 'rr.status', 'r.reservation_code',
                'c.full_name', 'i.name as instansi', 't.name as tipe']);

        $balok = $baris->map(function ($r) use ($mulai, $akhir) {
            $masuk = Carbon::parse($r->check_in_date);
            $keluar = Carbon::parse($r->check_out_date);
            $dari = max(0, (int) $mulai->diffInDays($masuk));
            $sampai = min(self::HARI_KALENDER, (int) $mulai->diffInDays($keluar));

            return (object) [
                'id' => $r->id,
                'room_id' => $r->room_id,
                'kolom' => ($dari + 1).' / '.($sampai + 1),
                'potongKiri' => $masuk->lt($mulai),
                'potongKanan' => $keluar->gt($akhir),
                'nama' => $r->full_name,
                'instansi' => $r->instansi,
                'kode' => $r->reservation_code,
                'tipe' => $r->tipe,
                'jenis' => match ($r->status) {
                    'checked_in' => 'menginap',
                    'booked' => 'akan',
                    default => 'selesai',
                },
                'tanggal' => $masuk->translatedFormat('j M').' – '.$keluar->translatedFormat('j M'),
            ];
        });

        return [
            'hari' => collect(range(0, self::HARI_KALENDER - 1))->map(fn ($i) => $mulai->copy()->addDays($i)),
            'perKamar' => $balok->whereNotNull('room_id')->groupBy('room_id'),
            'tanpaKamar' => $balok->whereNull('room_id')->values(),
            'akhir' => $akhir->copy()->subDay(),
        ];
    }

    public function open(int $id, RoomStatusService $service): void
    {
        Gate::authorize('view-rooms');

        $room = Room::active()->findOrFail($id);
        $this->resetValidation();
        $this->selectedId = $room->id;
        // Dari "Sedang dibersihkan" langsung terpilih tujuan yang disarankan
        $this->status = $service->suggestedTarget($room)?->value ?? '';
        $this->note = '';
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->resetValidation();
    }

    public function save(RoomStatusService $service): void
    {
        Gate::authorize('change-room-status');

        $room = Room::findOrFail($this->selectedId);
        $this->validate([
            'status' => ['required', Rule::enum(RoomStatus::class)],
            'note' => ['nullable', 'string', 'max:255', 'required_if:status,'.RoomStatus::Maintenance->value],
        ], [
            'status.required' => 'Pilih status baru.',
            'note.required_if' => 'Catatan wajib diisi untuk status Perbaikan.',
            'note.max' => 'Catatan maksimal 255 karakter.',
        ]);

        $baru = RoomStatus::from($this->status);
        if ($baru === $room->status) {
            $this->addError('status', 'Pilih status yang berbeda dari status sekarang.');

            return;
        }

        // Aturan perpindahan dan peran diperiksa di service; galatnya tampil di formulir.
        // Kamar yang masih punya laporan kerusakan penahan tidak menjadi Siap, melainkan Perbaikan (pesan dari service).
        $ditahan = $service->change($room, $baru, $this->note, Auth::user());

        $this->close();
        $ditahan
            ? $this->dispatch('toast', teks: $ditahan, peringatan: true)
            : $this->dispatch('toast', teks: "Status kamar {$room->room_number} diubah menjadi {$baru->label()}.");
    }

    public function render(): View
    {
        $service = app(RoomStatusService::class);
        $rooms = Room::active()->with('roomType')
            ->orderBy('floor')->orderByRaw('CHAR_LENGTH(room_number)')->orderBy('room_number')
            ->get();
        $filter = RoomStatus::tryFrom($this->filter);

        $selected = $this->selectedId ? Room::with('roomType')->find($this->selectedId) : null;
        $user = Auth::user()->loadMissing('roles');
        $canChange = Gate::allows('change-room-status');

        // Kamar dengan laporan kerusakan penahan yang belum selesai (blocks_room), misalnya AC rusak saat tamu menginap
        $rusak = MaintenanceRequest::adaKolomMenahan()
            ? MaintenanceRequest::with('unit:id,room_id')->whereIn('status', ['reported', 'in_progress'])->where('blocks_room', true)
                ->get(['id', 'room_id', 'facility_unit_id', 'request_code'])
                ->groupBy(fn ($l) => $l->unit?->room_id ?? $l->room_id)->map(fn ($g) => $g->pluck('request_code')->implode(', '))
            : collect();

        return view('livewire.staf.room-status-board', [
            'rusak' => $rusak,
            'counts' => $rooms->countBy(fn (Room $r) => $r->status->value),
            'total' => $rooms->count(),
            'floors' => ($filter ? $rooms->where('status', $filter) : $rooms)->groupBy('floor'),
            'selected' => $selected,
            'history' => $selected
                ? $selected->statusLogs()->with('changedBy')->orderByDesc('changed_at')->orderByDesc('id')->limit(10)->get()
                : collect(),
            'canChange' => $canChange,
            'options' => $selected && $canChange ? $selected->status->transitionsFor($user->roles->pluck('code')->all()) : [],
            'suggested' => $selected ? $service->suggestedTarget($selected) : null,
            'kalender' => $this->view === 'kalender' ? $this->calendar() : null,
            // Riwayat perawatan kamar (laporan kerusakan dan unit fasilitas di kamar ini)
            'perawatan' => $selected
                ? $selected->maintenanceRequests()->with('unit', 'handler')->whereIn('status', ['reported', 'in_progress', 'completed'])
                    ->orderByDesc('reported_at')->orderByDesc('id')->limit(5)->get()
                : collect(),
            'bolehMaintenance' => $user->can('manage-maintenance'),
        ])->layout('components.layouts.staf', ['title' => 'Papan Status Kamar']);
    }
}
