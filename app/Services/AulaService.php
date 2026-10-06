<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Nomor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemesanan aula: pengajuan (tamu atau staf), harga usulan, persetujuan Manager, penolakan, selesai, dan batal.
 * Jadwal tidak boleh bentrok dengan pemesanan lain yang menunggu atau terkonfirmasi; yang ditolak/dibatalkan diabaikan.
 * Aula perorangan dibuatkan nota hall saat disetujui; aula instansi ditagih bersama tagihan instansinya.
 */
class AulaService
{
    public function __construct(private NotaService $nota) {}

    /** Pemesanan lain yang jadwalnya beririsan. */
    public function bentrok(int $hallId, CarbonInterface $mulai, CarbonInterface $selesai, ?int $kecuali = null): ?HallBooking
    {
        return HallBooking::where('hall_id', $hallId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_at', '<', $selesai)
            ->where('end_at', '>', $mulai)
            ->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali))
            ->orderBy('start_at')
            ->first();
    }

    public function ajukan(Customer $penyewa, Hall $aula, CarbonInterface $mulai, CarbonInterface $selesai, ?int $peserta, ?string $keperluan, ?string $catatan, ?float $harga = null, ?User $oleh = null): HallBooking
    {
        $this->periksaJadwal($aula, $mulai, $selesai, $peserta);

        return DB::transaction(function () use ($penyewa, $aula, $mulai, $selesai, $peserta, $keperluan, $catatan, $harga, $oleh) {
            // Kunci aula agar dua pengajuan bersamaan tidak sama-sama lolos pengecekan bentrok
            Hall::whereKey($aula->id)->lockForUpdate()->first();
            $this->tolakBilaBentrok($aula, $mulai, $selesai, null);

            return HallBooking::create([
                'booking_code' => Nomor::berikut('hall_bookings', 'booking_code', 'HB-'),
                'hall_id' => $aula->id,
                'customer_id' => $penyewa->id,
                'start_at' => $mulai,
                'end_at' => $selesai,
                'participant_count' => $peserta,
                'rental_price' => $harga ?? 0,
                'purpose' => $keperluan,
                'status' => 'pending',
                'created_by' => $oleh?->id,
                'note' => $catatan,
            ]);
        });
    }

    /** Harga usulan hasil perundingan staf dengan penyewa (sebelum disetujui Manager). */
    public function ubahHarga(HallBooking $b, float $harga): void
    {
        if ($b->status !== 'pending') {
            throw ValidationException::withMessages(['harga' => 'Harga hanya bisa diubah selama menunggu persetujuan.']);
        }
        $b->update(['rental_price' => $harga]);
    }

    /**
     * Manager menyetujui dengan harga akhir. $instansi diisi bila sewa ditagihkan ke instansi (tanpa nota sendiri).
     */
    public function setujui(HallBooking $b, float $harga, ?Institution $instansi, ?string $catatan, User $oleh): void
    {
        if ($b->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Pemesanan ini sudah diputuskan.']);
        }
        if ($harga <= 0) {
            throw ValidationException::withMessages(['harga' => 'Isi harga sewa yang sudah disepakati.']);
        }

        DB::transaction(function () use ($b, $harga, $instansi, $catatan, $oleh) {
            Hall::whereKey($b->hall_id)->lockForUpdate()->first();
            $this->tolakBilaBentrok($b->hall, $b->start_at, $b->end_at, $b->id);

            $b->fill(['status' => 'confirmed', 'rental_price' => $harga]);
            if ($instansi) {
                $penyewa = $b->customer;
                if ($penyewa && $penyewa->institution_id === null) {
                    $penyewa->update(['institution_id' => $instansi->id]);
                }
                $b->note = self::tambahCatatan($b->note, 'Ditagihkan ke '.$instansi->name.' bersama tagihan instansi'.($catatan ? '; '.$catatan : ''));
            } else {
                $nota = Invoice::create([
                    'invoice_number' => Nomor::nota(),
                    'invoice_type' => 'hall',
                    'customer_id' => $b->customer_id,
                    'payment_term' => 'immediate',
                    'issue_date' => today()->toDateString(),
                    // Lunas paling lambat sehari sebelum acara
                    'due_date' => $b->start_at->copy()->subDay()->toDateString(),
                    'status' => 'issued',
                    'issued_by' => $oleh->id,
                    'note' => 'Sewa aula '.$b->booking_code,
                ]);
                $nota->items()->create([
                    'item_type' => 'hall',
                    'description' => 'Sewa '.$b->hall->name.' '.$b->start_at->translatedFormat('j M').($b->participant_count ? ', '.$b->participant_count.' peserta' : ''),
                    'service_date' => $b->start_at->toDateString(),
                    'quantity' => 1,
                    'unit_price' => $harga,
                    'subtotal' => $harga,
                ]);
                $this->nota->hitungUlang($nota);
                $b->invoice_id = $nota->id;
                if ($catatan) {
                    $b->note = self::tambahCatatan($b->note, $catatan);
                }
            }
            $b->save();
        });
    }

    public function tolak(HallBooking $b, string $alasan): void
    {
        if ($b->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Pemesanan ini sudah diputuskan.']);
        }
        $b->update(['status' => 'rejected', 'note' => self::tambahCatatan($b->note, 'Ditolak: '.$alasan)]);
    }

    public function selesai(HallBooking $b): void
    {
        if ($b->status !== 'confirmed' || $b->end_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'Hanya acara terkonfirmasi yang sudah berakhir yang bisa ditandai selesai.']);
        }
        $b->update(['status' => 'completed']);
    }

    public function batalkan(HallBooking $b, string $alasan): void
    {
        if (! in_array($b->status, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages(['status' => 'Pemesanan ini tidak bisa dibatalkan.']);
        }
        DB::transaction(function () use ($b, $alasan) {
            $b->update(['status' => 'cancelled', 'note' => self::tambahCatatan($b->note, 'Dibatalkan: '.$alasan)]);
            if ($b->invoice && (float) $b->invoice->paid_amount <= 0) {
                $b->invoice->update(['status' => 'cancelled', 'note' => 'Sewa aula '.$b->booking_code.' dibatalkan']);
            }
        });
    }

    private function periksaJadwal(Hall $aula, CarbonInterface $mulai, CarbonInterface $selesai, ?int $peserta): void
    {
        $galat = match (true) {
            ! $aula->is_active => ['aula', 'Aula ini sedang tidak disewakan.'],
            $mulai->lt(now()) => ['mulai', 'Waktu mulai sudah lewat.'],
            $selesai->lte($mulai) => ['selesai', 'Waktu selesai harus setelah waktu mulai.'],
            $mulai->diffInHours($selesai) > 72 => ['selesai', 'Maksimal 3 hari per pemesanan. Hubungi kami untuk acara lebih lama.'],
            $peserta !== null && $peserta > $aula->capacity => ['peserta', "Kapasitas {$aula->name} {$aula->capacity} orang."],
            default => null,
        };
        if ($galat) {
            throw ValidationException::withMessages([$galat[0] => $galat[1]]);
        }
    }

    private function tolakBilaBentrok(Hall $aula, CarbonInterface $mulai, CarbonInterface $selesai, ?int $kecuali): void
    {
        if ($lain = $this->bentrok($aula->id, $mulai, $selesai, $kecuali)) {
            throw ValidationException::withMessages(['mulai' => "Jadwal bentrok dengan pemesanan lain ({$lain->jadwal()} WITA). Pilih waktu lain."]);
        }
    }

    private static function tambahCatatan(?string $lama, string $baru): string
    {
        return mb_substr(trim(($lama ? $lama."\n" : '').$baru), 0, 255);
    }
}
