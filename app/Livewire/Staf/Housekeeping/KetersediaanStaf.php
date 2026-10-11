<?php

namespace App\Livewire\Staf\Housekeeping;

use App\Models\HousekeepingTask;
use App\Models\StaffAvailability;
use App\Services\SmartCleaning\PembagianTugasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ketersediaan staf Housekeeping per tanggal (hari ini sampai 7 hari ke depan): hadir, tugas rangkap, batas beban.
 * Staf tanpa baris dianggap hadir dengan batas default (config/housekeeping.php).
 */
class KetersediaanStaf extends Component
{
    public const HARI_KE_DEPAN = 7;

    #[Url(except: '')]
    public string $tanggal = '';

    /** id staf => [hadir, rangkap, batas, catatan] */
    public array $baris = [];

    public function mount(): void
    {
        Gate::authorize('manage-housekeeping');
        $this->tanggal = $this->tanggalSah($this->tanggal)->toDateString();
        $this->muat();
    }

    public function updatedTanggal(): void
    {
        $this->tanggal = $this->tanggalSah($this->tanggal)->toDateString();
        $this->resetValidation();
        $this->muat();
    }

    private function tanggalSah(string $t): Carbon
    {
        $hari = rescue(fn () => Carbon::createFromFormat('Y-m-d', $t)->startOfDay(), today(), false);

        return $hari->lt(today()) || $hari->gt(today()->addDays(self::HARI_KE_DEPAN)) ? today() : $hari;
    }

    private function muat(): void
    {
        $ada = StaffAvailability::whereDate('work_date', $this->tanggal)->get()->keyBy('user_id');
        $this->baris = PembagianTugasService::stafHousekeeping()->mapWithKeys(function ($u) use ($ada) {
            $k = $ada->get($u->id);

            return [$u->id => [
                'hadir' => $k ? (bool) $k->is_available : true,
                'rangkap' => (string) $k?->secondary_duty,
                'batas' => $k?->max_workload !== null ? PembagianTugasService::angka($k->max_workload) : '',
                'catatan' => (string) $k?->note,
            ]];
        })->all();
    }

    public function simpan(): void
    {
        Gate::authorize('manage-housekeeping');
        $staf = PembagianTugasService::stafHousekeeping()->keyBy('id');
        // "4,5" dari isian dibaca 4.5
        foreach ($this->baris as $id => $b) {
            $this->baris[$id]['batas'] = str_replace(',', '.', trim((string) ($b['batas'] ?? '')));
        }
        $this->validate([
            'baris' => ['array'],
            'baris.*.hadir' => ['boolean'],
            'baris.*.rangkap' => ['nullable', 'string', 'max:50'],
            'baris.*.batas' => ['nullable', 'numeric', 'min:0.5', 'max:10', 'multiple_of:0.5'],
            'baris.*.catatan' => ['nullable', 'string', 'max:255'],
        ], [
            'baris.*.rangkap.max' => 'Maksimal 50 karakter.',
            'baris.*.batas.numeric' => 'Isi angka, misalnya 4 atau 3,5.',
            'baris.*.batas.min' => 'Minimal 0,5.',
            'baris.*.batas.max' => 'Maksimal 10.',
            'baris.*.batas.multiple_of' => 'Kelipatan 0,5.',
            'baris.*.catatan.max' => 'Maksimal 255 karakter.',
        ]);

        DB::transaction(function () use ($staf) {
            foreach ($this->baris as $id => $b) {
                if (! $staf->has($id)) {
                    continue;
                }
                StaffAvailability::updateOrCreate(['user_id' => $id, 'work_date' => $this->tanggal], [
                    'is_available' => (bool) $b['hadir'],
                    'secondary_duty' => filled($b['rangkap']) ? trim($b['rangkap']) : null,
                    'max_workload' => filled($b['batas']) ? (float) $b['batas'] : null,
                    'note' => filled($b['catatan']) ? trim($b['catatan']) : null,
                ]);
            }
        });

        $this->muat();
        $this->dispatch('toast', teks: 'Ketersediaan staf '.Carbon::parse($this->tanggal)->translatedFormat('j M Y').' disimpan.');
    }

    public function render(): View
    {
        $staf = PembagianTugasService::stafHousekeeping();
        $hariIni = $this->tanggal === today()->toDateString();

        // Staf yang tidak hadir padahal masih punya tugas yang belum dimulai pada tanggal itu
        $tidakHadir = StaffAvailability::whereDate('work_date', $this->tanggal)->where('is_available', false)->pluck('user_id');
        $tertinggal = HousekeepingTask::whereDate('task_date', $this->tanggal)->where('status', 'assigned')
            ->join('housekeeping_task_assignees as a', 'a.housekeeping_task_id', '=', 'housekeeping_tasks.id')
            ->whereIn('a.user_id', $tidakHadir)->selectRaw('a.user_id, COUNT(*) n')->groupBy('a.user_id')->pluck('n', 'user_id');

        return view('livewire.staf.housekeeping.ketersediaan-staf', [
            'staf' => $staf,
            'batasDefault' => PembagianTugasService::angka(PembagianTugasService::batasDefault()),
            'peringatan' => $tertinggal->map(fn ($n, $id) => Str::before((string) $staf->firstWhere('id', $id)?->name, ' ')
                ." masih punya {$n} tugas ".($hariIni ? 'hari ini' : 'pada tanggal ini').'. Bagi ulang tugas?')->values(),
            'hariIni' => $hariIni,
            'maks' => today()->addDays(self::HARI_KE_DEPAN)->toDateString(),
        ])->layout('components.layouts.staf', ['title' => 'Ketersediaan Staf']);
    }
}
