<?php

namespace App\Services\SmartCleaning;

use App\Enums\RoomStatus;
use App\Models\CleaningCriterion;
use App\Models\CleaningPriorityBatch;
use App\Models\CleaningPriorityItem;
use App\Models\CleaningPriorityScore;
use App\Models\Room;
use App\Models\User;
use App\Services\AhpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modul 6 Smart Cleaning Priority: nilai kriteria tiap kamar kotor (NilaiKriteria), bobot AHP dari sesi aktif,
 * lalu peringkat SAW. Setiap perhitungan disimpan sebagai batch; batch terbaru pada tanggal itu yang berlaku.
 */
class PrioritasPembersihanService
{
    public function __construct(private AhpService $ahp, private NilaiKriteria $nilai, private SawService $saw) {}

    /** Kriteria aktif urut kode, dalam bentuk yang dipakai SawService. */
    public function kriteria(): Collection
    {
        return CleaningCriterion::where('is_active', true)->orderBy('code')->get();
    }

    public function hitungBatch(User $oleh, ?Carbon $sekarang = null): CleaningPriorityBatch
    {
        $sekarang ??= now();
        $sesi = $this->ahp->sesiAktif();
        if (! $sesi) {
            throw ValidationException::withMessages(['batch' => 'Bobot kriteria belum tersedia.']);
        }
        $bobot = $this->ahp->bobot((int) $sesi->id);
        $kriteria = $this->kriteria();
        if ($kriteria->isEmpty() || $kriteria->contains(fn ($k) => ! isset($bobot[$k->id]))) {
            throw ValidationException::withMessages(['batch' => 'Bobot kriteria belum tersedia.']);
        }

        return DB::transaction(function () use ($oleh, $sekarang, $sesi, $bobot, $kriteria) {
            $mentah = $this->nilai->hitung($sekarang);
            if ($mentah === []) {
                throw ValidationException::withMessages(['batch' => 'Tidak ada kamar yang perlu dibersihkan saat ini.']);
            }

            $hasil = $this->saw->hitung(
                collect($mentah)->map(fn ($m, $roomId) => [
                    'kunci' => $roomId,
                    'nomor' => $m['room']->room_number,
                    'nilai' => $kriteria->mapWithKeys(fn ($k) => [$k->id => NilaiKriteria::nilai($k->code, $m)])->all(),
                ])->values()->all(),
                $kriteria->map(fn ($k) => ['id' => $k->id, 'code' => $k->code, 'attribute_type' => $k->attribute_type])->all(),
                $bobot,
            );

            $dibersihkan = Room::active()->where('status', RoomStatus::Cleaning->value)->count();
            $batch = CleaningPriorityBatch::create([
                'batch_date' => $sekarang->toDateString(),
                'generated_at' => $sekarang,
                'generated_by' => $oleh->id,
                'ahp_session_id' => $sesi->id,
                'note' => count($hasil).' kamar'.($dibersihkan ? "; {$dibersihkan} kamar sedang dibersihkan tidak ikut" : ''),
            ]);

            foreach ($hasil as $h) {
                $item = CleaningPriorityItem::create([
                    'cleaning_priority_batch_id' => $batch->id,
                    'room_id' => $h['kunci'],
                    'preference_score' => $h['v'],
                    'priority_rank' => $h['peringkat'],
                ]);
                foreach ($kriteria as $k) {
                    CleaningPriorityScore::create([
                        'cleaning_priority_item_id' => $item->id,
                        'criteria_id' => $k->id,
                        'raw_value' => $h['nilai'][$k->id],
                        'normalized_value' => $h['normal'][$k->id],
                        'weighted_value' => $h['terbobot'][$k->id],
                    ]);
                }
            }

            return $batch;
        });
    }

    /**
     * Kamar kotor yang belum ada di batch terbaru, misalnya tamu check-out setelah perhitungan terakhir.
     *
     * @return list<string> nomor kamar
     */
    public function kotorBaru(?CleaningPriorityBatch $batch): array
    {
        if (! $batch) {
            return [];
        }

        return Room::active()->whereIn('status', [RoomStatus::VacantDirty->value, RoomStatus::OccupiedDirty->value])
            // Belum ada di batch, atau sudah dibersihkan lalu kotor lagi setelah batch dihitung
            ->where(fn ($q) => $q->whereNotIn('id', CleaningPriorityItem::where('cleaning_priority_batch_id', $batch->id)->select('room_id'))
                ->orWhere('dirty_since', '>', $batch->generated_at))
            ->orderBy('room_number')->pluck('room_number')->all();
    }
}
