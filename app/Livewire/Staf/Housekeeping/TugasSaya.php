<?php

namespace App\Livewire\Staf\Housekeeping;

use App\Models\HousekeepingTask;
use App\Services\SmartCleaning\BatasBebanStaf;
use App\Services\SmartCleaning\NilaiKriteria;
use App\Services\SmartCleaning\PembagianTugasService;
use App\Services\SmartCleaning\TugasHousekeepingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Tugas Saya (Housekeeping): daftar kamar hari ini urut prioritas, tombol Mulai dan Selesai. */
class TugasSaya extends Component
{
    /** Tugas yang sedang diisi catatan selesainya. */
    #[Locked]
    public ?int $selesaiId = null;

    public string $catatan = '';

    public function mount(): void
    {
        Gate::authorize('do-housekeeping-task');
    }

    private function milikSaya(int $id): HousekeepingTask
    {
        return HousekeepingTask::with('room')->whereKey($id)
            ->whereHas('assignees', fn ($q) => $q->where('user_id', Auth::id()))->first() ?? abort(404);
    }

    public function mulai(int $id, TugasHousekeepingService $layanan): void
    {
        Gate::authorize('do-housekeeping-task');
        $t = $this->milikSaya($id);
        $layanan->mulai($t, Auth::user());
        $this->dispatch('toast', teks: "Kamar {$t->room->room_number} mulai dibersihkan.");
    }

    public function bukaSelesai(int $id): void
    {
        Gate::authorize('do-housekeeping-task');
        $this->selesaiId = $this->milikSaya($id)->id;
        $this->catatan = '';
        $this->resetValidation();
    }

    public function batalSelesai(): void
    {
        $this->selesaiId = null;
    }

    public function selesai(TugasHousekeepingService $layanan): void
    {
        Gate::authorize('do-housekeeping-task');
        $this->validate(['catatan' => ['nullable', 'string', 'max:255']], ['catatan.max' => 'Maksimal 255 karakter.']);
        $t = $this->milikSaya((int) $this->selesaiId);
        $peringatan = $layanan->selesai($t, Auth::user(), $this->catatan);
        $this->selesaiId = null;
        $this->catatan = '';
        $peringatan
            ? $this->dispatch('toast', teks: $peringatan, peringatan: true)
            : $this->dispatch('toast', teks: "Kamar {$t->room->fresh()->room_number} selesai dibersihkan ({$t->room->fresh()->status->label()}).");
    }

    public function render(NilaiKriteria $nilai, PembagianTugasService $pembagian): View
    {
        $saya = Auth::user();
        $tugas = HousekeepingTask::with(['room.roomType', 'item', 'assignees'])
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereDate('task_date', today()->toDateString())->where('status', '!=', 'cancelled'))
                // Dimulai hari sebelumnya tetapi belum selesai: tetap tampil supaya bisa diselesaikan
                ->orWhere(fn ($q) => $q->whereDate('task_date', '<', today()->toDateString())->where('status', 'in_progress')))
            ->whereHas('assignees', fn ($q) => $q->where('user_id', $saya->id))
            ->get()
            ->sortBy(fn ($t) => [in_array($t->status, ['completed', 'verified'], true) ? 1 : 0, $t->status === 'in_progress' ? 0 : 1,
                $t->item?->priority_rank ?? 999, $t->id])
            ->values();

        $kotor = $tugas->pluck('room')->filter(fn ($r) => $r->status->isDirty())->unique('id')->values();
        $s = $pembagian->staf(today())->firstWhere('id', $saya->id);

        return view('livewire.staf.housekeeping.tugas-saya', [
            'tugas' => $tugas,
            'hariIni' => $hariIni = $tugas->filter(fn ($t) => $t->task_date->isToday()),
            'keterangan' => $kotor->isNotEmpty() ? collect($nilai->hitung(now(), $kotor))->map(fn ($m) => $m['keterangan']) : collect(),
            'selesai' => $hariIni->whereIn('status', ['completed', 'verified'])->count(),
            'beban' => $hariIni->sum(fn ($t) => (float) ($t->assignees->firstWhere('user_id', $saya->id)?->workload_share ?? 0)),
            'batas' => $s['batas'] ?? BatasBebanStaf::untuk($saya, today())[0],
        ])->layout('components.layouts.staf', ['title' => 'Tugas Saya']);
    }
}
