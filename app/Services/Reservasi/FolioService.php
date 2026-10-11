<?php

namespace App\Services\Reservasi;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ServiceOrder;
use App\Models\Setting;
use App\Services\Keuangan\NotaService;
use App\Services\Layanan\LaundryService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tagihan berjalan (folio) satu reservasi: kamar, layanan yang ditagihkan ke kamar, dan biaya terlambat check-out.
 * Hanya menghitung, tidak menyimpan apa pun. Dipakai layar Check-out, detail Daftar Reservasi, dan halaman tamu.
 *
 * Tujuan layanan mengikuti skema layanan reservasi (perorangan selalu satu_nota):
 *   satu_nota  = ikut nota menginap
 *   dua_nota   = nota layanan terpisah, ditagihkan ke instansi (kredit)
 *   tamu_bayar = nota layanan terpisah atas nama pemesan, dibayar langsung
 * Nota menginap instansi: kredit (due_after_checkout, monthly_fixed_date) atau dibayar langsung (upfront).
 */
class FolioService
{
    /** Awalan catatan nota layanan menginap terpisah (dua_nota, tamu_bayar), misalnya "Layanan menginap RSV-0012". */
    public const NOTA_LAYANAN = 'Layanan menginap';

    /** "12:00" */
    public static function jamKeluar(): string
    {
        return substr((string) Setting::get('check_out_time', '12:00'), 0, 5);
    }

    public static function biayaTerlambat(): float
    {
        return (float) Setting::get('late_checkout_fee', 250000);
    }

    /** Batas check-out satu kamar: tanggal keluar pada jam check-out. */
    public static function batasKeluar(ReservationRoom $b): Carbon
    {
        return Carbon::parse($b->check_out_date->toDateString().' '.self::jamKeluar());
    }

    /** Skema penagihan kamar instansi (null untuk tamu perorangan). */
    public static function skemaTagih(Reservation $r): ?string
    {
        return $r->institution_id ? ($r->billing_scheme ?: $r->institution?->default_billing_scheme) : null;
    }

    public static function skemaLayanan(Reservation $r): string
    {
        return $r->institution_id ? ($r->service_billing_scheme ?: 'satu_nota') : 'satu_nota';
    }

    /** Termin nota menginap: kredit untuk instansi selain bayar di muka. */
    public static function terminMenginap(Reservation $r): string
    {
        return in_array(self::skemaTagih($r), ['due_after_checkout', 'monthly_fixed_date'], true) ? 'credit' : 'immediate';
    }

    public static function terminLayanan(Reservation $r): string
    {
        return self::skemaLayanan($r) === 'dua_nota' ? 'credit' : 'immediate';
    }

    /** Nota menginap utama (dibayar saat check-out); nota periode menginap panjang tidak termasuk. */
    public function notaMenginap(Reservation $r): ?Invoice
    {
        return $r->invoices()->where('invoice_type', 'stay')->whereNull('period_start')->where('status', '!=', 'cancelled')->orderByDesc('id')->first();
    }

    /** Nota periode menginap panjang instansi (tagihan bulanan), urut periode. */
    public function notaPeriode(Reservation $r): Collection
    {
        return $r->invoices()->where('invoice_type', 'stay')->whereNotNull('period_start')->where('status', '!=', 'cancelled')->orderBy('period_start')->get();
    }

    /** Malam pertama yang belum ditagih nota periode (sehari setelah period_end terakhir), atau null. */
    public function awalBelumDitagih(Reservation $r): ?Carbon
    {
        $akhir = $r->invoices()->where('invoice_type', 'stay')->whereNotNull('period_end')->where('status', '!=', 'cancelled')->max('period_end');

        return $akhir ? Carbon::parse($akhir)->addDay() : null;
    }

    public function notaLayanan(Reservation $r): ?Invoice
    {
        return $r->invoices()->where('invoice_type', 'service')->where('status', '!=', 'cancelled')
            ->where('note', 'like', self::NOTA_LAYANAN.'%')->orderByDesc('id')->first();
    }

