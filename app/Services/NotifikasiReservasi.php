<?php

namespace App\Services;

use App\Models\Reservation;
use App\Notifications\PesanReservasi;
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
    public function __construct(private WhatsAppService $wa, private NotaPdf $notaPdf) {}

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

    public function keputusanInstansi(Reservation $r, ?string $alasan = null): void
    {
        $nama = Str::before($r->customer->full_name, ' ');
        $this->kirimWa($r, $r->status === 'confirmed'
            ? "Halo {$nama}, pengajuan reservasi {$r->reservation_code} disetujui. ".$r->kamarBerlaku()->count().' kamar, '
                .$r->masuk()?->translatedFormat('j M').' – '.$r->keluar()?->translatedFormat('j M Y').".\nCek rincian: {$r->urlStatus()}"
            : "Halo {$nama}, mohon maaf, pengajuan reservasi {$r->reservation_code} belum dapat kami terima".($alasan ? ": {$alasan}" : '.')
                ."\nCek rincian: {$r->urlStatus()}");
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
