<?php

namespace App\Livewire\Staf\Housekeeping;

use App\Models\CleaningPriorityBatch;
use App\Models\HousekeepingTask;
use App\Services\SmartCleaning\NilaiKriteria;
use App\Services\SmartCleaning\PrioritasPembersihanService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Prioritas Pembersihan (Manager): hitung peringkat kamar kotor dengan AHP-SAW dan lihat perhitungannya.
 * Nilai pada tabel diambil dari batch tersimpan; keterangan waktu menuju check-in dihitung dari keadaan sekarang.
 */
class PrioritasPembersihan extends Component
{
    public bool $lihatPerhitungan = false;

    public function mount(): void
    {
        Gate::authorize('view-cleaning-priority');
    }

    public function hitung(PrioritasPembersihanService $layanan): void
    {
        Gate::authorize('view-cleaning-priority');
        $batch = $layanan->hitungBatch(Auth::user());
        $this->dispatch('toast', teks: 'Prioritas dihitung: '.$batch->note.'.');
    }

    public function render(PrioritasPembersihanService $layanan, NilaiKriteria $nilai): View
    {
        $batch = CleaningPriorityBatch::terbaru(today());
        $items = $batch
            ? $batch->items()->with(['room.roomType', 'scores'])->get()
            : collect();

        // Keterangan C1 dan C3 dari keadaan sekarang, untuk kamar yang masih kotor
        $sekarang = $items->isNotEmpty() ? $nilai->hitung(now(), $items->pluck('room')->filter(fn ($r) => $r->status->isDirty())->values()) : [];

        $tugas = HousekeepingTask::with('assignees.user')->whereDate('task_date', today()->toDateString())
            ->whereIn('room_id', $items->pluck('room_id'))->where('status', '!=', 'cancelled')
            ->orderByDesc('id')->get()->unique('room_id')->keyBy('room_id');

        return view('livewire.staf.housekeeping.prioritas-pembersihan', [
            'batch' => $batch?->loadMissing('generatedBy'),
            'items' => $items,
            'kriteria' => $layanan->kriteria(),
            'keterangan' => collect($sekarang)->map(fn ($m) => $m['keterangan']),
            'tugas' => $tugas,
            'kotorBaru' => $layanan->kotorBaru($batch),
        ])->layout('components.layouts.staf', ['title' => 'Prioritas Pembersihan']);
    }
}