    /** Pesanan yang ditagihkan ke kamar dan belum masuk nota (selain yang dibatalkan). */
    public function pesananBelumBernota(Reservation $r): Collection
    {
        return ServiceOrder::with('items.service')
            ->whereIn('reservation_room_id', $r->rooms()->select('id'))
            ->where('payment_mode', 'charge_to_room')->whereNull('invoice_id')->where('status', '!=', 'cancelled')
            ->orderBy('order_date')->get();
    }

    /** "Makanan ORD/2026/10/0021 (2 item)" atau "Laundry ORD/2026/10/0022 (3,5 kg)". */
    public static function deskripsiPesanan(ServiceOrder $o): string
    {
        return $o->jenisLayanan() === 'laundry'
            ? 'Laundry '.$o->order_code.' ('.LaundryService::kg((float) $o->items->sum('quantity')).')'
            : 'Makanan '.$o->order_code.' ('.(int) $o->items->sum('quantity').' item)';
    }

    /**
     * Opsi: kamar = baris reservasi yang keluar (bawaan semua yang masih menginap); keluar_awal = 'dipakai'
     * (tagih malam yang dipakai bila keluar sekarang) atau 'pesanan' (bawaan untuk tagihan sementara);
     * bebas_terlambat = baris yang biaya terlambatnya dibebaskan.
     *
     * @param  array{kamar?: list<int>, keluar_awal?: string, bebas_terlambat?: list<int>}  $opsi
     */
    public function rincian(Reservation $r, ?CarbonInterface $saat = null, array $opsi = []): array
    {
        $saat = Carbon::instance($saat ?? now());
        $hari = $saat->copy()->startOfDay();
        $r->loadMissing('rooms.room', 'rooms.roomType', 'institution', 'customer');
        $menginap = $r->rooms->where('status', 'checked_in');
        $keluar = $menginap->whereIn('id', $opsi['kamar'] ?? $menginap->pluck('id')->all());
        $akhir = $menginap->isNotEmpty() && $keluar->count() === $menginap->count();
        // Tagihan sementara memakai tanggal pesanan; layar check-out memilih eksplisit (bawaannya "dipakai")
        $dipakai = ($opsi['keluar_awal'] ?? 'pesanan') === 'dipakai';
        $bebas = $opsi['bebas_terlambat'] ?? [];

        $notaKamar = $this->notaMenginap($r);
        $layananTerpisah = self::skemaLayanan($r) !== 'satu_nota';
        $notaLayanan = $layananTerpisah ? $this->notaLayanan($r) : null;
        $tujuanLayanan = $layananTerpisah ? 'service' : 'stay';

        // Kamar: dihitung dari baris reservasi seperti NotaService::susunKamar; keluar lebih awal memotong malam yang tidak dipakai,
        // dan malam yang sudah ditagih nota periode menginap panjang tidak ditagih lagi
        $awal = $this->awalBelumDitagih($r);
        $kamar = $r->rooms->whereIn('status', ['booked', 'checked_in', 'checked_out', 'moved'])
            ->map(function (ReservationRoom $b) use ($keluar, $dipakai, $hari, $awal) {
                if ($dipakai && $keluar->contains('id', $b->id) && $hari->lt($b->check_out_date)) {
                    $b = clone $b;
                    $b->check_out_date = $hari->max($b->check_in_date->copy()->addDay())->copy();
                }

                return $awal ? NotaService::potong($b, $awal, null) : $b;
            })
            ->filter(fn (?ReservationRoom $b) => $b && $b->malam() > 0)
            ->sortBy(fn (ReservationRoom $b) => [$b->check_in_date->toDateString(), $b->id])
            ->map(fn (ReservationRoom $b) => [
                'deskripsi' => ($b->room ? 'Kamar '.$b->room->room_number : $b->roomType->name).', '.$b->malam().' malam',
                'tanggal' => $b->check_in_date,
                'jumlah' => $b->malam(),
                'harga' => (float) $b->price_per_night,
                'potongan' => $b->potongan(),
                'subtotal' => $b->biaya(),
                'nota' => 'stay',
            ])->values();

        // Layanan yang sudah masuk nota, lalu pesanan tagihan kamar yang belum bernota
        $layanan = collect([$notaKamar, $notaLayanan])->filter()
            ->flatMap(fn (Invoice $n) => $n->items()->whereIn('item_type', ['fnb', 'laundry', 'amenity', 'other'])->orderBy('id')->get()
                ->map(fn ($i) => [
                    'deskripsi' => $i->description, 'tanggal' => $i->service_date, 'jenis' => $i->item_type,
                    'subtotal' => (float) $i->subtotal, 'nota' => $n->invoice_type, 'baru' => false, 'perkiraan' => false,
                ]));
        $belum = $this->pesananBelumBernota($r);
        $layanan = $layanan->concat($belum->map(fn (ServiceOrder $o) => [
            'deskripsi' => self::deskripsiPesanan($o), 'tanggal' => $o->order_date, 'jenis' => $o->jenisLayanan() === 'laundry' ? 'laundry' : 'fnb',
            'subtotal' => (float) $o->items->sum('subtotal'), 'nota' => $tujuanLayanan, 'baru' => true,
            // Laundry yang belum ditimbang masih memakai perkiraan berat
            'perkiraan' => $o->jenisLayanan() === 'laundry' && $o->status === 'pending',
        ]))->values();

        // Terlambat: yang sudah tercatat, lalu kamar yang keluar sekarang melewati batas check-out
        $terlambat = collect($notaKamar ? $notaKamar->items()->where('item_type', 'late_checkout')->orderBy('id')->get()
            ->map(fn ($i) => ['deskripsi' => $i->description, 'subtotal' => (float) $i->subtotal, 'baris' => null, 'bebas' => false, 'baru' => false, 'nota' => 'stay'])
            ->all() : []);
        foreach ($keluar as $b) {
            if ($saat->gt(self::batasKeluar($b))) {
                $dibebaskan = in_array($b->id, $bebas);
                $terlambat->push([
                    'deskripsi' => 'Terlambat check-out Kamar '.$b->room?->room_number,
                    'subtotal' => $dibebaskan ? 0.0 : self::biayaTerlambat(),
                    'baris' => $b->id, 'bebas' => $dibebaskan, 'baru' => true, 'nota' => 'stay',
                ]);
            }
        }

        // Ringkasan per nota
        $nota = ['stay' => $this->ringkasNota($notaKamar, self::terminMenginap($r),
            $kamar->sum('subtotal') + $terlambat->sum('subtotal') + $layanan->where('nota', 'stay')->sum('subtotal'))];
        if ($layananTerpisah) {
            $nota['service'] = $this->ringkasNota($notaLayanan, self::terminLayanan($r), $layanan->where('nota', 'service')->sum('subtotal'));
        }
        $nota = collect($nota);
        $segera = $nota->where('termin', 'immediate');

        // Penghalang dan peringatan untuk kamar yang keluar (semua kamar bila ini check-out terakhir)
        $baris = $akhir ? $r->rooms->pluck('id') : $keluar->pluck('id');
        $pesanan = ServiceOrder::with('items.service', 'invoice')->whereIn('reservation_room_id', $baris)->where('status', '!=', 'cancelled')->orderBy('order_date')->get();
        $penghalang = [];
        $peringatan = [];
        foreach ($pesanan as $o) {
            $laundry = $o->jenisLayanan() === 'laundry';
            if ($laundry && $o->status === 'pending') {
                $penghalang[] = "Laundry {$o->order_code} belum ditimbang";

                continue;
            }
            if ($o->payment_mode === 'pay_now' && $o->invoice && ! in_array($o->invoice->status, ['paid', 'cancelled'], true)) {
                $peringatan[] = [
                    'teks' => "Pesanan {$o->order_code} belum dibayar (".self::rp($o->invoice->sisa()).', nota terpisah)',
                    'url' => $laundry ? route('staf.laundry', ['cari' => $o->order_code]) : route('staf.fnb.kasir'),
                    'tautan' => $laundry ? 'Buka laundry' : 'Buka kasir',
                ];
            }
            if ($laundry && in_array($o->status, ['in_progress', 'ready'], true)) {
                $peringatan[] = ['teks' => "Laundry {$o->order_code} belum diserahkan ke tamu", 'url' => null, 'tautan' => null];
            } elseif (! $laundry && $o->payment_mode === 'charge_to_room' && $o->status !== 'delivered') {
                $peringatan[] = ['teks' => "Makanan {$o->order_code} masih diproses dapur", 'url' => null, 'tautan' => null];
            }
        }
        $sisaSegera = $segera->sum('sisa');
        if ($akhir && $sisaSegera > 0) {
            $penghalang[] = 'Sisa tagihan '.self::rp($sisaSegera).' belum dibayar.';
        }

        return [
            'kamar' => $kamar,
            'layanan' => $layanan,
            'terlambat' => $terlambat->values(),
            'nota' => $nota,
            'total' => (float) $nota->sum('total'),
            'sudah_dibayar' => (float) $nota->sum('dibayar'),
            'sisa' => (float) $sisaSegera,
            'piutang' => (float) $nota->where('termin', 'credit')->sum('sisa'),
            'lebih' => (float) $nota->sum('lebih'),
            'penghalang' => $penghalang,
            'peringatan' => $peringatan,
            'akhir' => $akhir,
            'kredit' => $this->keteranganKredit($r, $hari),
            'periode' => $this->notaPeriode($r)->map(fn (Invoice $n) => [
                'nomor' => $n->invoice_number, 'mulai' => $n->period_start, 'akhir' => $n->period_end, 'total' => (float) $n->total_amount,
            ])->values(),
        ];
    }

