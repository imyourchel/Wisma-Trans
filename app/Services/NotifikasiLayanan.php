<?php

namespace App\Services;

use App\Models\HallBooking;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pemberitahuan WhatsApp untuk laundry dan sewa aula. Teksnya dipakai juga untuk tombol kirim ulang (wa.me)
 * di panel staf. Kegagalan kirim hanya dicatat di log.
 */
class NotifikasiLayanan
{
    public function __construct(private WhatsAppService $wa, private NotaPdf $notaPdf) {}

    private static function rp(float|int|string|null $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    /* ---------------- Laundry ---------------- */

    public function teksLaundry(ServiceOrder $o): string
    {
        $o->loadMissing('items.service', 'invoice', 'customer', 'reservationRoom.room');
        $nama = Str::before((string) $o->customer?->full_name, ' ');
        $item = $o->items->first();
        $kamar = $o->reservationRoom?->room?->room_number;

        return match ($o->status) {
            'pending' => "Halo {$nama}, pesanan laundry {$o->order_code} sudah kami terima. "
                .($kamar ? "Petugas kami menjemput cucian ke kamar {$kamar}." : 'Silakan antar cucian ke resepsionis Wisma Trans.')
                .' Tagihan dihitung setelah cucian ditimbang ('.self::rp($item?->unit_price).'/kg, minimal '.LaundryService::kg(LaundryService::MIN_KG).").\n\nPantau pesanan: {$o->urlStatus()}",
            'in_progress' => "Halo {$nama}, cucian {$o->order_code} sudah ditimbang: {$item?->note}. Tagihan ".self::rp($item?->subtotal)
                .($o->payment_mode === 'charge_to_room'
                    ? ' ditagihkan ke kamar Anda.'
                    : ($o->sudahDibayar() ? ', sudah dibayar.' : '. Bayar online lewat tautan di bawah, atau tunai saat mengambil.'))
                ."\nSelesai sekitar ".$o->updated_at?->copy()->addDay()->translatedFormat('j M, H.i')." WITA.\n\nPantau dan bayar: {$o->urlStatus()}",
            'ready' => "Halo {$nama}, cucian {$o->order_code} sudah selesai. "
                .($o->order_type === 'in_house' ? "Petugas kami mengantarnya ke kamar {$kamar}." : 'Silakan ambil di resepsionis Wisma Trans.')
                .($o->payment_mode === 'pay_now' && ! $o->sudahDibayar() ? ' Tagihan '.self::rp($o->invoice?->total_amount).' dibayar saat mengambil.' : '')
                ."\n{$o->urlStatus()}",
            default => "Halo {$nama}, pesanan laundry {$o->order_code}: ".($o->keadaanTamu()[1] ?? $o->status).".\n{$o->urlStatus()}",
        };
    }

    public function laundry(ServiceOrder $o): void
    {
        $this->kirim($o->customer?->phone, $this->teksLaundry($o), $o->order_code);
    }

    /* ---------------- Makanan dan minuman ---------------- */

    public function teksMakanan(ServiceOrder $o): string
    {
        $o->loadMissing('customer', 'reservationRoom.room');
        $nama = Str::before((string) $o->customer?->full_name, ' ');
        $kamar = $o->reservationRoom?->room?->room_number;

        return match ($o->status) {
            'ready' => "Halo {$nama}, pesanan {$o->order_code} sudah siap. ".match ($o->serving_type) {
                'take_away' => 'Silakan ambil di restoran Wisma Trans.',
                'room_service' => "Segera kami antar ke kamar {$kamar}.",
                default => 'Segera kami sajikan di meja Anda.',
            }."\n\nPantau pesanan: {$o->urlStatus()}",
            default => "Halo {$nama}, pesanan {$o->order_code}: ".($o->keadaanTamu()[1] ?? $o->status).".\n{$o->urlStatus()}",
        };
    }

    /** Kabar pesanan siap hanya dikirim ke tamu luar; tamu menginap diantar ke kamar. */
    public function makanan(ServiceOrder $o): void
    {
        if ($o->order_type === 'outside_guest') {
            $this->kirim($o->customer?->phone, $this->teksMakanan($o), $o->order_code);
        }
    }

    /* ---------------- Aula ---------------- */

    public function teksAula(HallBooking $b): string
    {
        $b->loadMissing('hall', 'customer', 'invoice');
        $nama = Str::before((string) $b->customer?->full_name, ' ');
        $jadwal = $b->jadwal().' WITA';

        return match ($b->status) {
            'pending' => "Halo {$nama}, pengajuan sewa {$b->hall->name} ({$b->booking_code}) untuk {$jadwal} sudah kami terima. "
                .'Staf kami menghubungi Anda untuk membicarakan kebutuhan acara dan harga sewa.',
            'confirmed' => "Halo {$nama}, sewa {$b->hall->name} ({$b->booking_code}) untuk {$jadwal} terkonfirmasi. Harga sewa ".self::rp($b->rental_price).'.'
                .($b->invoice
                    ? ' Mohon dilunasi paling lambat '.$b->invoice->due_date?->translatedFormat('j F Y').".\n\nNota {$b->invoice->invoice_number} (PDF): {$this->notaPdf->tautan($b->invoice)}"
                    : ' Tagihan dikirim ke instansi bersama tagihan bulanannya.'),
            'rejected' => "Halo {$nama}, mohon maaf, pengajuan sewa {$b->hall->name} ({$b->booking_code}) untuk {$jadwal} belum dapat kami terima."
                .(preg_match('/Ditolak: (.+)$/m', (string) $b->note, $m) ? " Alasan: {$m[1]}" : ''),
            'cancelled' => "Halo {$nama}, sewa {$b->hall->name} ({$b->booking_code}) untuk {$jadwal} dibatalkan.",
            default => "Halo {$nama}, terima kasih sudah memakai {$b->hall->name} Wisma Trans.",
        };
    }

    public function aula(HallBooking $b): void
    {
        $this->kirim($b->customer?->phone, $this->teksAula($b), $b->booking_code);
    }

    private function kirim(?string $hp, string $teks, string $kode): void
    {
        try {
            $this->wa->kirim($hp, $teks);
        } catch (Throwable $e) {
            Log::warning('Notifikasi WhatsApp layanan gagal', ['kode' => $kode, 'pesan' => $e->getMessage()]);
        }
    }
}
