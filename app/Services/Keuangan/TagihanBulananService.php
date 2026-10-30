<?php

namespace App\Services\Keuangan;

use App\Models\BillingStatement;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PesanReservasi;
use App\Services\Notifikasi\WhatsAppService;
use App\Services\Reservasi\FolioService;
use App\Support\Nomor;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tagihan bulanan instansi (skema monthly_fixed_date). Periode mengikuti Institution::periodeTagihan():
 * (tanggal potong bulan lalu + 1) sampai tanggal potong. Draft memuat semua nota kredit instansi yang belum masuk
 * tagihan bulanan sampai akhir periode (nota susulan periode lalu ikut). Manager menerbitkan, mengeluarkan nota,
 * atau membatalkan draft. Jatuh tempo = tanggal terbit + due_days (bawaan 14).
 */
class TagihanBulananService
{
    public const HARI_JATUH_TEMPO = 14;

    public const MASA_BERLAKU_HARI = 90;

    public function __construct(private NotaService $nota, private WhatsAppService $wa) {}

    public static function hariJatuhTempo(Institution $i): int
    {
        return (int) ($i->due_days ?: self::HARI_JATUH_TEMPO);
    }

    /** Nota kredit instansi yang belum masuk tagihan bulanan sampai akhir periode. */
    public function notaSiap(Institution $i, CarbonInterface $akhir): Collection
    {
        $tanggal = $akhir->toDateString();

        return Invoice::where('institution_id', $i->id)->where('payment_term', 'credit')->whereNull('billing_statement_id')
            ->where('status', '!=', 'cancelled')->where('total_amount', '>', 0)
            // Nota periode menginap panjang dihitung dari akhir periodenya; nota lain dari tanggal terbitnya
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('period_end')->whereDate('period_end', '<=', $tanggal))
                ->orWhere(fn ($q) => $q->whereNull('period_end')->whereDate('issue_date', '<=', $tanggal)))
            ->orderBy('issue_date')->orderBy('id')->get();
    }

    /**
     * Susun draft untuk satu periode. Null bila tidak ada nota. Draft dobel (yang belum dibatalkan) untuk periode
     * yang sama ditolak.
     *
     * @param  array{mulai: CarbonInterface, akhir: CarbonInterface, terbit: CarbonInterface}  $p
     */
    public function susun(Institution $i, array $p, ?User $oleh = null): ?BillingStatement
    {
        return DB::transaction(function () use ($i, $p, $oleh) {
            Institution::whereKey($i->id)->lockForUpdate()->first();
            $ada = BillingStatement::where('institution_id', $i->id)->whereDate('period_start', $p['mulai']->toDateString())
                ->whereDate('period_end', $p['akhir']->toDateString())->where('status', '!=', 'cancelled')->first();
            if ($ada) {
                throw ValidationException::withMessages(['periode' => "Tagihan bulanan periode ini sudah ada: {$ada->statement_number}."]);
            }
            $nota = $this->notaSiap($i, $p['akhir']);
            if ($nota->isEmpty()) {
                return null;
            }

            $s = BillingStatement::create([
                'statement_number' => Nomor::tagihanBulanan(),
                'institution_id' => $i->id,
                'period_start' => $p['mulai']->toDateString(),
                'period_end' => $p['akhir']->toDateString(),
                // Sementara; ditetapkan ulang saat diterbitkan
                'due_date' => $p['terbit']->copy()->addDays(self::hariJatuhTempo($i))->toDateString(),
                'paid_amount' => $nota->sum(fn ($n) => (float) $n->paid_amount),
                'status' => 'draft',
                'issued_by' => $oleh?->id,
            ]);
            Invoice::whereIn('id', $nota->pluck('id'))->update(['billing_statement_id' => $s->id]);

            return $this->nota->hitungTagihanBulanan($s);
        });
    }

    /** Penjadwal: draft periode terakhir yang sudah berakhir (dengan susulan bila perintah sempat tidak berjalan). */
    public function susunOtomatis(): int
    {
        $n = 0;
        $kemarin = today()->subDay();
        Institution::where('default_billing_scheme', 'monthly_fixed_date')->whereNotNull('billing_day')->get()
            ->each(function (Institution $i) use ($kemarin, &$n) {
                // Periode yang memuat kemarin bila berakhir kemarin, selain itu periode sebelumnya yang sudah berakhir
                $p = $i->periodeTagihan($kemarin);
                if ($p['akhir']->gt($kemarin)) {
                    $p = $i->periodeTagihan($p['mulai']->subDay());
                }
                // Periode yang pernah disusun (termasuk yang dibatalkan Manager) tidak disusun ulang otomatis
                $pernah = BillingStatement::where('institution_id', $i->id)->whereDate('period_start', $p['mulai']->toDateString())
                    ->whereDate('period_end', $p['akhir']->toDateString())->exists();
                if (! $pernah && $this->susun($i, $p)) {
                    $n++;
                }
            });

        return $n;
    }

    /** Terbitkan draft: tanggal terbit hari ini, jatuh tempo + due_days, lalu kirim PDF ke narahubung. */
    public function terbitkan(BillingStatement $s, User $oleh): array
    {
        $s = DB::transaction(function () use ($s, $oleh) {
            $s = BillingStatement::whereKey($s->id)->lockForUpdate()->first();
            if ($s->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Hanya draft yang bisa diterbitkan.']);
            }
            if ($s->invoices()->doesntExist()) {
                throw ValidationException::withMessages(['status' => 'Draft ini tidak memuat nota.']);
            }
            $s->fill([
                'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays(self::hariJatuhTempo($s->institution))->toDateString(),
                'status' => 'issued',
                'issued_by' => $oleh->id,
            ]);

            return $this->nota->hitungTagihanBulanan($s);
        });

        return $this->kirim($s);
    }

    /** Keluarkan nota dari draft; nota ikut periode berikutnya. */
    public function keluarkanNota(BillingStatement $s, Invoice $nota): BillingStatement
    {
        if ($s->status !== 'draft' || $nota->billing_statement_id !== $s->id) {
            throw ValidationException::withMessages(['status' => 'Nota hanya bisa dikeluarkan dari draft.']);
        }

        return DB::transaction(function () use ($s, $nota) {
            $nota->update(['billing_statement_id' => null]);
            $s->paid_amount = (float) $s->invoices()->sum('paid_amount');

            return $this->nota->hitungTagihanBulanan($s);
        });
    }

    /** Batalkan tagihan bulanan yang belum dibayar; semua nota dilepas dan bisa disusun lagi. */
    public function batalkan(BillingStatement $s): BillingStatement
    {
        if ($s->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Tagihan bulanan ini sudah dibatalkan.']);
        }
        if ($s->payments()->where('status', 'success')->exists()) {
            throw ValidationException::withMessages(['status' => 'Tagihan bulanan yang sudah dibayar tidak bisa dibatalkan.']);
        }

        return DB::transaction(function () use ($s) {
            $s->invoices()->update(['billing_statement_id' => null]);
            $s->status = 'cancelled';

            return $this->nota->hitungTagihanBulanan($s);
        });
    }

    /* ---------------- Dokumen dan pengiriman ---------------- */

    /** Rekening tujuan dari pengaturan, atau null bila belum diatur. */
    public static function rekening(): ?array
    {
        $r = ['bank' => (string) Setting::get('bank_name', ''), 'nomor' => (string) Setting::get('bank_account_number', ''), 'nama' => (string) Setting::get('bank_account_name', '')];

        return $r['nomor'] !== '' ? $r : null;
    }

    public function pdf(BillingStatement $s): string
    {
        $s->loadMissing(['institution.primaryContact', 'invoices' => fn ($q) => $q->with('reservation.rooms.room', 'hallBooking.hall', 'customer')->orderBy('issue_date')->orderBy('id')]);

        return Pdf::setOption(['isFontSubsettingEnabled' => true])
            ->loadView('pdf.tagihan-bulanan', ['s' => $s, 'rekening' => self::rekening()])->setPaper('a4')->output();
    }

    /** BS/2026/10/0001 menjadi Tagihan-BS-2026-10-0001.pdf */
    public function namaBerkas(BillingStatement $s): string
    {
        return 'Tagihan-'.str_replace('/', '-', $s->statement_number).'.pdf';
    }

    /** Tautan publik bertanda tangan untuk narahubung instansi (tanpa akun). */
    public function tautan(BillingStatement $s): string
    {
        return URL::temporarySignedRoute('tagihan-bulanan.unduh', now()->addDays(self::MASA_BERLAKU_HARI), ['tagihan' => $s->id]);
    }

    /**
     * Penerima bawaan. Email: email kantor instansi dengan tembusan (cc) ke narahubung utama; bila email kantor kosong,
     * langsung ke narahubung utama. WhatsApp: narahubung utama, atau telepon instansi.
     */
    public static function penerima(Institution $i): array
    {
        $c = $i->primaryContact;
        $email = $i->email ?: $c?->email;
        $cc = $i->email && $c?->email && Str::lower($c->email) !== Str::lower($i->email) ? $c->email : null;

        return ['nama' => $c?->full_name ?? $i->name, 'email' => $email, 'cc' => $cc, 'hp' => $c?->phone ?: $i->phone];
    }

    /** Kirim PDF tagihan bulanan ke narahubung (WhatsApp berisi tautan, email dengan lampiran). Mengembalikan kanal yang berhasil. */
    public function kirim(BillingStatement $s): array
    {
        $s->loadMissing('institution.primaryContact');
        $tujuan = self::penerima($s->institution);
        $kanal = [];
        $teks = 'Yth. '.$s->institution->name.', tagihan bulanan Wisma Trans '.$s->statement_number.' periode '.$s->rentang()
            .' sebesar '.FolioService::rp($s->total_amount).', jatuh tempo '.$s->due_date->translatedFormat('j F Y').".\n\nUnduh tagihan: ".$this->tautan($s);
        try {
            if ($this->wa->kirim($tujuan['hp'], $teks)) {
                $kanal[] = 'whatsapp';
            }
        } catch (Throwable $e) {
            Log::warning('Tagihan bulanan via WhatsApp gagal', ['tagihan' => $s->statement_number, 'pesan' => $e->getMessage()]);
        }
        if ($tujuan['email']) {
            try {
                Notification::route('mail', $tujuan['email'])->notify(new PesanReservasi(
                    'Tagihan bulanan '.$s->statement_number.' Wisma Trans', 'Yth. '.Str::before($tujuan['nama'], ','),
                    ['Berikut tagihan bulanan '.$s->institution->name.' periode '.$s->rentang().' sebesar '.FolioService::rp($s->total_amount).'.',
                        'Jatuh tempo '.$s->due_date->translatedFormat('j F Y').'. Rincian nota kami lampirkan dalam bentuk PDF.'],
                    'Unduh tagihan', $this->tautan($s), [$this->pdf($s), $this->namaBerkas($s)], array_filter([$tujuan['cc']]),
                ));
                $kanal[] = 'email';
            } catch (Throwable $e) {
                Log::warning('Tagihan bulanan via email gagal', ['tagihan' => $s->statement_number, 'pesan' => $e->getMessage()]);
            }
        }

        return $kanal;
    }
}
