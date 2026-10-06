<?php

namespace App\Services;

use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kirim pesan WhatsApp lewat Fonnte. Token dibaca dari FONNTE_TOKEN (config/services.php).
 * Kegagalan kirim tidak pernah menggagalkan transaksi: hasilnya hanya dicatat di log,
 * dan staf bisa mengirim ulang manual lewat tautan wa.me (tautan()).
 */
class WhatsAppService
{
    public static function siap(): bool
    {
        return filled(config('services.fonnte.token'));
    }

    public function kirim(?string $nomor, string $pesan): bool
    {
        if (! $nomor) {
            return false;
        }
        if (! self::siap()) {
            Log::info('WhatsApp tidak dikirim: FONNTE_TOKEN belum diisi', ['tujuan' => $nomor]);

            return false;
        }

        try {
            $jawab = Http::asForm()->timeout(15)
                ->withHeaders(['Authorization' => (string) config('services.fonnte.token')])
                ->post('https://api.fonnte.com/send', ['target' => $nomor, 'message' => $pesan, 'countryCode' => '62']);

            if ($jawab->successful() && $jawab->json('status') !== false) {
                return true;
            }
            Log::warning('WhatsApp gagal dikirim', ['tujuan' => $nomor, 'jawaban' => $jawab->body()]);
        } catch (Throwable $e) {
            Log::warning('WhatsApp gagal dikirim', ['tujuan' => $nomor, 'pesan' => $e->getMessage()]);
        }

        return false;
    }

    /** Tautan wa.me berisi pesan siap kirim, untuk kirim ulang manual oleh staf. */
    public static function tautan(?string $nomor, string $pesan): string
    {
        $tujuan = $nomor ? preg_replace('/^0/', '62', Phone::normalize($nomor)) : '';

        return 'https://wa.me/'.$tujuan.'?text='.rawurlencode($pesan);
    }
}
