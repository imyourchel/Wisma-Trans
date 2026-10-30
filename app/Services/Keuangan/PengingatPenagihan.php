<?php

namespace App\Services\Keuangan;

use App\Models\BillingReminder;
use App\Models\BillingStatement;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PesanReservasi;
use App\Services\Maintenance\PengingatJadwalPerawatan;
use App\Services\Notifikasi\WhatsAppService;
use App\Services\Reservasi\FolioService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pengingat penagihan instansi dua tahap (billing_reminders, satu baris per kanal):
 *   1. internal: H−reminder_days_before, ringkasan ke semua Manager lewat WhatsApp dan email (sekali sehari per tagihan per kanal);
 *   2. to_institution: dibuat pending, baru dikirim setelah Manager memeriksa (Periksa & kirim).
 * Selama lewat jatuh tempo dan belum lunas, pengingat ke instansi dibuat lagi setiap HARI_TINDAK_LANJUT hari.
 * Tagihan di atas institution_letter_threshold juga dikirimi surat (kanal letter, dicetak manual).
 */
class PengingatPenagihan
{
    public const HARI_TINDAK_LANJUT = 7;

    public function __construct(private WhatsAppService $wa, private NotaPdf $notaPdf, private TagihanBulananService $bulanan) {}

    public static function hariSebelum(): int
    {
        return (int) Setting::get('reminder_days_before', 3);
    }

    public static function ambangSurat(): float
    {
        return (float) Setting::get('institution_letter_threshold', 10000000);
    }

    public static function sisa(Invoice|BillingStatement $t): float
    {
        return max(0, (float) $t->total_amount - (float) $t->paid_amount);
    }

    public static function perluSurat(Invoice|BillingStatement $t): bool
    {
        return self::sisa($t) > self::ambangSurat();
    }

    public static function nomor(Invoice|BillingStatement $t): string
    {
        return $t instanceof Invoice ? $t->invoice_number : $t->statement_number;
    }

    /** Kolom sasaran pengingat untuk tagihan ini. */
    private static function sasaran(Invoice|BillingStatement $t): array
    {
        return $t instanceof Invoice ? ['invoice_id' => $t->id] : ['billing_statement_id' => $t->id];
    }

    /** Tagihan instansi yang masih ditunggu pembayarannya dan punya jatuh tempo. */
    public function tagihanDipantau(): Collection
    {
        $nota = Invoice::with('institution.primaryContact')->where('payment_term', 'credit')->whereNotNull('institution_id')
            ->whereNull('billing_statement_id')->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->whereNotNull('due_date')->whereColumn('paid_amount', '<', 'total_amount')->get();
        $bulanan = BillingStatement::with('institution.primaryContact')->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->whereColumn('paid_amount', '<', 'total_amount')->get();

        return $nota->concat($bulanan)->sortBy('due_date')->values();
    }

    /**
     * Perintah harian penagihan:pengingat.
     *
     * @return array{internal: int, instansi: int, dibatalkan: int}
     */
    public function jalankan(): array
    {
        $dibatalkan = $this->batalkanYangSelesai();
        $menjelang = $this->tagihanDipantau()
            ->filter(fn ($t) => $t->due_date->copy()->subDays(self::hariSebelum())->lte(today()))->values();

        // Tahap internal: satu baris per tagihan per kanal per hari
        $baru = collect();
        foreach ($menjelang as $t) {
            foreach (['email', 'whatsapp'] as $kanal) {
                $ada = BillingReminder::where(self::sasaran($t))->where('reminder_stage', 'internal')->where('channel', $kanal)
                    ->whereDate('scheduled_date', today())->exists();
                if (! $ada) {
                    $baru->push(BillingReminder::create([...self::sasaran($t), 'reminder_stage' => 'internal', 'scheduled_date' => today()->toDateString(),
                        'channel' => $kanal, 'recipient' => 'Manager', 'status' => 'pending']));
                }
            }
        }
        if ($baru->isNotEmpty()) {
            $tagihan = $menjelang->filter(fn ($t) => $baru->contains(fn ($b) => $t instanceof Invoice ? $b->invoice_id === $t->id : $b->billing_statement_id === $t->id));
            $hasil = $this->kirimRingkasan($tagihan);
            foreach ($baru as $b) {
                $b->update(['status' => $hasil[$b->channel] ? 'sent' : 'failed', 'sent_at' => $hasil[$b->channel] ? now() : null]);
            }
        }

        // Tahap ke instansi: pending sampai diperiksa Manager
        $instansi = $menjelang->filter(fn ($t) => $this->perluPengingatInstansi($t))
            ->each(fn ($t) => $this->buatPengingatInstansi($t))->count();

        return ['internal' => $baru->count(), 'instansi' => $instansi, 'dibatalkan' => $dibatalkan];
    }

    /** Pengingat pending untuk tagihan yang sudah lunas atau dibatalkan otomatis batal. */
    public function batalkanYangSelesai(): int
    {
        return BillingReminder::where('status', 'pending')
            ->where(fn ($q) => $q->whereHas('invoice', fn ($n) => $n->whereIn('status', ['paid', 'cancelled']))
                ->orWhereHas('billingStatement', fn ($s) => $s->whereIn('status', ['paid', 'cancelled'])))
            ->update(['status' => 'cancelled']);
    }

