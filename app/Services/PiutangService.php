<?php

namespace App\Services;

use App\Models\BillingStatement;
use App\Models\HallBooking;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\Nomor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Piutang instansi. "Tagihan" = nota kredit instansi yang belum masuk tagihan bulanan, atau tagihan bulanan (statement).
 * Keduanya diringkas dalam bentuk yang sama. Juga tugas harian penagihan, semuanya idempoten dan menyusul
 * bila penjadwal sempat tidak berjalan: status overdue, nota sewa aula instansi, dan nota periode menginap panjang.
 */
class PiutangService
{
    public const UMUR = ['belum' => 'Belum jatuh tempo', '1-30' => '1–30 hari', '31-60' => '31–60 hari', '60+' => '> 60 hari'];

    public function __construct(private NotaService $nota) {}

    /**
     * Tagihan terbuka (masih bersisa), paling lama lewat jatuh tempo dulu.
     *
     * @return Collection<int, array{jenis: string, model: Invoice|BillingStatement, nomor: string, instansi: Institution, total: float,
     *                               terbayar: float, sisa: float, jatuh_tempo: ?CarbonImmutable, status: string, umur: int, kelompok: string}>
     */
    public function tagihanTerbuka(?int $instansiId = null): Collection
    {
        $nota = Invoice::with('institution')->where('payment_term', 'credit')->whereNotNull('institution_id')->whereNull('billing_statement_id')
            ->whereNotIn('status', ['paid', 'cancelled'])->whereColumn('paid_amount', '<', 'total_amount')
            ->when($instansiId, fn ($q) => $q->where('institution_id', $instansiId))->get();
        $bulanan = BillingStatement::with('institution')->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->when($instansiId, fn ($q) => $q->where('institution_id', $instansiId))->get();

        return $nota->map(fn (Invoice $n) => self::ringkas($n))->concat($bulanan->map(fn (BillingStatement $s) => self::ringkas($s)))
            ->sortByDesc('umur')->values();
    }

    public static function ringkas(Invoice|BillingStatement $t): array
    {
        $nota = $t instanceof Invoice;
        $jatuhTempo = $t->due_date ? CarbonImmutable::parse($t->due_date) : null;
        $umur = $jatuhTempo && $jatuhTempo->lt(today()) ? (int) $jatuhTempo->diffInDays(today()) : 0;

        return [
            'jenis' => $nota ? 'nota' : 'bulanan',
            'model' => $t,
            'nomor' => $nota ? $t->invoice_number : $t->statement_number,
            'instansi' => $t->institution,
            'total' => (float) $t->total_amount,
            // Nota anggota statement dibayar lewat statement: selalu baca paid_amount, bukan Invoice::terbayar()
            'terbayar' => (float) $t->paid_amount,
            'sisa' => max(0, (float) $t->total_amount - (float) $t->paid_amount),
            'jatuh_tempo' => $jatuhTempo,
            'status' => $t->status,
            'umur' => $umur,
            'kelompok' => self::kelompokUmur($umur),
        ];
    }

    public static function kelompokUmur(int $umur): string
    {
        return match (true) {
            $umur <= 0 => 'belum',
            $umur <= 30 => '1-30',
            $umur <= 60 => '31-60',
            default => '60+',
        };
    }

    /** Ringkasan per instansi: sisa, jumlah tagihan, umur piutang, pembayaran terakhir; paling lama lewat jatuh tempo dulu. */
    public function perInstansi(): Collection
    {
        return $this->tagihanTerbuka()->groupBy(fn ($t) => $t['instansi']->id)->map(function (Collection $g) {
            $i = $g->first()['instansi'];

            return [
                'instansi' => $i,
                'sisa' => (float) $g->sum('sisa'),
                'jumlah' => $g->count(),
                'umur' => collect(self::UMUR)->map(fn ($l, $k) => (float) $g->where('kelompok', $k)->sum('sisa'))->all(),
                'terlama' => (int) $g->max('umur'),
                'terakhir' => $this->pembayaranInstansi($i->id)->first(),
            ];
        })->sortByDesc('terlama')->values();
    }

