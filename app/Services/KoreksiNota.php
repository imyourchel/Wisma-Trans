<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perubahan nota oleh Manager: diskon nota, koreksi atau hapus baris, dan pembatalan.
 * Setiap perubahan diakhiri NotaService::hitungUlang, dicatat di note beserta nama dan waktunya,
 * dan bila hasilnya lebih bayar, kelebihannya masuk antrean pengembalian dana.
 */
class KoreksiNota
{
    public function __construct(private NotaService $nota, private PembayaranService $pembayaran, private FolioService $folio) {}

    /** Alasan nota tidak bisa diubah, atau null bila boleh. */
    public function penghalang(Invoice $n): ?string
    {
        return match (true) {
            $n->status === 'cancelled' => 'Nota ini sudah dibatalkan.',
            $n->billing_statement_id !== null => 'Bagian dari tagihan bulanan '.$n->billingStatement?->statement_number.'.',
            $this->masihMenginap($n) => 'Tamu masih menginap: nota ini hanya bisa dibayar. Koreksi dilakukan setelah check-out.',
            default => null,
        };
    }

    /** Nota folio (menginap atau layanan menginap) milik reservasi yang masih check-in. */
    public function masihMenginap(Invoice $n): bool
    {
        return $n->reservation?->status === 'checked_in'
            && ($n->invoice_type === 'stay' || str_starts_with((string) $n->note, FolioService::NOTA_LAYANAN));
    }

    public function diskon(Invoice $n, string $jenis, float $nilai, string $alasan, User $oleh): Invoice
    {
        $alasan = $this->alasan($alasan);
        $this->pastikanBoleh($n);
        if ($n->status === 'paid') {
            throw ValidationException::withMessages(['diskon' => 'Nota yang sudah lunas tidak bisa diberi diskon.']);
        }
        $subtotal = (float) $n->items()->sum('subtotal');
        $galat = match (true) {
            ! in_array($jenis, ['percent', 'amount'], true) => 'Pilih bentuk diskon.',
            $nilai <= 0 => 'Isi nilai diskon lebih dari nol.',
            $jenis === 'percent' && $nilai > 100 => 'Diskon persen paling besar 100%.',
            $jenis === 'amount' && $nilai > $subtotal => 'Diskon melebihi subtotal '.FolioService::rp($subtotal).'.',
            default => null,
        };
        if ($galat) {
            throw ValidationException::withMessages(['diskon' => $galat]);
        }

        return $this->ubah($n, function (Invoice $n) use ($jenis, $nilai, $alasan, $oleh) {
            $n->fill(['discount_type' => $jenis, 'discount_value' => $nilai]);
            $this->catat($n, 'Diskon '.($jenis === 'percent' ? rtrim(rtrim(number_format($nilai, 2, ',', ''), '0'), ',').'%' : FolioService::rp($nilai)).': '.$alasan, $oleh);
        }, 'Diskon nota');
    }

    public function ubahBaris(InvoiceItem $baris, float $jumlah, float $harga, string $alasan, User $oleh): Invoice
    {
        $alasan = $this->alasan($alasan);
        $n = $baris->invoice;
        $this->pastikanBoleh($n);
        if ($jumlah <= 0 || $harga < 0) {
            throw ValidationException::withMessages(['baris' => 'Isi jumlah lebih dari nol dan harga tidak negatif.']);
        }

        return $this->ubah($n, function (Invoice $n) use ($baris, $jumlah, $harga, $alasan, $oleh) {
            $kotor = $jumlah * $harga;
            $potong = match ($baris->discount_type) {
                'percent' => round($kotor * (float) $baris->discount_value / 100),
                'amount' => min($kotor, (float) $baris->discount_value),
                default => 0.0,
            };
            $lama = (float) $baris->quantity.' × '.FolioService::rp($baris->unit_price);
            $baris->update(['quantity' => $jumlah, 'unit_price' => $harga, 'discount_amount' => $potong, 'subtotal' => $kotor - $potong]);
            $this->catat($n, "Koreksi \"{$baris->description}\" dari {$lama} menjadi {$jumlah} × ".FolioService::rp($harga).": {$alasan}", $oleh);
        }, 'Koreksi nota');
    }

    /** Hapus baris layanan; bila baris itu pesanan (ORD/…), pesanannya dibatalkan dan dilepas dari nota. */
    public function hapusBaris(InvoiceItem $baris, string $alasan, User $oleh): Invoice
    {
        $alasan = $this->alasan($alasan);
        $n = $baris->invoice;
        $this->pastikanBoleh($n);
        if ($baris->item_type === 'room') {
            throw ValidationException::withMessages(['baris' => 'Baris kamar tidak bisa dihapus; ubah jumlah malamnya.']);
        }

        return $this->ubah($n, function (Invoice $n) use ($baris, $alasan, $oleh) {
            $pesanan = ServiceOrder::where('invoice_id', $n->id)->get()
                ->first(fn (ServiceOrder $o) => str_contains($baris->description, $o->order_code));
            if ($pesanan) {
                $pesanan->update([
                    'status' => 'cancelled',
                    'invoice_id' => null,
                    'note' => mb_substr(trim(($pesanan->note ? $pesanan->note."\n" : '').'Dibatalkan lewat koreksi nota '.$n->invoice_number.': '.$alasan), 0, 255),
                ]);
            }
            $baris->delete();
            $this->catat($n, "Hapus \"{$baris->description}\" (".FolioService::rp($baris->subtotal)."): {$alasan}", $oleh);
        }, 'Koreksi nota');
    }

    public function batalkan(Invoice $n, string $alasan, User $oleh): Invoice
    {
        $alasan = $this->alasan($alasan);
        $this->pastikanBoleh($n);
        if ($n->payments()->where('transaction_type', 'payment')->where('status', 'success')->exists()) {
            throw ValidationException::withMessages(['batal' => 'Nota yang sudah dibayar tidak bisa dibatalkan.']);
        }

        return DB::transaction(function () use ($n, $alasan, $oleh) {
            $n = Invoice::whereKey($n->id)->lockForUpdate()->first();
            $n->status = 'cancelled';
            $this->catat($n, 'Dibatalkan: '.$alasan, $oleh);
            $n->save();

            return $n;
        });
    }

    private function ubah(Invoice $n, callable $langkah, string $sebab): Invoice
    {
        $n = DB::transaction(function () use ($n, $langkah) {
            $n = Invoice::whereKey($n->id)->lockForUpdate()->first();
            $langkah($n);

            return $this->nota->hitungUlang($n);
        });
        // Total turun di bawah yang sudah dibayar: kelebihannya dikembalikan ke tamu
        $this->pembayaran->ajukanKelebihan($n, $sebab.' '.$n->invoice_number.' membuat lebih bayar');

        return $n->fresh();
    }

    private function pastikanBoleh(Invoice $n): void
    {
        if ($alasan = $this->penghalang($n)) {
            throw ValidationException::withMessages(['nota' => $alasan]);
        }
    }

    private function alasan(string $alasan): string
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw ValidationException::withMessages(['alasan' => 'Isi alasan.']);
        }

        return mb_substr($alasan, 0, 150);
    }

    /** Tambahkan ke catatan nota beserta nama dan waktu; awalan catatan (misalnya "Layanan menginap RSV-…") tetap. */
    private function catat(Invoice $n, string $teks, User $oleh): void
    {
        $baris = $teks.' ('.$oleh->name.', '.now()->translatedFormat('j M Y H.i').')';
        $n->note = mb_substr(trim(($n->note ? $n->note.' | ' : '').$baris), 0, 255);
    }
}