    /** Belum pernah diingatkan, atau lewat jatuh tempo dan pengingat terakhir sudah HARI_TINDAK_LANJUT hari lalu. */
    public function perluPengingatInstansi(Invoice|BillingStatement $t): bool
    {
        $q = BillingReminder::where(self::sasaran($t))->where('reminder_stage', 'to_institution');
        if ((clone $q)->where('status', 'pending')->exists()) {
            return false;
        }
        $terakhir = (clone $q)->where('status', '!=', 'cancelled')->orderByDesc('scheduled_date')->orderByDesc('id')->first();
        if (! $terakhir) {
            return true;
        }
        $kapan = ($terakhir->sent_at ?? $terakhir->scheduled_date)->copy()->startOfDay();

        return $t->due_date->lt(today()) && $kapan->lte(today()->subDays(self::HARI_TINDAK_LANJUT));
    }

    public function buatPengingatInstansi(Invoice|BillingStatement $t, ?CarbonInterface $tanggal = null): BillingReminder
    {
        $t->loadMissing('institution.primaryContact');
        $tujuan = TagihanBulananService::penerima($t->institution);

        return BillingReminder::create([
            ...self::sasaran($t),
            'reminder_stage' => 'to_institution',
            'scheduled_date' => ($tanggal ?? today())->toDateString(),
            'channel' => $tujuan['hp'] ? 'whatsapp' : 'email',
            'recipient' => $tujuan['hp'] ?: $tujuan['email'],
            'status' => 'pending',
        ]);
    }

    /** Tombol "Kirim pengingat sekarang" di Piutang: pengingat pending hari ini (yang sudah ada dimajukan). */
    public function kirimSekarang(Invoice|BillingStatement $t): BillingReminder
    {
        $ada = BillingReminder::where(self::sasaran($t))->where('reminder_stage', 'to_institution')->where('status', 'pending')->first();
        if ($ada) {
            $ada->update(['scheduled_date' => today()->toDateString()]);

            return $ada;
        }

        return $this->buatPengingatInstansi($t);
    }

    /** Pesan pengingat untuk instansi (dipakai juga untuk pratinjau). */
    public function teks(Invoice|BillingStatement $t): string
    {
        $t->loadMissing('institution');
        $lewat = $t->due_date->lt(today());
        $rekening = TagihanBulananService::rekening();

        return 'Yth. '.$t->institution->name.', kami mengingatkan tagihan Wisma Trans '.self::nomor($t).' sebesar '.FolioService::rp(self::sisa($t))
            .($lewat ? ' yang telah lewat jatuh tempo '.$t->due_date->translatedFormat('j F Y') : ' dengan jatuh tempo '.$t->due_date->translatedFormat('j F Y')).'.'
            .($rekening ? "\nPembayaran ke {$rekening['bank']} {$rekening['nomor']} a.n. {$rekening['nama']}." : '')
            ."\nRincian tagihan: ".$this->tautan($t)
            ."\nAbaikan pesan ini bila sudah membayar. Terima kasih.";
    }

    public function tautan(Invoice|BillingStatement $t): string
    {
        return $t instanceof Invoice ? $this->notaPdf->tautan($t) : $this->bulanan->tautan($t);
    }

