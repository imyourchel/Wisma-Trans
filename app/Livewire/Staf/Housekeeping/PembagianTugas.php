<?php

namespace App\Livewire\Staf\Housekeeping;

use App\Models\HousekeepingTask;
use App\Models\User;
use App\Services\SmartCleaning\PembagianTugasService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Pembagian Tugas (Manager): usulan Greedy dari batch prioritas terbaru hari ini, simpan, bagi ulang,
 * pindahkan tugas yang belum dimulai, dan verifikasi tugas yang selesai. Tugas yang sudah dimulai pada hari
 * sebelumnya tetapi belum selesai ditampilkan sebagai "Belum selesai kemarin".
 */
class PembagianTugas extends Component
{
    /** Usulan Greedy sedang ditampilkan (belum disimpan). */
    public bool $pratinjau = false;

    #[Locked]
    public ?int $pindahId = null;

    public string $pindahKe = '';

    public function mount(): void
    {
        Gate::authorize('manage-housekeeping');
    }

    public function buatUsulan(): void
    {
        Gate::authorize('manage-housekeeping');
        $this->pratinjau = true;
    }

    public function batalUsulan(): void
    {
        $this->pratinjau = false;
    }

    public function simpan(PembagianTugasService $layanan): void
    {
        Gate::authorize('manage-housekeeping');
        $hasil = $layanan->simpan(Auth::user(), today());
        $this->pratinjau = false;
        $this->dispatch('toast', teks: "{$hasil['dibuat']} tugas dibagikan.".($hasil['belum'] ? ' '.count($hasil['belum']).' kamar belum terbagi.' : ''),
            peringatan: (bool) $hasil['belum']);
    }

    public function bagiUlang(PembagianTugasService $layanan): void
    {
        Gate::authorize('manage-housekeeping');
        $hasil = $layanan->bagiUlang(Auth::user(), today());
        $this->pratinjau = false;
        $this->dispatch('toast', teks: "Tugas yang belum dimulai dibagi ulang: {$hasil['dibagi']} tugas.".($hasil['belum'] ? ' '.count($hasil['belum']).' kamar belum terbagi.' : ''),
            peringatan: (bool) $hasil['belum']);
    }

    public function mulaiPindah(int $id): void
    {
        Gate::authorize('manage-housekeeping');
        $this->pindahId = HousekeepingTask::whereKey($id)->where('status', 'assigned')->value('id');
        $this->pindahKe = '';
        $this->resetValidation();
    }

    public function batalPindah(): void
    {
        $this->pindahId = null;
        $this->resetValidation();
    }

    public function pindahkan(PembagianTugasService $layanan): void
    {
        Gate::authorize('manage-housekeeping');
        $this->validate(['pindahKe' => ['required', 'integer']], ['pindahKe.required' => 'Pilih staf tujuan.']);
        $t = HousekeepingTask::with('room')->findOrFail($this->pindahId);
        $peringatan = $layanan->pindahkan($t, User::findOrFail((int) $this->pindahKe), Auth::user());
        $this->pindahId = null;
        $this->dispatch('toast', teks: "Kamar {$t->room->room_number} dipindahkan.".($peringatan ? ' '.$peringatan : ''), peringatan: (bool) $peringatan);
    }

    public function verifikasi(int $id, PembagianTugasService $layanan): void
    {
        Gate::authorize('manage-housekeeping');
        $t = HousekeepingTask::with('room')->findOrFail($id);
        $layanan->verifikasi($t, Auth::user());
        $this->dispatch('toast', teks: "Kamar {$t->room->room_number} terverifikasi.");
    }

    public function render(PembagianTugasService $layanan): View
    {
        $usulan = $layanan->usulan(today());
        $tugas = HousekeepingTask::with(['room.roomType', 'assignees.user', 'item'])
            ->whereDate('task_date', today()->toDateString())->where('status', '!=', 'cancelled')
            ->get()->sortBy(fn ($t) => [array_search($t->status, ['in_progress', 'assigned', 'completed', 'verified'], true), $t->item?->priority_rank ?? 999, $t->id]);

        return view('livewire.staf.housekeeping.pembagian-tugas', [
            'usulan' => $usulan,
            'staf' => $usulan['staf'],
            'tugasPerStaf' => $tugas->flatMap(fn ($t) => $t->assignees->map(fn ($a) => ['user_id' => $a->user_id, 'tugas' => $t]))
                ->groupBy('user_id')->map(fn ($g) => $g->pluck('tugas')),
            'menunggu' => collect($usulan['penugasan'] ?? [])->flatten(1)->concat($usulan['belum'] ?? [])->sortBy('peringkat')->values(),
            'pilihanStaf' => PembagianTugasService::stafHousekeeping(),
            'belumSelesaiKemarin' => HousekeepingTask::with(['room', 'assignees.user'])->where('status', 'in_progress')
                ->whereDate('task_date', '<', today()->toDateString())->orderBy('task_date')->orderBy('id')->get(),
        ])->layout('components.layouts.staf', ['title' => 'Pembagian Tugas']);
    }
}
