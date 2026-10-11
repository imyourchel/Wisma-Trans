<?php

namespace App\Services\SmartCleaning;

use App\Enums\RoomStatus;
use App\Models\CleaningPriorityBatch;
use App\Models\CleaningPriorityItem;
use App\Models\HousekeepingTask;
use App\Models\HousekeepingTaskAssignee;
use App\Models\Room;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modul 6 Workload Balancing: kamar dari batch prioritas terbaru dibagi ke staf Housekeeping yang hadir
 * dengan Greedy (GreedyService), lalu disimpan sebagai housekeeping_tasks. Manager bisa membagi ulang
 * tugas yang belum dimulai, memindahkan tugas, dan memverifikasi tugas yang selesai.
 */
class PembagianTugasService
{
    public function __construct(private GreedyService $greedy) {}

    /** Poin beban kamar menurut status kotornya (config/housekeeping.php). */
    public static function poin(RoomStatus $status): float
    {
        return (float) (config('housekeeping.poin')[$status->value] ?? 1.0);
    }

    public static function batasDefault(): float
    {
        return (float) config('housekeeping.batas_default', 4.0);
    }

    /** 4.0 → "4", 4.5 → "4,5". */
    public static function angka(float|string|null $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    }

    /** Akun aktif berperan Housekeeping, urut nama. */
    public static function stafHousekeeping(): Collection
    {
        return User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'housekeeping'))->orderBy('name')->get();
    }

    /**
     * Staf Housekeeping pada tanggal itu: kehadiran, batas, beban, dan jumlah tugas yang sudah ada.
     * Beban = jumlah workload_share tugas tanggal itu yang tidak dibatalkan, termasuk yang sudah selesai,
     * supaya pembagian adil sepanjang hari.
     *
     * @param  list<int>  $lepas  id tugas yang sedang dilepas (bagi ulang); tidak ikut dihitung sebagai beban
     * @return Collection<int, array{id: int, nama: string, hadir: bool, batas: float, beban: float, jumlah: int, rangkap: ?string, catatan: ?string, ketersediaan: ?StaffAvailability}>
     */
    public function staf(Carbon $tanggal, array $lepas = []): Collection
    {
        $hari = $tanggal->toDateString();
        $ketersediaan = StaffAvailability::whereDate('work_date', $hari)->get()->keyBy('user_id');
        $beban = HousekeepingTaskAssignee::query()
            ->join('housekeeping_tasks as t', 't.id', '=', 'housekeeping_task_assignees.housekeeping_task_id')
            ->whereDate('t.task_date', $hari)->where('t.status', '!=', 'cancelled')->whereNotIn('t.id', $lepas)
            ->groupBy('housekeeping_task_assignees.user_id')
            ->selectRaw('housekeeping_task_assignees.user_id, SUM(workload_share) beban, COUNT(*) jumlah')
            ->get()->keyBy('user_id');

        return self::stafHousekeeping()->map(function (User $u) use ($ketersediaan, $beban) {
            $k = $ketersediaan[$u->id] ?? null;

            return [
                'id' => $u->id,
                'nama' => $u->name,
                'hadir' => $k ? (bool) $k->is_available : true,
                'batas' => $k && $k->max_workload !== null ? (float) $k->max_workload : self::batasDefault(),
                'beban' => (float) ($beban[$u->id]->beban ?? 0),
                'jumlah' => (int) ($beban[$u->id]->jumlah ?? 0),
                'rangkap' => $k?->secondary_duty,
                'catatan' => $k?->note,
                'ketersediaan' => $k,
            ];
        })->values();
    }

    /**
     * Pratinjau pembagian (belum disimpan): kamar dari batch terbaru yang masih kotor dan belum punya tugas terbuka.
     *
     * @return array{batch: ?CleaningPriorityBatch, penugasan: array<int, list<array>>, beban: array<int, float>, belum: list<array>, staf: Collection}
     */
    public function usulan(Carbon $tanggal): array
    {
        return $this->susun($tanggal);
    }

    /**
     * @param  list<int>  $lepas  tugas assigned yang dilepas untuk dibagi ulang
     */
    private function susun(Carbon $tanggal, array $lepas = []): array
    {
        $batch = CleaningPriorityBatch::terbaru($tanggal);
        $staf = $this->staf($tanggal, $lepas);
        if (! $batch) {
            return ['batch' => null, 'penugasan' => [], 'beban' => [], 'belum' => [], 'staf' => $staf];
        }

        $hari = $tanggal->toDateString();
        $terbuka = HousekeepingTask::whereDate('task_date', $hari)->whereIn('status', HousekeepingTask::TERBUKA)
            ->whereNotIn('id', $lepas)->pluck('room_id')->all();
        $items = CleaningPriorityItem::with('room.roomType')->where('cleaning_priority_batch_id', $batch->id)->orderBy('priority_rank')->get()
            ->filter(fn ($i) => $i->room?->is_active && $i->room->status->isDirty() && ! in_array($i->room_id, $terbuka, true));

        $tugas = $items->map(fn ($i) => $this->tugas($i->room, $i))->values();

        // Tugas yang dilepas untuk kamar yang tidak ada di batch terbaru tetap ikut, di urutan paling belakang
        if ($lepas) {
            $sudah = $items->pluck('room_id')->all();
            HousekeepingTask::with('room.roomType', 'item')->whereIn('id', $lepas)->orderBy('id')->get()
                ->filter(fn ($t) => $t->room?->status->isDirty() && ! in_array($t->room_id, $sudah, true))
                ->each(fn ($t) => $tugas->push($this->tugas($t->room, $t->item)));
        }

        $hasil = $this->greedy->bagi($tugas->all(), $staf->all());

        return $hasil + ['batch' => $batch, 'staf' => $staf];
    }

    private function tugas(Room $room, ?CleaningPriorityItem $item): array
    {
        return [
            'kunci' => $room->id,
            'room_id' => $room->id,
            'nomor' => $room->room_number,
            'tipe' => $room->roomType?->name,
            'status' => $room->status->label(),
            'poin' => self::poin($room->status),
            'peringkat' => $item?->priority_rank,
            'item_id' => $item?->id,
        ];
    }

    /**
     * Simpan pembagian: usulan dihitung ulang di dalam transaksi (kamar dikunci), jadi kamar yang sejak pratinjau
     * sudah tidak kotor atau sudah dapat tugas dilewati.
     *
     * @return array{dibuat: int, belum: list<array>}
     */
    public function simpan(User $oleh, Carbon $tanggal): array
    {
        return DB::transaction(function () use ($oleh, $tanggal) {
            $this->kunciKamar();
            $u = $this->susun($tanggal);
            if (! $u['batch']) {
                throw ValidationException::withMessages(['pembagian' => 'Hitung prioritas dulu.']);
            }

            $dibuat = 0;
            foreach ($u['penugasan'] as $userId => $daftar) {
                foreach ($daftar as $t) {
                    $tugas = HousekeepingTask::create([
                        'room_id' => $t['room_id'],
                        'task_date' => $tanggal->toDateString(),
                        'cleaning_priority_item_id' => $t['item_id'],
                        'workload_point' => $t['poin'],
                        'assignment_method' => 'greedy',
                        'status' => 'assigned',
                        'assigned_by' => $oleh->id,
                    ]);
                    $tugas->assignees()->create(['user_id' => $userId, 'workload_share' => $t['poin']]);
                    $dibuat++;
                }
            }

            return ['dibuat' => $dibuat, 'belum' => $u['belum']];
        });
    }

    /**
     * Bagi ulang: tugas assigned (belum dimulai) hari itu dilepas lalu dibagi lagi bersama kamar yang belum terbagi.
     * Baris tugas dipakai ulang; tugas yang dilepas tetapi tidak muat atau kamarnya sudah tidak kotor dibatalkan.
     * Tugas yang sudah dimulai atau selesai tidak disentuh.
     *
     * @return array{dibagi: int, belum: list<array>}
     */
    public function bagiUlang(User $oleh, Carbon $tanggal): array
    {
        return DB::transaction(function () use ($oleh, $tanggal) {
            $this->kunciKamar();
            $lepas = HousekeepingTask::whereDate('task_date', $tanggal->toDateString())->where('status', 'assigned')
                ->lockForUpdate()->get()->keyBy('room_id');
            $u = $this->susun($tanggal, $lepas->pluck('id')->all());
            if (! $u['batch']) {
                throw ValidationException::withMessages(['pembagian' => 'Hitung prioritas dulu.']);
            }

            $dibagi = 0;
            $dipakai = [];
            foreach ($u['penugasan'] as $userId => $daftar) {
                foreach ($daftar as $t) {
                    $data = [
                        'cleaning_priority_item_id' => $t['item_id'] ?? ($lepas[$t['room_id']] ?? null)?->cleaning_priority_item_id,
                        'workload_point' => $t['poin'],
                        'assignment_method' => 'greedy',
                        'status' => 'assigned',
                        'assigned_by' => $oleh->id,
                        'note' => null,
                    ];
                    if ($lama = $lepas[$t['room_id']] ?? null) {
                        $lama->update($data);
                        $lama->assignees()->delete();
                        $tugas = $lama;
                        $dipakai[] = $lama->id;
                    } else {
                        $tugas = HousekeepingTask::create($data + ['room_id' => $t['room_id'], 'task_date' => $tanggal->toDateString()]);
                    }
                    $tugas->assignees()->create(['user_id' => $userId, 'workload_share' => $t['poin']]);
                    $dibagi++;
                }
            }

            // Dilepas tetapi tidak dibagi lagi: tidak muat di staf mana pun, atau kamarnya sudah tidak kotor
            foreach ($lepas->whereNotIn('id', $dipakai) as $sisa) {
                $sisa->assignees()->delete();
                $sisa->update(['status' => 'cancelled', 'note' => 'Dibatalkan saat bagi ulang: '
                    .($sisa->room->fresh()->status->isDirty() ? 'tidak muat di beban staf mana pun' : 'kamar sudah tidak kotor')]);
            }

            return ['dibagi' => $dibagi, 'belum' => $u['belum']];
        });
    }

    /**
     * Pindahkan tugas yang belum dimulai ke staf lain. Boleh melebihi batas; peringatan dikembalikan.
     *
     * @return ?string peringatan bila beban staf tujuan melebihi batasnya
     */
    public function pindahkan(HousekeepingTask $t, User $ke, User $oleh): ?string
    {
        if (! $ke->is_active || ! $ke->loadMissing('roles')->hasRole('housekeeping')) {
            throw ValidationException::withMessages(['ke' => 'Pilih staf Housekeeping yang aktif.']);
        }

        return DB::transaction(function () use ($t, $ke, $oleh) {
            $t = HousekeepingTask::with('assignees.user')->lockForUpdate()->findOrFail($t->id);
            if ($t->status !== 'assigned') {
                throw ValidationException::withMessages(['ke' => 'Hanya tugas yang belum dimulai yang bisa dipindahkan.']);
            }
            if ($t->assignees->pluck('user_id')->all() === [$ke->id]) {
                throw ValidationException::withMessages(['ke' => "Tugas ini sudah milik {$ke->name}."]);
            }

            $dari = $t->namaStaf() ?: 'belum ada';
            $t->assignees()->delete();
            $t->assignees()->create(['user_id' => $ke->id, 'workload_share' => $t->workload_point]);
            $t->update(['assignment_method' => 'manual', 'note' => "Dipindah dari {$dari} ke {$ke->name} oleh {$oleh->name}"]);

            $s = $this->staf($t->task_date)->firstWhere('id', $ke->id);

            return $s && $s['beban'] > $s['batas'] + 1e-9
                ? "Beban {$ke->name} menjadi ".self::angka($s['beban']).' dari batas '.self::angka($s['batas']).'.'
                : null;
        });
    }

    public function verifikasi(HousekeepingTask $t, User $oleh): void
    {
        $t = HousekeepingTask::lockForUpdate()->findOrFail($t->id);
        if ($t->status !== 'completed') {
            throw ValidationException::withMessages(['status' => 'Hanya tugas yang sudah selesai yang bisa diverifikasi.']);
        }
        $t->update(['status' => 'verified', 'verified_by' => $oleh->id]);
    }

    /** Kunci baris kamar kotor supaya dua Manager yang menyimpan bersamaan tidak membuat tugas ganda. */
    private function kunciKamar(): void
    {
        Room::active()->whereIn('status', [RoomStatus::VacantDirty->value, RoomStatus::OccupiedDirty->value])->lockForUpdate()->get(['id']);
    }
}
