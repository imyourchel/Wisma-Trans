<?php

namespace App\Services\Keuangan;

use App\Models\BillingStatement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reservasi\FolioService;
use App\Support\Nomor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran manual di meja (tunai, QRIS mesin EDC, kartu, transfer) dan antrean pengembalian dana.
 * Satu nota boleh dibayar beberapa kali, misalnya sebagian tunai dan sebagian QRIS.
 *
 * Kewajiban mengembalikan dana selalu berupa payment refund berstatus pending (satu per nota), lalu
 * diselesaikan (success, paid_amount nota berkurang) atau dihanguskan (expired) bila tamu tidak mengambilnya.
 * Uang yang benar-benar masuk selalu menambah paid_amount, sehingga kelebihan = paid_amount − total
 * (atau seluruh paid_amount bila nota dibatalkan).
 */
class PembayaranService
{
    public const METODE = [
        'cash' => 'Tunai',
        'qris' => 'QRIS',
        'debit_card' => 'Kartu debit',
        'credit_card' => 'Kartu kredit',
        'bank_transfer' => 'Transfer bank',
    ];

    public const TUJUAN = ['settlement', 'service', 'hall', 'other'];

    public function __construct(private NotaService $nota, private FolioService $folio, private PembayaranMidtrans $midtrans) {}

    /**
     * Sisa yang harus dibayar. Selama tamu masih menginap, nota folio memakai hitungan folio
     * (termasuk layanan tagihan kamar yang baru digabung saat check-out).
     */
    public function sisaTagihan(Invoice $nota, array $opsi = []): float
    {
        if ($nota->status === 'cancelled') {
            return 0.0;
        }

        return (float) ($this->folio->sisaNota($nota, $opsi)['sisa'] ?? $nota->sisa());
    }

    /** Kelebihan bayar kotor (belum dikurangi refund yang masih menunggu). Nota batal: seluruh yang terbayar. */
    public function lebihBayar(Invoice $nota): float
    {
        if ($nota->status === 'cancelled') {
            return max(0, (float) $nota->paid_amount);
        }
        $folio = $this->folio->sisaNota($nota);

        return (float) ($folio['lebih'] ?? max(0, (float) $nota->paid_amount - (float) $nota->total_amount));
    }

    /** Tujuan pembayaran menurut jenis nota. */
    public static function tujuan(Invoice $nota): string
    {
        return match ($nota->invoice_type) {
            'service' => 'service',
            'hall' => 'hall',
            default => 'settlement',
        };
    }

    public function catatManual(Invoice $nota, float $jumlah, string $metode, string $tujuan, User $oleh, ?string $catatan = null): Payment
    {
        if (! array_key_exists($metode, self::METODE)) {
            throw ValidationException::withMessages(['metode' => 'Pilih metode pembayaran.']);
        }
        if (! in_array($tujuan, self::TUJUAN, true)) {
            throw ValidationException::withMessages(['jumlah' => 'Tujuan pembayaran tidak dikenal.']);
        }
        $jumlah = round($jumlah);
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Isi jumlah lebih dari nol.']);
        }
        if ($nota->status === 'cancelled') {
            throw ValidationException::withMessages(['jumlah' => 'Nota ini sudah dibatalkan.']);
        }

        $bayar = DB::transaction(function () use ($nota, $jumlah, $metode, $tujuan, $oleh, $catatan) {
            $nota = Invoice::whereKey($nota->id)->lockForUpdate()->first();
            // Batas teratas memakai tanggal pesanan, jadi pilihan "tagih sesuai pesanan" tetap bisa dilunasi
            $batas = $this->sisaTagihan($nota);
            if ($jumlah > $batas) {
                throw ValidationException::withMessages(['jumlah' => $batas > 0 ? 'Melebihi sisa tagihan '.FolioService::rp($batas).'.' : 'Nota ini sudah lunas.']);
            }

            $bayar = Payment::create([
                'payment_code' => Nomor::pembayaran(),
                'invoice_id' => $nota->id,
                'transaction_type' => 'payment',
                'payment_purpose' => $tujuan,
                'amount' => $jumlah,
                'method' => $metode,
                'channel' => 'manual',
                'status' => 'success',
                'paid_at' => now(),
                'received_by' => $oleh->id,
                'note' => $catatan !== null ? mb_substr($catatan, 0, 255) : null,
            ]);
            $nota->paid_amount = (float) $nota->paid_amount + $jumlah;
            $this->nota->hitungUlang($nota);

            return $bayar;
        });

