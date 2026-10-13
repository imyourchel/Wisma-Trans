<?php

namespace App\Livewire\Staf\Housekeeping;

use App\Models\HousekeepingTask;
use App\Models\StaffAvailability;
use App\Services\SmartCleaning\BatasBebanStaf;
use App\Services\SmartCleaning\PembagianTugasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ketersediaan staf Housekeeping per tanggal (sampai 7 hari ke depan; tanggal lewat hanya dilihat): hadir, batas beban, catatan.
 * Tugas rangkap hanya tampilan, dibaca dari peran staf (user_roles). Batas kosong, atau sama dengan batas otomatisnya,
 * disimpan NULL dan berarti batas otomatis (BatasBebanStaf): multi-role atau HK murni, angkanya dari settings.
 * Karena NULL, perubahan settings ikut berlaku untuk semua tanggal. Staf tanpa baris dianggap hadir.
 */
class KetersediaanStaf extends Component
{
    public const HARI_KE_DEPAN = 7;

    public const BATAS_MIN = 0.5;

    public const BATAS_MAKS = 20;

    #[Url(except: '')]
    public string $tanggal = '';

    /** id staf => [hadir, batas, catatan] */
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

    /** Tanggal bawaan hari ini (zona waktu aplikasi, WITA); tanggal lewat boleh dibuka untuk dilihat. */
    private function tanggalSah(string $t): Carbon
    {
        $hari = rescue(fn () => Carbon::createFromFormat('Y-m-d', $t)->startOfDay(), today(), false);

        return $hari->gt(today()->addDays(self::HARI_KE_DEPAN)) ? today() : $hari;
    }

    /** Tanggal sebelum hari ini: hanya bisa dilihat, tidak bisa disimpan. */
    private function sudahLewat(): bool
    {
        return Carbon::parse($this->tanggal)->lt(today());
    }

    private function muat(): void
    {
        $ada = StaffAvailability::whereDate('work_date', $this->tanggal)->get()->keyBy('user_id');
        $this->baris = PembagianTugasService::stafHousekeeping()->mapWithKeys(function ($u) use ($ada) {
            $k = $ada->get($u->id);

            return [$u->id => [
                'hadir' => $k ? (bool) $k->is_available : true,
                'batas' => $k?->max_workload !== null ? PembagianTugasService::angka($k->max_workload) : '',
                'catatan' => (string) $k?->note,
            ]];
        })->all();
    }

    public function simpan(): void
    {
        Gate::authorize('manage-housekeeping');
        if ($this->sudahLewat()) {
            $this->dispatch('toast', teks: 'Tanggal sudah lewat, ketersediaan hanya bisa dilihat.', peringatan: true);

            return;
        }
        $staf = PembagianTugasService::stafHousekeeping()->keyBy('id');
        foreach ($this->baris as $id => $b) {
            // "4,5" dari isian dibaca 4.5
            $this->baris[$id]['batas'] = str_replace(',', '.', trim((string) ($b['batas'] ?? '')));
            // Staf tidak hadir: batas beban tidak berlaku, catatan tetap
            if (! ($b['hadir'] ?? true)) {
                $this->baris[$id]['batas'] = '';
            }
        }
        $batas = self::BATAS_MIN.' sampai '.self::BATAS_MAKS;
        $this->validate([
            'baris' => ['array'],
            'baris.*.hadir' => ['boolean'],
            'baris.*.batas' => ['nullable', 'numeric', 'min:'.self::BATAS_MIN, 'max:'.self::BATAS_MAKS, 'multiple_of:0.5'],
            'baris.*.catatan' => ['nullable', 'string', 'max:255'],
        ], [
            'baris.*.batas.numeric' => 'Isi angka, misalnya 2 atau 2,5.',
            'baris.*.batas.min' => 'Batas beban '.str_replace('.', ',', $batas).' poin.',
            'baris.*.batas.max' => 'Batas beban '.str_replace('.', ',', $batas).' poin.',
            'baris.*.batas.multiple_of' => 'Isi kelipatan 0,5, misalnya 2 atau 2,5.',
            'baris.*.catatan.max' => 'Maksimal 255 karakter.',
        ]);

        DB::transaction(function () use ($staf) {
            foreach ($this->baris as $id => $b) {
                if (! $staf->has($id)) {
                    continue;
                }
                StaffAvailability::updateOrCreate(['user_id' => $id, 'work_date' => $this->tanggal], [
                    'is_available' => (bool) $b['hadir'],
                    // Kosong, atau sama dengan batas otomatisnya, = NULL: batas otomatis dibaca saat pembagian tugas
                    'max_workload' => $b['hadir'] && filled($b['batas']) ? BatasBebanStaf::untukDisimpan($staf[$id], (float) $b['batas']) : null,
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
            // Batas otomatis untuk teks bantuan, placeholder, dan badge per baris (angkanya dari settings)
            'otomatis' => collect(BatasBebanStaf::SETTING)->map(fn ($k, $jenis) => PembagianTugasService::angka(BatasBebanStaf::otomatis($jenis))),
            'batasStaf' => $staf->mapWithKeys(fn ($u) => [$u->id => BatasBebanStaf::otomatisUntuk($u)]),
            // Tugas rangkap = peran operasional lain dari user_roles; HK murni "—"
            'rangkap' => $staf->mapWithKeys(fn ($u) => [$u->id => implode(', ', BatasBebanStaf::peranLain($u)) ?: '—']),
            'poin' => collect(config('housekeeping.poin'))->map(fn ($p) => PembagianTugasService::angka($p)),
            'peringatan' => $tertinggal->map(fn ($n, $id) => Str::before((string) $staf->firstWhere('id', $id)?->name, ' ')
                ." masih punya {$n} tugas ".($hariIni ? 'hari ini' : 'pada tanggal ini').'. Bagi ulang tugas?')->values(),
            'hariIni' => $hariIni,
            'lewat' => $this->sudahLewat(),
            // Pembagian tugas tanggal itu sudah ada: perubahan ketersediaan baru berlaku bila dibagi ulang
            'sudahDibagi' => HousekeepingTask::whereDate('task_date', $this->tanggal)->where('status', '!=', 'cancelled')->exists(),
            'besok' => today()->addDay()->toDateString(),
            'maks' => today()->addDays(self::HARI_KE_DEPAN)->toDateString(),
        ])->layout('components.layouts.staf', ['title' => 'Ketersediaan Staf']);
    }
}
