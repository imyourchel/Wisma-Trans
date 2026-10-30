<?php

namespace App\Livewire\Staf\Aula;

use App\Models\HallBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Kalender Aula: pemesanan per hari dalam satu bulan (menunggu, terkonfirmasi, selesai). Klik untuk membuka detail.
 * Pengguna lihat saja (F&B) hanya melihat yang terkonfirmasi dan selesai (PemesananAula::lihatSaja()).
 */
class KalenderAula extends Component
{
    /** Bulan yang ditampilkan, format Y-m; kosong = bulan ini. */
    #[Url(except: '')]
    public string $bulan = '';

    public function mount(): void
    {
        Gate::authorize('view-halls');
    }

    private function awal(): CarbonImmutable
    {
        try {
            return $this->bulan !== '' ? CarbonImmutable::createFromFormat('!Y-m', $this->bulan)->startOfMonth() : today()->toImmutable()->startOfMonth();
        } catch (Throwable) {
            return today()->toImmutable()->startOfMonth();
        }
    }

    public function geser(int $n): void
    {
        $baru = $n === 0 ? today()->toImmutable()->startOfMonth() : $this->awal()->addMonths(max(-24, min(24, $n)));
        $this->bulan = $baru->isSameMonth(today()) ? '' : $baru->format('Y-m');
    }

    public function render(): View
    {
        $awal = $this->awal();
        $mulaiGrid = $awal->startOfWeek(CarbonImmutable::MONDAY);
        $akhirGrid = $awal->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();

        $lihatSaja = PemesananAula::lihatSaja();
        $pemesanan = HallBooking::with('hall', 'customer')
            ->whereIn('status', $lihatSaja ? PemesananAula::STATUS_LIHAT_SAJA : ['pending', 'confirmed', 'completed'])
            ->where('start_at', '<', $akhirGrid->addDay())
            ->where('end_at', '>', $mulaiGrid)
            ->orderBy('start_at')->get();

        $hari = [];
        for ($d = $mulaiGrid; $d->lte($akhirGrid); $d = $d->addDay()) {
            $hari[] = [
                'tanggal' => $d,
                'pemesanan' => $pemesanan->filter(fn ($b) => $b->start_at->lt($d->addDay()) && $b->end_at->gt($d))->values(),
            ];
        }

        return view('livewire.staf.aula.kalender-aula', [
            'awal' => $awal,
            'hari' => $hari,
            'jumlah' => $pemesanan->filter(fn ($b) => $b->start_at->isSameMonth($awal))->countBy('status'),
            'lihatSaja' => $lihatSaja,
        ])->layout('components.layouts.staf', ['title' => 'Kalender Aula']);
    }
}
