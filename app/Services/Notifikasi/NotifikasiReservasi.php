<?php

namespace App\Services\Notifikasi;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Notifications\PesanReservasi;
use App\Services\Keuangan\NotaPdf;
use App\Services\Reservasi\BuktiInstansi;
use App\Services\Reservasi\FolioService;
use App\Services\Reservasi\ReservasiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pemberitahuan reservasi ke tamu lewat WhatsApp dan email.
 * Dipanggil setelah transaksi basis data selesai; kegagalan kirim hanya dicatat di log.
 */
class NotifikasiReservasi
{
    public function __construct(private WhatsAppService $wa, private NotaPdf $notaPdf, private BuktiInstansi $bukti) {}

    private static function rp(float|int|string|null $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    /** Teks konfirmasi reservasi online; dipakai juga untuk tombol kirim ulang lewat wa.me di panel staf. */
    public function teksDibuat(Reservation $r): string
    {
        $r->loadMissing('rooms.roomType', 'stayInvoice', 'customer');
        $nama = Str::before($r->customer->full_name, ' ');
        $kamar = $r->kamarBerlaku()->countBy(fn ($k) => $k->roomType->name)->map(fn ($n, $t) => "{$n} {$t}")->join(', ');

        return match ($r->status) {
            'pending_payment' => "Halo {$nama}, reservasi Wisma Trans {$r->reservation_code} sudah kami terima.\n\n"
                ."{$kamar}\n".$r->masuk()->translatedFormat('j M Y').' – '.$r->keluar()->translatedFormat('j M Y')."\n"
                .'Total kamar '.self::rp($r->stayInvoice?->total_amount)."\n"
                .'Uang muka '.self::rp($r->dp_amount_required).', bayar sebelum '.$r->dp_due_at->translatedFormat('j M Y H.i')." WITA.\n\n"
                ."Bayar dan cek pesanan: {$r->urlStatus()}\nKode {$r->reservation_code}, token {$r->token()}",
            'waiting_approval' => "Halo {$nama}, pengajuan reservasi instansi {$r->reservation_code} sudah kami terima. "
                ."Manager kami memeriksa ketersediaan dan menghubungi Anda.\n\nCek status: {$r->urlStatus()}\nKode {$r->reservation_code}, token {$r->token()}",
            default => "Halo {$nama}, reservasi Wisma Trans {$r->reservation_code}: {$r->label()}.\n"
                .($r->masuk() ? $r->masuk()->translatedFormat('j M Y').' – '.$r->keluar()->translatedFormat('j M Y')."\n" : '')
                ."\nCek pesanan: {$r->urlStatus()}\nKode {$r->reservation_code}, token {$r->token()}",
        };
    }

    public function dibuat(Reservation $r): void
    {
        $this->kirimWa($r, $this->teksDibuat($r));

        $baris = $r->status === 'waiting_approval'
            ? ['Pengajuan reservasi instansi '.$r->reservation_code.' sudah kami terima. Manager kami memeriksa ketersediaan kamar dan akan menghubungi Anda.']
            : [
                'Reservasi '.$r->reservation_code.' sudah kami terima. Selesaikan pembayaran uang muka '.self::rp($r->dp_amount_required)
                    .' sebelum '.$r->dp_due_at?->translatedFormat('j F Y, H.i').' WITA agar kamar terkonfirmasi.',
                'Menginap '.$r->masuk()?->translatedFormat('j F Y').' sampai '.$r->keluar()?->translatedFormat('j F Y').'. Total kamar '.self::rp($r->stayInvoice?->total_amount).'.',
                'Simpan kode '.$r->reservation_code.' dan token '.$r->token().' untuk membuka halaman Cek pesanan di situs Wisma Trans.',
            ];
        $this->kirimEmail($r, 'Reservasi '.$r->reservation_code.' Wisma Trans', $baris, $r->status === 'pending_payment' ? 'Bayar uang muka' : 'Cek pengajuan');
    }

    /** Teks WhatsApp setelah uang muka diterima, berisi tautan unduh nota; dipakai juga untuk kirim ulang manual. */
    public function teksUangMukaDiterima(Reservation $r): string
    {
        $r->loadMissing('customer', 'stayInvoice');
        $nama = Str::before($r->customer->full_name, ' ');
        $nota = $r->stayInvoice;

        return "Halo {$nama}, uang muka reservasi {$r->reservation_code} sebesar ".self::rp($r->dp_amount_required)
            ." sudah kami terima. Reservasi Anda terkonfirmasi.\n\n"
            .'Check-in mulai pukul '.ReservasiService::jamCheckIn().' WITA, '.$r->masuk()?->translatedFormat('l, j M Y').".\n"
            .($nota ? 'Sisa '.self::rp($nota->sisa())." dilunasi di resepsionis.\n\nNota {$nota->invoice_number} (PDF): {$this->notaPdf->tautan($nota)}\n" : '')
            ."Cek pesanan: {$r->urlStatus()}";
    }

    public function uangMukaDiterima(Reservation $r): void
    {
        $r->loadMissing('customer', 'stayInvoice');
        $this->kirimWa($r, $this->teksUangMukaDiterima($r));

        $nota = $r->stayInvoice;
        $this->kirimEmail($r, 'Uang muka diterima, reservasi '.$r->reservation_code.' terkonfirmasi', [
            'Uang muka '.self::rp($r->dp_amount_required).' untuk reservasi '.$r->reservation_code.' sudah kami terima. Reservasi Anda terkonfirmasi.',
            'Check-in mulai pukul '.ReservasiService::jamCheckIn().' WITA pada '.$r->masuk()?->translatedFormat('l, j F Y').'. Sisa pembayaran dilunasi di resepsionis.',
            $nota ? 'Nota '.$nota->invoice_number.' kami lampirkan dalam bentuk PDF.' : '',
        ], 'Lihat reservasi', $nota ? fn () => [$this->notaPdf->isi($nota), $this->notaPdf->namaBerkas($nota)] : null);
    }

    public function dibatalkanOtomatis(Reservation $r): void
    {
        $nama = Str::before($r->customer->full_name, ' ');
        $this->kirimWa($r, "Halo {$nama}, reservasi {$r->reservation_code} dibatalkan otomatis karena uang muka tidak dibayar sampai batas waktu. "
            .'Silakan pesan ulang bila masih ingin menginap: '.route('kamar'));
    }

    /** Disetujui: bukti pemesanan (kode bukti) dikirim ke PIC. Ditolak: alasan dikirim lewat WhatsApp. */
    public function keputusanInstansi(Reservation $r, ?string $alasan = null): void
    {
        if ($r->status === 'confirmed') {
            $this->kirimBukti($r, disetujui: true);

            return;
        }
        $nama = Str::before($r->customer->full_name, ' ');
        $this->kirimWa($r, "Halo {$nama}, mohon maaf, pengajuan reservasi {$r->reservation_code} belum dapat kami terima".($alasan ? ": {$alasan}" : '.')
            ."\nCek rincian: {$r->urlStatus()}");
    }

    /** Teks WhatsApp bukti pemesanan instansi, berisi tautan PDF bertanda tangan. */
    public function teksBukti(Reservation $r, bool $disetujui = false): string
    {
        $r->loadMissing('rooms.roomType', 'customer', 'institution', 'request');
        $nama = Str::before($r->customer->full_name, ' ');

        return "Halo {$nama}, ".($disetujui ? "pengajuan reservasi {$r->reservation_code} disetujui." : "berikut bukti pemesanan {$r->reservation_code} dengan data terbaru.")."\n\n"
            .($r->institution ? "{$r->institution->name}\n" : '')
            .BuktiInstansi::ringkasKamar($r).', '.ReservasiService::rentang($r->masuk(), $r->keluar())."\n"
            .'Check-in mulai pukul '.ReservasiService::jamCheckIn()." WITA.\n\n"
            ."Kode bukti: {$r->kodeBukti()}\n"
            ."Bukti pemesanan (PDF): {$this->bukti->tautan($r)}\n\n"
            .'Teruskan kode ini kepada staf yang ditugaskan menginap. Sebutkan atau tunjukkan di resepsionis saat kedatangan.';
    }

    /** Kirim bukti ke PIC: WhatsApp berisi tautan PDF, email dengan PDF terlampir. PDF selalu dibuat dari data terbaru. */
    public function kirimBukti(Reservation $r, bool $disetujui = false): void
    {
        if (! BuktiInstansi::berlaku($r)) {
            return;
        }
        $r->loadMissing('rooms.roomType', 'customer', 'institution', 'request');
        $this->kirimWa($r, $this->teksBukti($r, $disetujui));
        $this->kirimEmail($r, ($disetujui ? 'Pengajuan disetujui, bukti pemesanan ' : 'Bukti pemesanan ').$r->reservation_code.' Wisma Trans', [
            $disetujui ? 'Pengajuan reservasi instansi '.$r->reservation_code.' disetujui.' : 'Berikut bukti pemesanan '.$r->reservation_code.' dengan data terbaru.',
            BuktiInstansi::ringkasKamar($r).', '.$r->masuk()?->translatedFormat('j F Y').' sampai '.$r->keluar()?->translatedFormat('j F Y')
                .'. Check-in mulai pukul '.ReservasiService::jamCheckIn().' WITA.',
            'Kode bukti: '.$r->kodeBukti().'. Bukti pemesanan kami lampirkan dalam bentuk PDF.',
            'Teruskan kode ini kepada staf yang ditugaskan menginap. Sebutkan atau tunjukkan di resepsionis saat kedatangan.',
        ], 'Lihat reservasi', fn () => [$this->bukti->isi($r), $this->bukti->namaBerkas($r)]);
    }

    /** Check-in berhasil: kode kamar dan tautan Resto untuk memesan makanan dan laundry ke kamar. Gagal kirim tidak membatalkan check-in. */
    public function selamatDatang(Reservation $r): void
    {
        $r->loadMissing(['rooms' => fn ($q) => $q->where('status', 'checked_in')->with('room'), 'customer']);
        $kamar = $r->rooms->map(fn ($b) => $b->room?->room_number)->filter()->join(', ');
        $this->kirimWa($r, "Selamat datang di Wisma Trans. Kamar Anda: {$kamar}. "
            ."Pesan makanan dan laundry ke kamar dengan kode kamar {$r->kodeKamar()}. "
            ."Makanan: {$r->urlPesanMakanan()} · Laundry: {$r->urlPesanLaundry()}");
    }

    /** Check-out selesai: ucapan terima kasih dengan tautan nota PDF. Gagal kirim tidak membatalkan check-out. */
    public function terimaKasih(Reservation $r): void
    {
        $r->loadMissing('customer', 'institution');
        $folio = app(FolioService::class);
        $nota = collect([$folio->notaMenginap($r), $folio->notaLayanan($r)])->filter()->values();
        $nama = Str::before((string) $r->customer?->full_name, ' ');
        $tautan = $nota->map(fn ($n) => 'Nota '.$n->invoice_number.': '.$this->notaPdf->tautan($n))->join("\n");
        $kredit = $nota->contains('payment_term', 'credit') && $r->institution ? "\nTagihan dikirim ke {$r->institution->name}." : '';

        $this->kirimWa($r, "Terima kasih telah menginap di Wisma Trans, {$nama}. Check-out {$r->reservation_code} sudah selesai.{$kredit}\n\n{$tautan}\n\nSampai jumpa kembali.");
        $utama = $nota->first();
        $this->kirimEmail($r, 'Terima kasih telah menginap, '.$r->reservation_code.' Wisma Trans', [
            'Check-out '.$r->reservation_code.' sudah selesai. Terima kasih telah menginap di Wisma Trans.',
            $utama ? 'Nota '.$utama->invoice_number.' kami lampirkan dalam bentuk PDF.' : null,
            $kredit !== '' ? trim($kredit) : null,
        ], 'Lihat reservasi', $utama ? fn () => [$this->notaPdf->isi($utama), $this->notaPdf->namaBerkas($utama)] : null);
    }

    /**
     * Kirim ulang nota apa pun (menginap, layanan, aula) ke pelanggannya: WhatsApp berisi tautan PDF, email dengan PDF terlampir.
     * Mengembalikan kanal yang dicoba (whatsapp, email).
     *
     * @return list<string>
     */
    public function kirimNota(Invoice $nota): array
    {
        $nota->loadMissing('customer');
        $c = $nota->customer;
        $kanal = [];
        $teks = 'Halo '.Str::before((string) $c?->full_name, ' ').', berikut nota '.$nota->invoice_number.' Wisma Trans senilai '
            .self::rp($nota->total_amount).".\n\nUnduh nota: ".$this->notaPdf->tautan($nota);
        if ($c?->phone) {
            try {
                if ($this->wa->kirim($c->phone, $teks)) {
                    $kanal[] = 'whatsapp';
                }
            } catch (Throwable $e) {
                Log::warning('Kirim ulang nota lewat WhatsApp gagal', ['nota' => $nota->invoice_number, 'pesan' => $e->getMessage()]);
            }
        }
        if ($c?->email) {
            try {
                Notification::route('mail', $c->email)->notify(new PesanReservasi(
                    'Nota '.$nota->invoice_number.' Wisma Trans', 'Halo, '.Str::before($c->full_name, ' ').'.',
                    ['Berikut nota '.$nota->invoice_number.' senilai '.self::rp($nota->total_amount).'. Nota kami lampirkan dalam bentuk PDF.'],
                    'Unduh nota', $this->notaPdf->tautan($nota), [$this->notaPdf->isi($nota), $this->notaPdf->namaBerkas($nota)],
                ));
                $kanal[] = 'email';
            } catch (Throwable $e) {
                Log::warning('Kirim ulang nota lewat email gagal', ['nota' => $nota->invoice_number, 'pesan' => $e->getMessage()]);
            }
        }

        return $kanal;
    }

    private function kirimWa(Reservation $r, string $teks): void
    {
        try {
            $this->wa->kirim($r->customer?->phone, $teks);
        } catch (Throwable $e) {
            Log::warning('Notifikasi WhatsApp reservasi gagal', ['reservasi' => $r->reservation_code, 'pesan' => $e->getMessage()]);
        }
    }

    /**
     * @param  (callable(): array{0: string, 1: string})|null  $lampiran  dibuat hanya bila email benar-benar dikirim
     */
    private function kirimEmail(Reservation $r, string $subjek, array $baris, string $tombol, ?callable $lampiran = null): void
    {
        $email = $r->customer?->email;
        if (! $email) {
            return;
        }
        try {
            Notification::route('mail', $email)->notify(new PesanReservasi(
                $subjek, 'Halo, '.Str::before($r->customer->full_name, ' ').'.', array_values(array_filter($baris)), $tombol, $r->urlStatus(),
                $lampiran ? $lampiran() : null,
            ));
        } catch (Throwable $e) {
            Log::warning('Email reservasi gagal dikirim', ['reservasi' => $r->reservation_code, 'pesan' => $e->getMessage()]);
        }
    }
}
