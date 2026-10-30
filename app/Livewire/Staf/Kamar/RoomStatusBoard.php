<?php

namespace App\Livewire\Staf\Kamar;

use App\Enums\RoomStatus;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Kamar\RoomStatusService;
use App\Services\Maintenance\MaintenanceService;
use App\Services\SmartCleaning\TugasHousekeepingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Papan Status Kamar untuk Manager, Front Office, dan Housekeeping (Admin hanya mengelola data master).
 * Manager hanya melihat. Pilihan status mengikuti RoomStatus::allowedTransitions() dan peran pengguna;
 * RoomStatusService memeriksanya lagi saat menyimpan.
 * Papan memuat ulang tiap 30 detik (dihentikan selama detail kamar terbuka).
 * Tampilan kalender (tombol Kalender di halaman ini, ?tampilan=kalender) menampilkan pemesanan per kamar selama 14 hari,
 * sisa kamar per tipe per malam (KetersediaanKamar), dan pemesanan yang belum punya nomor kamar. Manager/FO membuka
 * reservasi dari baloknya; FO membuat reservasi langsung dari sel kosong. Housekeeping tidak melihat nama tamu.
 * Jalur cepat: kamar Perbaikan yang tertahan laporan kerusakan bisa dibuka FO/HK dengan email dan kata sandi Manager.
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

    /** Modal "Buka dengan persetujuan Manager" terbuka. */
    public bool $jalurCepat = false;

    public string $emailManager = '';

    public string $sandiManager = '';

    public function mount(): void
    {
        Gate::authorize('view-rooms');
    }

    public function filterBy(string $status): void
    {
        $this->filter = $this->filter === $status || RoomStatus::tryFrom($status) === null ? '' : $status;
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
     * @return array{hari: Collection, perKamar: Collection, tanpaKamar: Collection, akhir: Carbon, tipe: Collection,
     *               sisa: array<string, array<int, int>>, jumlahKamar: Collection, perTanggal: Collection}
     */
    private function calendar(): array
    {
        $mulai = $this->startDate();
        $akhir = $mulai->copy()->addDays(self::HARI_KALENDER);
        // Housekeeping hanya perlu tahu kamar terisi, bukan siapa tamunya
        $lihatTamu = Gate::allows('view-reservations');

        $baris = DB::table('reservation_rooms as rr')
            ->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('institutions as i', 'i.id', '=', 'r.institution_id')
            ->join('room_types as t', 't.id', '=', 'rr.room_type_id')
            ->where('rr.status', '!=', 'cancelled')
            ->whereNotIn('r.status', ['cancelled', 'rejected', 'no_show'])
            ->where('rr.check_in_date', '<', $akhir->toDateString())
            ->where('rr.check_out_date', '>', $mulai->toDateString())
            ->orderBy('rr.check_in_date')->orderBy('rr.id')
            ->get(['rr.id', 'rr.reservation_id', 'rr.room_id', 'rr.room_type_id', 'rr.check_in_date', 'rr.check_out_date', 'rr.status',
                'r.reservation_code', 'c.full_name', 'i.name as instansi', 't.name as tipe']);

        $balok = $baris->map(function ($r) use ($mulai, $akhir, $lihatTamu) {
            $masuk = Carbon::parse($r->check_in_date);
            $keluar = Carbon::parse($r->check_out_date);
            $dari = max(0, (int) $mulai->diffInDays($masuk));
            $sampai = min(self::HARI_KALENDER, (int) $mulai->diffInDays($keluar));

            return (object) [
                'id' => $r->id,
                'reservasi' => $r->reservation_id,
                'room_id' => $r->room_id,
                'room_type_id' => $r->room_type_id,
                'dari' => $dari,
                'sampai' => $sampai,
                'kolom' => ($dari + 1).' / '.($sampai + 1),
                'potongKiri' => $masuk->lt($mulai),
                'potongKanan' => $keluar->gt($akhir),
                'nama' => $lihatTamu ? $r->full_name : null,
                'instansi' => $lihatTamu ? $r->instansi : null,
                'kode' => $r->reservation_code,
                'tipe' => $r->tipe,
                'masuk' => $masuk->toDateString(),
                'keluar' => $keluar->toDateString(),
                'jenis' => match ($r->status) {
                    'checked_in' => 'menginap',
                    'booked' => 'akan',
                    default => 'selesai',
                },
                'tanggal' => $masuk->translatedFormat('j M').' – '.$keluar->translatedFormat('j M'),
                'url' => $lihatTamu ? route('staf.reservasi', ['buka' => $r->reservation_id]) : null,
            ];
        });

        // Pemesanan tanpa nomor kamar: satu kelompok per tipe, dibagi ke lajur supaya balok tidak bertumpuk
        $tanpaKamar = $balok->whereNull('room_id')->groupBy('tipe')->map(function (Collection $g) {
            $lajur = [];
            foreach ($g->sortBy('dari') as $b) {
                $i = collect($lajur)->search(fn ($akhirLajur) => $akhirLajur <= $b->dari);
                $i = $i === false ? count($lajur) : $i;
                $lajur[$i] = $b->sampai;
                $b->lajur = $i;
            }

            return $g->groupBy('lajur')->sortKeys()->values();
        });

        $hari = collect(range(0, self::HARI_KALENDER - 1))->map(fn ($i) => $mulai->copy()->addDays($i));
        $tipe = RoomType::orderBy('id')->get(['id', 'name', 'code']);
        // Sisa per malam mengikuti aturan pemesanan (KetersediaanKamar), termasuk pesanan yang belum punya nomor kamar
        $ketersediaan = app(KetersediaanKamar::class);
        $sisa = $hari->mapWithKeys(fn (Carbon $h) => [$h->toDateString() => $ketersediaan->sisa($h, $h->copy()->addDay())])->all();
        $jumlahKamar = Room::active()->selectRaw('room_type_id, COUNT(*) AS n')->groupBy('room_type_id')->pluck('n', 'room_type_id');

        // Daftar per tanggal untuk layar sempit: tipe → kamar terisi malam itu, dan sisa
        $nomor = Room::pluck('room_number', 'id');
        $perTanggal = $hari->mapWithKeys(fn (Carbon $h) => [$h->toDateString() => $tipe->map(fn ($t) => [
            'tipe' => $t,
            'sisa' => $sisa[$h->toDateString()][$t->id] ?? 0,
            'terisi' => $balok->filter(fn ($b) => $b->room_type_id == $t->id && $b->masuk <= $h->toDateString() && $b->keluar > $h->toDateString())
                ->map(fn ($b) => ['kamar' => $b->room_id ? $nomor[$b->room_id] ?? '?' : null, 'kode' => $b->kode, 'url' => $b->url])->values(),
        ])]);

        return [
            'hari' => $hari,
            'perKamar' => $balok->whereNotNull('room_id')->groupBy('room_id'),
            'tanpaKamar' => $tanpaKamar,
            'akhir' => $akhir->copy()->subDay(),
            'tipe' => $tipe,
            'sisa' => $sisa,
            'jumlahKamar' => $jumlahKamar,
            'perTanggal' => $perTanggal,
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
        $this->tutupJalurCepat();
        $this->resetValidation();
    }

    public function bukaJalurCepat(): void
    {
        Gate::authorize('change-room-status');
        $this->resetValidation();
        $this->jalurCepat = true;
        $this->emailManager = '';
        $this->sandiManager = '';
    }

    public function tutupJalurCepat(): void
    {
        $this->jalurCepat = false;
        $this->sandiManager = '';
        $this->resetValidation(['emailManager', 'sandiManager']);
    }

    /** Kredensial Manager hanya dicocokkan; sesi FO/HK tidak berganti. */
    public function simpanJalurCepat(MaintenanceService $layanan): void
    {
        Gate::authorize('change-room-status');
        $room = Room::findOrFail($this->selectedId);
        $this->validate([
            'emailManager' => ['required', 'string', 'max:150'],
            'sandiManager' => ['required', 'string'],
        ], [
            'emailManager.required' => 'Isi email Manager.',
            'sandiManager.required' => 'Isi kata sandi Manager.',
        ]);

        try {
            $manager = $layanan->verifikasiManager($this->emailManager, $this->sandiManager, Auth::user());
            $pesan = $layanan->selesaikanCepat($room, $manager, Auth::user());
        } catch (ValidationException $e) {
            $this->sandiManager = '';
            $this->addError('sandiManager', collect($e->errors())->flatten()->first());

            return;
        }

        $this->close();
        $this->dispatch('toast', teks: $pesan);
    }

    public function save(RoomStatusService $service, TugasHousekeepingService $tugas): void
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
        // Tugas housekeeping hari ini mengikuti status kamar yang sebenarnya (bisa ditahan menjadi Perbaikan)
        $tugas->sinkron($room, $room->status, Auth::user());

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

        return view('livewire.staf.kamar.room-status-board', [
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
            // FO membuat reservasi langsung dari sel kosong kalender (hari ini dan sesudahnya)
            'bolehPesan' => Gate::allows('manage-reservations'),
            // Riwayat perawatan kamar (laporan kerusakan dan unit fasilitas di kamar ini)
            'perawatan' => $selected
                ? $selected->maintenanceRequests()->with('unit', 'handler')->whereIn('status', ['reported', 'in_progress', 'completed'])
                    ->orderByDesc('reported_at')->orderByDesc('id')->limit(5)->get()
                : collect(),
            'bolehMaintenance' => $user->can('manage-maintenance'),
            // Laporan yang menahan kamar Perbaikan; bisa ditutup lewat jalur cepat dengan persetujuan Manager
            'penahan' => $selected && $canChange && $selected->status === RoomStatus::Maintenance
                ? $selected->laporanMenahan()->map(fn ($l) => ['kode' => $l->request_code, 'deskripsi' => Str::limit($l->damage_description, 60)])
                : collect(),
        ])->layout('components.layouts.staf', ['title' => $this->view === 'kalender' ? 'Kalender Ketersediaan' : 'Papan Status Kamar']);
    }
}