    /**
     * Periksa & kirim: Manager memilih kanal dan penerima. Pengingat menjadi verified lalu sent atau failed.
     * Kanal tambahan dicatat sebagai baris baru (satu baris per kanal).
     *
     * @param  list<string>  $kanal  email dan/atau whatsapp
     * @return array<string, bool> kanal => terkirim
     */
    public function periksaDanKirim(BillingReminder $p, array $kanal, ?string $email, ?string $hp, User $oleh): array
    {
        $kanal = array_values(array_intersect(['whatsapp', 'email'], $kanal));
        if ($p->status !== 'pending' || $p->reminder_stage !== 'to_institution') {
            throw ValidationException::withMessages(['kanal' => 'Pengingat ini sudah diproses.']);
        }
        if ($kanal === []) {
            throw ValidationException::withMessages(['kanal' => 'Pilih email dan/atau WhatsApp.']);
        }
        if (in_array('email', $kanal, true) && ! filter_var((string) $email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Isi email penerima yang valid.']);
        }
        if (in_array('whatsapp', $kanal, true) && blank($hp)) {
            throw ValidationException::withMessages(['hp' => 'Isi nomor WhatsApp penerima.']);
        }
        $t = $p->tagihan();
        $baris = DB::transaction(function () use ($p, $kanal, $email, $hp, $oleh) {
            return collect($kanal)->map(function ($k, $i) use ($p, $email, $hp, $oleh) {
                $data = ['channel' => $k, 'recipient' => $k === 'email' ? $email : $hp, 'status' => 'verified', 'verified_by' => $oleh->id, 'verified_at' => now()];
                if ($i === 0) {
                    $p->update($data);

                    return $p;
                }

                return BillingReminder::create([...$p->only(['invoice_id', 'billing_statement_id', 'reminder_stage', 'scheduled_date']), ...$data]);
            });
        });

        $hasil = [];
        foreach ($baris as $b) {
            $ok = $b->channel === 'email' ? $this->kirimEmail($t, $b->recipient) : $this->kirimWa($b->recipient, $this->teks($t));
            $b->update(['status' => $ok ? 'sent' : 'failed', 'sent_at' => $ok ? now() : null]);
            $hasil[$b->channel] = $ok;
        }

        return $hasil;
    }

    public function tunda(BillingReminder $p, string $tanggal): void
    {
        if ($p->status !== 'pending') {
            throw ValidationException::withMessages(['tanggal' => 'Pengingat ini sudah diproses.']);
        }
        if (! strtotime($tanggal) || ! today()->lt($tanggal)) {
            throw ValidationException::withMessages(['tanggal' => 'Pilih tanggal setelah hari ini.']);
        }
        $p->update(['scheduled_date' => $tanggal]);
    }

    public function batalkan(BillingReminder $p): void
    {
        if ($p->status !== 'pending') {
            throw ValidationException::withMessages(['kanal' => 'Pengingat ini sudah diproses.']);
        }
        $p->update(['status' => 'cancelled']);
    }

    /** Surat tagihan resmi (dicetak dan dikirim manual) untuk tagihan di atas ambang. */
    public function suratTerkirim(Invoice|BillingStatement $t, User $oleh): BillingReminder
    {
        if (! self::perluSurat($t)) {
            throw ValidationException::withMessages(['surat' => 'Surat tagihan hanya untuk tagihan di atas '.FolioService::rp(self::ambangSurat()).'.']);
        }
        $t->loadMissing('institution');

        return BillingReminder::create([
            ...self::sasaran($t), 'reminder_stage' => 'to_institution', 'scheduled_date' => today()->toDateString(), 'channel' => 'letter',
            'recipient' => Str::limit($t->institution->name.($t->institution->address ? ', '.$t->institution->address : ''), 150, ''),
            'status' => 'sent', 'verified_by' => $oleh->id, 'verified_at' => now(), 'sent_at' => now(),
        ]);
    }

    /** Ringkasan ke semua Manager. @return array{email: bool, whatsapp: bool} */
    public function kirimRingkasan(Collection $tagihan): array
    {
        $daftar = $tagihan->map(fn ($t) => self::nomor($t).' '.$t->institution->name.' '.FolioService::rp(self::sisa($t))
            .', jatuh tempo '.$t->due_date->translatedFormat('j M'))->values();
        $judul = $daftar->count().' tagihan instansi menjelang jatuh tempo';
        $url = route('staf.keuangan.pengingat');
        $hasil = ['email' => false, 'whatsapp' => false];
        foreach (PengingatJadwalPerawatan::penerima() as $m) {
            $hasil['whatsapp'] = $this->kirimWa($m->phone, $judul.': '.$daftar->join('; ').". Periksa di {$url}") || $hasil['whatsapp'];
            if ($m->email) {
                try {
                    $m->notify(new PesanReservasi($judul, 'Halo '.Str::before($m->name, ' ').',',
                        ['Tagihan instansi berikut menjelang atau sudah lewat jatuh tempo:', ...$daftar->map(fn ($d) => '• '.$d)->all(),
                            'Periksa lalu kirim pengingat ke instansi dari menu Pengingat Penagihan.'], 'Periksa pengingat', $url));
                    $hasil['email'] = true;
                } catch (Throwable $e) {
                    Log::warning('Ringkasan penagihan via email gagal', ['user' => $m->id, 'pesan' => $e->getMessage()]);
                }
            }
        }

        return $hasil;
    }

    private function kirimWa(?string $hp, string $teks): bool
    {
        try {
            return $this->wa->kirim($hp, $teks);
        } catch (Throwable $e) {
            Log::warning('Pengingat penagihan via WhatsApp gagal', ['tujuan' => $hp, 'pesan' => $e->getMessage()]);

            return false;
        }
    }

    private function kirimEmail(Invoice|BillingStatement $t, string $email): bool
    {
        try {
            $nota = $t instanceof Invoice;
            // Tembusan ke narahubung utama bila pengingat dikirim ke alamat lain (misalnya email kantor instansi)
            $narahubung = $t->institution->primaryContact?->email;
            $cc = $narahubung && Str::lower($narahubung) !== Str::lower($email) ? [$narahubung] : [];
            Notification::route('mail', $email)->notify(new PesanReservasi(
                'Pengingat tagihan '.self::nomor($t).' Wisma Trans', 'Yth. '.$t->institution->name.',',
                array_filter(explode("\n", $this->teks($t))), 'Lihat tagihan', $this->tautan($t),
                $nota ? [$this->notaPdf->isi($t), $this->notaPdf->namaBerkas($t)] : [$this->bulanan->pdf($t), $this->bulanan->namaBerkas($t)], $cc,
            ));

            return true;
        } catch (Throwable $e) {
            Log::warning('Pengingat penagihan via email gagal', ['tagihan' => self::nomor($t), 'pesan' => $e->getMessage()]);

            return false;
        }
    }
}
