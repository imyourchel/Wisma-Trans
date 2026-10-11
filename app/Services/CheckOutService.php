<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\SmartCleaning\TugasHousekeepingService;
use App\Support\Nomor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Check-out: semua biaya (kamar, layanan tagihan kamar, terlambat) digabung ke nota, sisa tagihan dilunasi
 * (instansi kredit boleh piutang), kamar dikosongkan menjadi Kosong-kotor, dan KTP dikembalikan.
 * Boleh sebagian kamar; reservasi selesai saat kamar terakhir keluar, dan layanan digabung hanya pada saat itu.
 */
class CheckOutService
{
    public function __construct(
        private FolioService $folio,
        private NotaService $nota,
        private RoomStatusService $statusKamar,
        private ReservasiService $reservasi,
    ) {}

    /**
     * Opsi: keluar_awal = 'dipakai' (bawaan) atau 'pesanan'; bebas_terlambat = id baris => alasan pembebasan;
     * ktp_kembali = KTP yang ditahan sudah dikembalikan.
     *
     * @param  list<int>  $kamarIds  baris reservasi yang keluar
     * @param  array{keluar_awal?: string, bebas_terlambat?: array<int, string>, ktp_kembali?: bool}  $opsi
     * @return array{akhir: bool, lebih: float}
     */
    public function proses(Reservation $r, array $kamarIds, array $opsi, User $oleh): array
    {
        $kamarIds = array_values(array_unique(array_map('intval', $kamarIds)));
        if ($kamarIds === []) {
            throw ValidationException::withMessages(['kamar' => 'Pilih kamar yang check-out.']);
        }
        $dipakai = ($opsi['keluar_awal'] ?? 'dipakai') === 'dipakai';
        $bebas = collect($opsi['bebas_terlambat'] ?? [])->map(fn ($a) => trim((string) $a));

        $hasil = DB::transaction(function () use ($r, $kamarIds, $opsi, $oleh, $dipakai, $bebas) {
            $r = Reservation::whereKey($r->id)->lockForUpdate()->first();
            if ($r?->status !== 'checked_in') {
                throw ValidationException::withMessages(['status' => 'Reservasi ini tidak sedang menginap.']);
            }
            $menginap = ReservationRoom::with('room')->where('reservation_id', $r->id)->where('status', 'checked_in')->lockForUpdate()->get();
            $keluar = $menginap->whereIn('id', $kamarIds);
            if ($keluar->count() !== count($kamarIds)) {
                throw ValidationException::withMessages(['kamar' => 'Ada kamar yang tidak sedang ditempati.']);
            }
            $akhir = $keluar->count() === $menginap->count();
            $saat = now();
            $hari = today();

            if ($akhir && $r->id_card_held && empty($opsi['ktp_kembali'])) {
                throw ValidationException::withMessages(['ktp' => 'Kembalikan KTP tamu, lalu centang "KTP sudah dikembalikan".']);
            }
            $penghalang = collect($this->folio->rincian($r, $saat, ['kamar' => $kamarIds])['penghalang'])
                ->reject(fn ($p) => str_starts_with($p, 'Sisa tagihan'));
            if ($penghalang->isNotEmpty()) {
                throw ValidationException::withMessages(['status' => $penghalang->join('. ').'.']);
            }

            // Biaya terlambat: pembebasan wajib beralasan
            $terlambat = $keluar->filter(fn (ReservationRoom $b) => $saat->gt(FolioService::batasKeluar($b)));
            foreach ($terlambat as $b) {
                if ($bebas->has($b->id) && $bebas[$b->id] === '') {
                    throw ValidationException::withMessages(["alasan.{$b->id}" => "Isi alasan pembebasan biaya terlambat Kamar {$b->room?->room_number}."]);
                }
            }

            $notaKamar = $this->siapkanNota($r, 'stay', $oleh);
            $catatan = [];

            foreach ($keluar as $b) {
                $nomor = $b->room?->room_number;
                // Keluar lebih awal: bawaan menagih malam yang dipakai saja (minimal 1 malam)
                if ($hari->lt($b->check_out_date)) {
                    if ($dipakai) {
                        $baru = $hari->max($b->check_in_date->copy()->addDay());
                        $catatan[] = "Kamar {$nomor} keluar lebih awal ({$b->check_out_date->translatedFormat('j M')}), ditagih malam yang dipakai";
                        $b->check_out_date = $baru->toDateString();
                    } else {
                        $catatan[] = "Kamar {$nomor} keluar lebih awal, ditagih sesuai pesanan";
                    }
                }
                $b->status = 'checked_out';
                $b->actual_check_out_at = $saat;
                $b->save();
            }
            $r->unsetRelation('rooms');
            // Malam yang sudah masuk nota periode menginap panjang tidak ditagih lagi
            $this->nota->susunKamar($notaKamar, $r, $this->folio->awalBelumDitagih($r));

            foreach ($terlambat as $b) {
                $nomor = $b->room?->room_number;
                if ($bebas->has($b->id)) {
                    $this->reservasi->catat($r, $r->status, $oleh, "Biaya terlambat Kamar {$nomor} dibebaskan: {$bebas[$b->id]}");

                    continue;
                }
                $notaKamar->items()->create([
                    'item_type' => 'late_checkout',
                    'description' => "Terlambat check-out Kamar {$nomor}",
                    'service_date' => $hari->toDateString(),
                    'quantity' => 1,
                    'unit_price' => FolioService::biayaTerlambat(),
                    'subtotal' => FolioService::biayaTerlambat(),
                ]);
            }

            $notaSemua = collect([$notaKamar]);
            if ($akhir) {
                // Penggabungan layanan hanya saat kamar terakhir keluar, supaya nota tidak terbelah
                $tujuan = FolioService::skemaLayanan($r) === 'satu_nota' ? $notaKamar : $this->siapkanNota($r, 'service', $oleh);
                $notaSemua->push($tujuan);
                foreach ($this->folio->pesananBelumBernota($r) as $o) {
                    $laundry = $o->jenisLayanan() === 'laundry';
                    $total = (float) $o->items->sum('subtotal');
                    $kg = (float) $o->items->sum('quantity');
                    $tujuan->items()->create([
                        'item_type' => $laundry ? 'laundry' : 'fnb',
                        'description' => FolioService::deskripsiPesanan($o),
                        'service_date' => $o->order_date->toDateString(),
                        'quantity' => $laundry ? $kg : 1,
                        'unit_price' => $laundry && $kg > 0 ? round($total / $kg, 2) : $total,
                        'subtotal' => $total,
                    ]);
                    $o->update(['invoice_id' => $tujuan->id]);
                }
            }
            $notaSemua = $notaSemua->unique('id')->map(fn (Invoice $n) => $this->nota->hitungUlang($n->fresh()));

            // Syarat lunas: nota yang dibayar langsung tidak boleh bersisa saat kamar terakhir keluar
            if ($akhir) {
                $sisa = $notaSemua->where('payment_term', 'immediate')->sum(fn (Invoice $n) => $n->sisa());
                if ($sisa > 0) {
                    throw ValidationException::withMessages(['status' => 'Sisa tagihan '.FolioService::rp($sisa).' belum dibayar.']);
                }
            }

            foreach ($keluar as $b) {
                $room = $b->room;
                if ($room && $room->status->canTransitionTo(RoomStatus::VacantDirty)) {
                    $this->statusKamar->change($room, RoomStatus::VacantDirty, 'Check-out '.$r->reservation_code, $oleh);
                    app(TugasHousekeepingService::class)->sinkron($room, $room->fresh()->status, $oleh);
                }
            }

            if ($akhir) {
                if ($r->id_card_held) {
                    $this->reservasi->catat($r, null, $oleh, 'KTP atas nama '.($r->id_card_holder_name ?: $r->customer?->full_name).' dikembalikan', 'id_card_returned');
                    $r->id_card_held = false;
                }
                $r->status = 'checked_out';
                $r->save();
                $this->reservasi->catat($r, 'checked_out', $oleh, collect(['Check-out', ...$catatan])->join('; '));
            } else {
                $this->reservasi->catat($r, $r->status, $oleh, collect(['Check-out sebagian: Kamar '.$keluar->map(fn ($b) => $b->room?->room_number)->join(', '), ...$catatan])->join('; '));
            }
            $notaSemua->each(fn (Invoice $n) => $this->nota->hitungUlang($n));
            // Lebih bayar (misalnya keluar lebih awal) masuk antrean pengembalian dana; tidak dobel dengan yang sudah ada
            if ($akhir) {
                $notaSemua->each(fn (Invoice $n) => app(PembayaranService::class)->ajukanKelebihan($n, 'Lebih bayar saat check-out '.$r->reservation_code));
            }

            return ['akhir' => $akhir, 'lebih' => (float) $notaSemua->sum(fn (Invoice $n) => max(0, (float) $n->paid_amount - (float) $n->total_amount))];
        });
        // Profil CRM (jumlah kunjungan, kamar favorit) langsung memuat check-out ini
        ProfilPelanggan::lupakan($r->customer_id, $r->institution_id);
        // Nota check-out menambah pendapatan diakui bulan ini di Dashboard
        RingkasanDashboard::lupakanBulan();

        if ($hasil['akhir']) {
            try {
                app(NotifikasiReservasi::class)->terimaKasih($r->fresh());
            } catch (Throwable $e) {
                Log::warning('Pesan terima kasih check-out gagal dikirim', ['reservasi' => $r->reservation_code, 'pesan' => $e->getMessage()]);
            }
        }

        return $hasil;
    }