    /**
     * Sisa piutang berjalan per instansi (id => rupiah) dari tagihanTerbuka(), untuk daftar yang tidak butuh
     * umur dan pembayaran terakhir seperti perInstansi().
     *
     * @return Collection<int, float>
     */
    public function sisaPerInstansi(): Collection
    {
        return $this->tagihanTerbuka()->groupBy(fn ($t) => $t['instansi']->id)->map(fn (Collection $g) => (float) $g->sum('sisa'));
    }

    /** Pembayaran atas nota kredit dan tagihan bulanan instansi, terbaru dulu. */
    public function pembayaranInstansi(int $instansiId)
    {
        return Payment::with('invoice', 'billingStatement', 'receivedBy')->where('status', 'success')
            ->where(fn ($q) => $q->whereHas('invoice', fn ($n) => $n->where('institution_id', $instansiId)->where('payment_term', 'credit'))
                ->orWhereHas('billingStatement', fn ($s) => $s->where('institution_id', $instansiId)))
            ->orderByDesc('paid_at')->orderByDesc('id')->get();
    }

    /** Kartu: total piutang, lewat jatuh tempo, dan diterima bulan ini. */
    public function ringkasan(): array
    {
        $terbuka = $this->tagihanTerbuka();
        $diterima = Payment::where('status', 'success')->where('transaction_type', 'payment')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->where(fn ($q) => $q->whereNotNull('billing_statement_id')
                ->orWhereHas('invoice', fn ($n) => $n->where('payment_term', 'credit')->whereNotNull('institution_id')))
            ->sum('amount');

        return [
            'total' => (float) $terbuka->sum('sisa'),
            'lewat' => (float) $terbuka->where('umur', '>', 0)->sum('sisa'),
            'jumlahLewat' => $terbuka->where('umur', '>', 0)->count(),
            'diterima' => (float) $diterima,
        ];
    }

    /* ======================== Tugas harian (penagihan:harian) ======================== */

    /** @return array{overdue: int, aula: int, periode: int, bulanan: int} */
    public function harian(): array
    {
        return [
            'aula' => $this->notaAula(),
            'periode' => $this->notaPeriode(),
            'bulanan' => app(TagihanBulananService::class)->susunOtomatis(),
            'overdue' => $this->tandaiOverdue(),
        ];
    }

    /** Nota kredit dan tagihan bulanan lewat jatuh tempo yang masih bersisa menjadi overdue. */
    public function tandaiOverdue(): int
    {
        $n = Invoice::where('payment_term', 'credit')->whereIn('status', ['issued', 'partially_paid'])
            ->whereDate('due_date', '<', today())->whereColumn('paid_amount', '<', 'total_amount')->update(['status' => 'overdue']);

        return $n + BillingStatement::whereIn('status', ['issued', 'partially_paid'])
            ->whereDate('due_date', '<', today())->whereColumn('paid_amount', '<', 'total_amount')->update(['status' => 'overdue']);
    }

    /** Sewa aula instansi yang belum bernota (bayar di muka: segera; skema lain: setelah acara selesai). */
    public function notaAula(): int
    {
        return HallBooking::with('customer.institution')->whereIn('status', ['confirmed', 'completed'])->whereNull('invoice_id')
            ->where('rental_price', '>', 0)->whereHas('customer', fn ($c) => $c->whereNotNull('institution_id'))->get()
            ->filter(fn (HallBooking $b) => $this->buatNotaAula($b) !== null)->count();
    }

