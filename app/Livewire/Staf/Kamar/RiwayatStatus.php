<?php

namespace App\Livewire\Staf\Kamar;

use App\Enums\RoomStatus;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Riwayat Status Kamar (Manager, FO): daftar log room_status_logs dengan status sebelumnya dan lama di status itu,
 * serta lini masa per kamar (urutan perubahan dan total waktu per status dalam rentang terpilih).
 * Status sebelumnya dihitung hanya untuk baris di halaman yang tampil, dengan satu query LAG() per halaman.
 */
class RiwayatStatus extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $kamar = '';

    #[Url(except: '')]
    public string $status = '';

    /** id pengguna, atau "sistem" untuk log tanpa pengubah */
    #[Url(except: '')]
    public string $staf = '';

    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    #[Url(except: '')]
    public string $cari = '';

    /** Kamar yang lini masanya sedang dibuka */
    #[Locked]
    public ?int $kamarLini = null;

    public function mount(): void
    {
        Gate::authorize('view-reservations');
        // Bawaan 7 hari terakhir
        $this->dari = $this->tanggalSah($this->dari) ?? today()->subDays(6)->toDateString();
        $this->sampai = $this->tanggalSah($this->sampai) ?? today()->toDateString();
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['kamar', 'status', 'staf', 'dari', 'sampai', 'cari'], true)) {
            $this->resetPage();
        }
    }

    public function resetSaringan(): void
    {
        $this->reset('kamar', 'status', 'staf', 'cari');
        $this->dari = today()->subDays(6)->toDateString();
        $this->sampai = today()->toDateString();
        $this->resetPage();
    }

    public function lini(int $roomId): void
    {
        $this->kamarLini = Room::findOrFail($roomId)->id;
    }

    public function tutupLini(): void
    {
        $this->kamarLini = null;
    }

    private function tanggalSah(string $t): ?string
    {
        if ($t === '') {
            return null;
        }
        $d = rescue(fn () => Carbon::createFromFormat('!Y-m-d', $t), null, false);

        return $d && $d->format('Y-m-d') === $t ? $t : null;
    }

    /** @return array{0: Carbon, 1: Carbon} awal dan akhir rentang (akhir dibatasi sampai sekarang) */
    private function rentang(): array
    {
        $awal = Carbon::parse($this->tanggalSah($this->dari) ?? today()->subDays(6)->toDateString())->startOfDay();
        $akhir = Carbon::parse($this->tanggalSah($this->sampai) ?? today()->toDateString())->endOfDay();

        return [$awal, $akhir->min(now())];
    }

    /** "2 jam 15 menit", "3 hari 4 jam", "15 menit", "kurang dari 1 menit" */
    public static function lama(int $detik): string
    {
        $hari = intdiv($detik, 86400);
        $jam = intdiv($detik % 86400, 3600);
        $menit = intdiv($detik % 3600, 60);

        return match (true) {
            $hari > 0 => $hari.' hari'.($jam ? " {$jam} jam" : ''),
            $jam > 0 => $jam.' jam'.($menit ? " {$menit} menit" : ''),
            $menit > 0 => $menit.' menit',
            default => 'kurang dari 1 menit',
        };
    }

    /**
     * Status sebelumnya dan waktunya untuk baris di halaman ini saja: satu query dengan LAG() per kamar,
     * diurutkan changed_at lalu id (dua log bisa punya waktu yang sama). Log sebelumnya boleh di luar rentang penyaring.
     *
     * @return Collection<int, object{id: int, status_lalu: ?string, waktu_lalu: ?string}>
     */
    private function sebelumnya(Collection $baris): Collection
    {
        if ($baris->isEmpty()) {
            return collect();
        }
        $sub = DB::table('room_status_logs')->whereIn('room_id', $baris->pluck('room_id')->unique()->values())
            ->selectRaw('id, LAG(status) OVER (PARTITION BY room_id ORDER BY changed_at, id) AS status_lalu, '
                .'LAG(changed_at) OVER (PARTITION BY room_id ORDER BY changed_at, id) AS waktu_lalu');

        return DB::query()->fromSub($sub, 't')->whereIn('id', $baris->pluck('id'))->get()->keyBy('id');
    }

    /** Lini masa satu kamar dalam rentang: urutan perubahan dan total detik per status. */
    private function linimasa(Room $room): array
    {
        [$awal, $akhir] = $this->rentang();
        $awalStatus = RoomStatusLog::where('room_id', $room->id)->where('changed_at', '<', $awal)
            ->orderByDesc('changed_at')->orderByDesc('id')->first();
        $log = RoomStatusLog::with('changedBy')->where('room_id', $room->id)->whereBetween('changed_at', [$awal, $akhir])
            ->orderBy('changed_at')->orderBy('id')->get();

        // Potongan waktu: dari awal rentang (status terakhir sebelum rentang) sampai log berikutnya, dst.
        $total = [];
        $titik = $awalStatus ? collect([['status' => $awalStatus->status, 'mulai' => $awal]]) : collect();
        $titik = $titik->concat($log->map(fn ($l) => ['status' => $l->status, 'mulai' => $l->changed_at]))->values();
        foreach ($titik as $i => $t) {
            $selesai = $titik[$i + 1]['mulai'] ?? $akhir;
            $total[$t['status']->value] = ($total[$t['status']->value] ?? 0) + max(0, (int) $t['mulai']->diffInSeconds($selesai));
        }
        arsort($total);

        // Tautan laporan kerusakan yang disebut di catatan (MNT-0006)
        $kode = $log->flatMap(fn ($l) => preg_match_all('/MNT-\d+/', (string) $l->note, $m) ? $m[0] : [])->unique()->values();
        $laporan = $kode->isEmpty() ? collect() : MaintenanceRequest::whereIn('request_code', $kode)->pluck('id', 'request_code');
        $manager = Gate::allows('manage-maintenance');

        return [
            'room' => $room,
            'log' => $log,
            'awal' => $awalStatus,
            'total' => $total,
            'tautan' => $laporan->map(fn ($id, $k) => $manager ? route('staf.maintenance.index', ['cari' => $k]) : route('staf.maintenance.saya', ['lihat' => $id])),
        ];
    }

    public function render(): View
    {
        [$awal, $akhir] = $this->rentang();
        $kata = trim($this->cari);
        $logs = RoomStatusLog::with('room:id,room_number', 'changedBy:id,name')
            ->whereBetween('changed_at', [$awal, Carbon::parse($this->tanggalSah($this->sampai) ?? today()->toDateString())->endOfDay()])
            ->when($this->kamar !== '', fn ($q) => $q->where('room_id', (int) $this->kamar))
            ->when(RoomStatus::tryFrom($this->status), fn ($q, $s) => $q->where('status', $s->value))
            ->when($this->staf === 'sistem', fn ($q) => $q->whereNull('changed_by'))
            ->when(ctype_digit($this->staf), fn ($q) => $q->where('changed_by', (int) $this->staf))
            ->when($kata !== '', fn ($q) => $q->where('note', 'like', '%'.$kata.'%'))
            ->orderByDesc('changed_at')->orderByDesc('id')
            ->paginate(25);

        return view('livewire.staf.kamar.riwayat-status', [
            'logs' => $logs,
            'sebelum' => $this->sebelumnya(collect($logs->items())),
            'kamarPilihan' => Room::orderByRaw('CHAR_LENGTH(room_number)')->orderBy('room_number')->pluck('room_number', 'id'),
            'stafPilihan' => User::whereIn('id', RoomStatusLog::whereNotNull('changed_by')->select('changed_by')->distinct())->orderBy('name')->pluck('name', 'id'),
            'lini' => $this->kamarLini ? $this->linimasa(Room::findOrFail($this->kamarLini)) : null,
            'rentangTeks' => $awal->translatedFormat('j M Y').' – '.Carbon::parse($this->tanggalSah($this->sampai) ?? today())->translatedFormat('j M Y'),
        ])->layout('components.layouts.staf', ['title' => 'Riwayat Status']);
    }
}