    /**
     * Nota folio yang dipakai check-out, dibuat bila belum ada. Nota menginap instansi mengikuti skema penagihan:
     * jatuh tempo setelah check-out (kredit, due_date = hari ini + due_days), tagihan bulanan (kredit, due_date kosong),
     * atau bayar di muka (seperti tamu umum, harus lunas). Panggil di dalam transaksi.
     *
     * @param  string  $jenis  stay | service
     */
    public function siapkanNota(Reservation $r, string $jenis, ?User $oleh): Invoice
    {
        $r->loadMissing('institution');
        $nota = $jenis === 'stay' ? $this->folio->notaMenginap($r) : $this->folio->notaLayanan($r);
        $termin = $jenis === 'stay' ? FolioService::terminMenginap($r) : FolioService::terminLayanan($r);
        $instansi = $r->institution_id && ($jenis === 'stay' || FolioService::skemaLayanan($r) === 'dua_nota') ? $r->institution_id : null;
        $data = [
            'institution_id' => $instansi,
            'payment_term' => $termin,
            'due_date' => $termin === 'credit' && FolioService::skemaTagih($r) === 'due_after_checkout'
                ? today()->addDays((int) $r->institution?->due_days)->toDateString() : null,
        ];

        if ($nota) {
            if ($r->institution_id) {
                $nota->update($data);
            }

            return $nota;
        }

        $nota = Invoice::create([
            'invoice_number' => Nomor::nota(),
            'invoice_type' => $jenis,
            'customer_id' => $r->customer_id,
            'reservation_id' => $r->id,
            'issue_date' => today()->toDateString(),
            'status' => 'issued',
            'issued_by' => $oleh?->id,
            'note' => $jenis === 'service' ? FolioService::NOTA_LAYANAN.' '.$r->reservation_code : null,
            ...$data,
        ]);

        return $jenis === 'stay' ? $this->nota->susunKamar($nota, $r, $this->folio->awalBelumDitagih($r)) : $nota;
    }
}