        // Sisa berubah: QR yang sempat dibuat untuk jumlah lama diakhiri
        $this->midtrans->akhiriTagihan($nota);

        return $bayar;
    }

    /**
     * Pembayaran tagihan bulanan instansi: satu payment atas statement (invoice_id kosong), lalu jumlahnya dibagikan
     * ke paid_amount nota anggota mulai dari tanggal terbit paling lama. Nota anggota tidak punya payment sendiri,
     * jadi status lunasnya dibaca dari paid_amount, bukan Invoice::terbayar().
     */
    public function catatTagihanBulanan(BillingStatement $s, float $jumlah, string $metode, User $oleh, ?string $catatan = null): Payment
    {
        if (! array_key_exists($metode, self::METODE)) {
            throw ValidationException::withMessages(['metode' => 'Pilih metode pembayaran.']);
        }
        $jumlah = round($jumlah);
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Isi jumlah lebih dari nol.']);
        }

        return DB::transaction(function () use ($s, $jumlah, $metode, $oleh, $catatan) {
            $s = BillingStatement::whereKey($s->id)->lockForUpdate()->first();
            if (! in_array($s->status, ['issued', 'partially_paid', 'overdue'], true)) {
                throw ValidationException::withMessages(['jumlah' => $s->status === 'draft' ? 'Terbitkan tagihan bulanan ini dulu.' : 'Tagihan bulanan ini tidak menunggu pembayaran.']);
            }
            if ($jumlah > $s->sisa()) {
                throw ValidationException::withMessages(['jumlah' => 'Melebihi sisa tagihan '.FolioService::rp($s->sisa()).'.']);
            }

            $bayar = Payment::create([
                'payment_code' => Nomor::pembayaran(),
                'billing_statement_id' => $s->id,
                'transaction_type' => 'payment',
                'payment_purpose' => 'settlement',
                'amount' => $jumlah,
                'method' => $metode,
                'channel' => 'manual',
                'status' => 'success',
                'paid_at' => now(),
                'received_by' => $oleh->id,
                'note' => $catatan !== null ? mb_substr($catatan, 0, 255) : null,
            ]);
            $s->paid_amount = (float) $s->paid_amount + $jumlah;

            $sisaBagi = $jumlah;
            $anggota = $s->invoices()->where('status', '!=', 'cancelled')->orderBy('issue_date')->orderBy('id')->lockForUpdate()->get();
            foreach ($anggota as $nota) {
                $kurang = max(0, (float) $nota->total_amount - (float) $nota->paid_amount);
                if ($sisaBagi <= 0 || $kurang <= 0) {
                    continue;
                }
                $bagian = min($kurang, $sisaBagi);
                $nota->paid_amount = (float) $nota->paid_amount + $bagian;
                $this->nota->hitungUlang($nota);
                $sisaBagi -= $bagian;
            }
            $this->nota->hitungTagihanBulanan($s);

            return $bayar;
        });
    }

    /* ======================== Antrean pengembalian dana ======================== */

    /** Refund yang masih menunggu dikembalikan untuk nota ini (paling banyak satu). */
    public function refundTertunda(Invoice $nota): ?Payment
    {
        return Payment::where('invoice_id', $nota->id)->where('transaction_type', 'refund')->where('status', 'pending')->first();
    }

    /**
     * Ajukan pengembalian dana. Bila nota sudah punya refund pending, jumlahnya ditambahkan ke sana.
     * Tidak mengubah paid_amount; itu terjadi saat refund diselesaikan.
     */
    public function ajukanRefund(Invoice $nota, float $jumlah, string $alasan, ?Payment $asal = null): Payment
    {
        $jumlah = round($jumlah);
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah pengembalian harus lebih dari nol.']);
        }
        $catatan = $alasan.($asal ? ' · asal '.$asal->payment_code : '');

        return DB::transaction(function () use ($nota, $jumlah, $catatan, $asal) {
            $ada = Payment::where('invoice_id', $nota->id)->where('transaction_type', 'refund')->where('status', 'pending')->lockForUpdate()->first();
            if ($ada) {
                $ada->update([
                    'amount' => (float) $ada->amount + $jumlah,
                    'note' => mb_substr($ada->note.' | '.$catatan, 0, 255),
                ]);

                return $ada;
            }

            return Payment::create([
                'payment_code' => Nomor::pembayaran(),
                'invoice_id' => $nota->id,
                'transaction_type' => 'refund',
                'payment_purpose' => $asal?->payment_purpose ?? self::tujuan($nota),
                'amount' => $jumlah,
                // Sementara; diganti metode sebenarnya saat dana dikembalikan
                'method' => $asal && array_key_exists($asal->method, self::METODE) ? $asal->method : 'cash',
                'channel' => 'manual',
                'status' => 'pending',
                'note' => mb_substr($catatan, 0, 255),
            ]);
        });
    }

    /**
     * Ajukan refund sebesar kelebihan bayar yang belum masuk antrean, supaya sumber yang berbeda
     * (QR lama, check-out, koreksi) tidak membuat kewajiban dobel untuk nota yang sama.
     */
    public function ajukanKelebihan(Invoice $nota, string $alasan, ?Payment $asal = null): ?Payment
    {
        $nota = $nota->fresh();
        $tertunda = (float) ($this->refundTertunda($nota)?->amount ?? 0);
        $baru = round($this->lebihBayar($nota) - $tertunda);

        return $baru > 0 ? $this->ajukanRefund($nota, $baru, $alasan, $asal) : null;
    }

    /** Dana sudah dikembalikan ke tamu: refund menjadi success dan paid_amount nota berkurang. */
    public function selesaikanRefund(Payment $pending, string $metode, User $oleh, ?string $catatan = null): Payment
    {
        if (! array_key_exists($metode, self::METODE)) {
            throw ValidationException::withMessages(['metode' => 'Pilih metode pengembalian.']);
        }

        return DB::transaction(function () use ($pending, $metode, $oleh, $catatan) {
            $refund = Payment::whereKey($pending->id)->lockForUpdate()->first();
            if ($refund?->transaction_type !== 'refund' || $refund->status !== 'pending') {
                throw ValidationException::withMessages(['refund' => 'Pengembalian dana ini sudah diproses.']);
            }
            $refund->update([
                'status' => 'success',
                'method' => $metode,
                'paid_at' => now(),
                'received_by' => $oleh->id,
                'note' => mb_substr(trim($refund->note.($catatan ? ' · '.trim($catatan) : '')), 0, 255),
            ]);
            $nota = Invoice::whereKey($refund->invoice_id)->lockForUpdate()->first();
            $nota->paid_amount = max(0, (float) $nota->paid_amount - (float) $refund->amount);
            $this->nota->hitungUlang($nota);

            return $refund;
        });
    }

    /** Tamu tidak mengambil dananya: refund dihanguskan (expired), paid_amount tidak berubah. */
    public function hanguskanRefund(Payment $pending, string $alasan, User $oleh): Payment
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw ValidationException::withMessages(['alasan' => 'Isi alasan menghanguskan pengembalian dana.']);
        }

        return DB::transaction(function () use ($pending, $alasan, $oleh) {
            $refund = Payment::whereKey($pending->id)->lockForUpdate()->first();
            if ($refund?->transaction_type !== 'refund' || $refund->status !== 'pending') {
                throw ValidationException::withMessages(['refund' => 'Pengembalian dana ini sudah diproses.']);
            }
            $refund->update([
                'status' => 'expired',
                'note' => mb_substr($refund->note.' · Dihanguskan '.$oleh->name.' '.now()->translatedFormat('j M Y H.i').': '.$alasan, 0, 255),
            ]);

            return $refund;
        });
    }

    /** Kembalikan dana langsung di meja (check-out): ajukan bila belum ada, lalu selesaikan dalam satu langkah. */
    public function kembalikanDiMeja(Invoice $nota, string $metode, User $oleh, string $alasan): ?Payment
    {
        $pending = $this->refundTertunda($nota) ?? $this->ajukanKelebihan($nota, $alasan);

        return $pending ? $this->selesaikanRefund($pending, $metode, $oleh) : null;
    }
}