    /** Sisa yang harus dibayar untuk satu nota folio, dihitung dari folio selama tamu masih menginap. */
    public function sisaNota(Invoice $nota, array $opsi = []): ?array
    {
        $r = $nota->reservation;
        if (! $r || $r->status !== 'checked_in') {
            return null;
        }
        $kunci = match (true) {
            $nota->invoice_type === 'stay' => 'stay',
            $nota->invoice_type === 'service' && str_starts_with((string) $nota->note, self::NOTA_LAYANAN) => 'service',
            default => null,
        };
        $ringkas = $kunci ? ($this->rincian($r, null, $opsi)['nota'][$kunci] ?? null) : null;

        return $ringkas && $ringkas['model']?->id === $nota->id ? $ringkas : null;
    }

    /** "Ditagihkan ke PT X, jatuh tempo 20 Okt 2026" / "Masuk tagihan bulanan periode …"; null bila bukan kredit. */
    public function keteranganKredit(Reservation $r, CarbonInterface $hari): ?string
    {
        $i = $r->institution;
        if (! $i || (self::terminMenginap($r) !== 'credit' && self::terminLayanan($r) !== 'credit')) {
            return null;
        }
        if (self::skemaTagih($r) === 'monthly_fixed_date') {
            $p = $i->periodeTagihan($hari);

            return $p ? 'Masuk tagihan bulanan '.$i::teksPeriode($p) : 'Masuk tagihan bulanan (tanggal tagih instansi belum diatur)';
        }
        if (self::skemaTagih($r) === 'due_after_checkout') {
            return 'Ditagihkan ke '.$i->name.', jatuh tempo '.Carbon::parse($hari)->addDays((int) $i->due_days)->translatedFormat('j M Y');
        }

        return 'Layanan ditagihkan ke '.$i->name;
    }

    private function ringkasNota(?Invoice $model, string $termin, float $kotor): array
    {
        $diskon = match ($model?->discount_type) {
            'percent' => round($kotor * (float) $model->discount_value / 100),
            'amount' => min($kotor, (float) $model->discount_value),
            default => 0.0,
        };
        $total = $kotor - $diskon;
        $dibayar = (float) ($model?->paid_amount ?? 0);

        return [
            'model' => $model,
            'termin' => $termin,
            'diskon' => $diskon,
            'total' => $total,
            'dibayar' => $dibayar,
            'sisa' => max(0, $total - $dibayar),
            'lebih' => max(0, $dibayar - $total),
        ];
    }

    public static function rp(float|int|string|null $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }
}
