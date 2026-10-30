<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email ringkas: pesan reservasi (disusun App\Services\Notifikasi\NotifikasiReservasi) dan pengingat untuk staf. */
class PesanReservasi extends Notification
{
    /**
     * @param  list<string>  $baris
     */
    public function __construct(
        public string $subjek,
        public string $sapaan,
        public array $baris,
        public ?string $tombol = null,
        public ?string $url = null,
        /** @var array{0: string, 1: string}|null [isi berkas, nama berkas], misalnya nota PDF */
        public ?array $lampiran = null,
        /** @var list<string> tembusan, misalnya narahubung utama saat tagihan dikirim ke email kantor instansi */
        public array $cc = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pesan = (new MailMessage)->subject($this->subjek)->greeting($this->sapaan);
        foreach ($this->baris as $i => $b) {
            // Tombol tampil setelah baris pertama, sisanya keterangan
            $pesan->line($b);
            if ($i === 0 && $this->tombol && $this->url) {
                $pesan->action($this->tombol, $this->url);
            }
        }

        if ($this->cc) {
            $pesan->cc($this->cc);
        }

        if ($this->lampiran) {
            $pesan->attachData($this->lampiran[0], $this->lampiran[1], ['mime' => 'application/pdf']);
        }

        return $pesan->salutation('Salam hangat, Wisma Trans');
    }
}