    /**
     * Nota sewa aula yang ditagihkan ke instansi penyewa. Bayar di muka (upfront): nota langsung, jatuh tempo sehari
     * sebelum acara, dibuat begitu disetujui. Jatuh tempo setelah acara: nota kredit setelah acara selesai,
     * jatuh tempo + due_days. Tagihan bulanan: nota kredit tanpa jatuh tempo. Hanya sekali per pemesanan.
     */
    public function buatNotaAula(HallBooking $b): ?Invoice
    {
        $i = $b->customer?->institution;
        if (! $i) {
            return null;
        }
        $skema = $i->default_billing_scheme;
        if ($skema !== 'upfront' && $b->end_at->isFuture()) {
            return null;
        }

        return DB::transaction(function () use ($b, $i, $skema) {
            $b = HallBooking::with('hall')->whereKey($b->id)->lockForUpdate()->first();
            if ($b->invoice_id !== null || ! in_array($b->status, ['confirmed', 'completed'], true) || (float) $b->rental_price <= 0) {
                return null;
            }
            $nota = Invoice::create([
                'invoice_number' => Nomor::nota(),
                'invoice_type' => 'hall',
                'customer_id' => $b->customer_id,
                'institution_id' => $i->id,
                'payment_term' => $skema === 'upfront' ? 'immediate' : 'credit',
                'issue_date' => today()->toDateString(),
                'due_date' => match ($skema) {
                    'upfront' => $b->start_at->copy()->subDay()->toDateString(),
                    'due_after_checkout' => $b->end_at->copy()->addDays(TagihanBulananService::hariJatuhTempo($i))->toDateString(),
                    default => null,
                },
                'status' => 'issued',
                'note' => 'Sewa aula '.$b->booking_code,
            ]);
            $nota->items()->create([
                'item_type' => 'hall',
                'description' => 'Sewa '.$b->hall->name.' '.$b->start_at->translatedFormat('j M').($b->participant_count ? ', '.$b->participant_count.' peserta' : ''),
                'service_date' => $b->start_at->toDateString(),
                'quantity' => 1,
                'unit_price' => $b->rental_price,
                'subtotal' => $b->rental_price,
            ]);
            $b->update(['invoice_id' => $nota->id]);

            return $this->nota->hitungUlang($nota);
        });
    }

    /**
     * Nota periode menginap panjang instansi tagihan bulanan: untuk reservasi yang masih menginap, setiap periode yang sudah
     * berakhir (period_end < hari ini) dan belum bernota dibuatkan nota kredit berisi malam dalam periode itu saja.
     * Reservasi yang sudah check-out tidak diproses, karena check-out sudah menagih semua malamnya.
     */
    public function notaPeriode(): int
    {
        $n = 0;
        Reservation::with('institution', 'rooms.room', 'rooms.roomType')->where('status', 'checked_in')->whereNotNull('institution_id')->get()
            ->filter(fn (Reservation $r) => FolioService::skemaTagih($r) === 'monthly_fixed_date' && $r->institution?->billing_day)
            ->each(function (Reservation $r) use (&$n) {
                $p = $r->institution->periodeTagihan($r->masuk());
                while ($p['akhir']->lt(today())) {
                    if ($this->buatNotaPeriode($r, $p)) {
                        $n++;
                    }
                    $p = $r->institution->periodeTagihan($p['akhir']->addDay());
                }
            });

        return $n;
    }

    private function buatNotaPeriode(Reservation $r, array $p): ?Invoice
    {
        return DB::transaction(function () use ($r, $p) {
            $r = Reservation::whereKey($r->id)->lockForUpdate()->first();
            $ada = $r->invoices()->where('invoice_type', 'stay')->where('status', '!=', 'cancelled')
                ->whereDate('period_start', $p['mulai']->toDateString())->exists();
            if ($r->status !== 'checked_in' || $ada) {
                return null;
            }
            $r->load('rooms.room', 'rooms.roomType');
            $malam = $r->rooms->whereIn('status', ['checked_in', 'checked_out', 'moved'])
                ->map(fn ($b) => NotaService::potong($b, $p['mulai'], $p['akhir']))->filter()->sum(fn ($b) => $b->malam());
            if ($malam === 0) {
                return null;
            }
            $nota = Invoice::create([
                'invoice_number' => Nomor::nota(),
                'invoice_type' => 'stay',
                'customer_id' => $r->customer_id,
                'reservation_id' => $r->id,
                'institution_id' => $r->institution_id,
                'payment_term' => 'credit',
                'issue_date' => today()->toDateString(),
                'period_start' => $p['mulai']->toDateString(),
                'period_end' => $p['akhir']->toDateString(),
                'status' => 'issued',
                'note' => 'Menginap '.$r->reservation_code.' periode '.$p['mulai']->translatedFormat('j M').' – '.$p['akhir']->translatedFormat('j M Y'),
            ]);

            return $this->nota->susunKamar($nota, $r, $p['mulai'], $p['akhir']);
        });
    }
}
