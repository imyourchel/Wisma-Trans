<?php

namespace App\Livewire\Staf\Concerns;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\FolioService;
use Closure;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * QRIS Midtrans di meja (Check-out, Nota): QR senilai sisa saat dibuat, dipindai tamu, dipantau setiap 5 detik.
 * Tampilannya ada di partial livewire.staf.partials.qris-meja.
 */
trait QrisMeja
{
    /** QR yang sedang ditampilkan: nota, order_id, jumlah, qr */
    #[Locked]
    public ?array $qris = null;

    /**
     * Benar bila QR berhasil dibuat; galat ditaruh di kunci 'jumlah'.
     * $nota boleh berupa closure, supaya nota baru dibuat hanya bila QR memang bisa ditampilkan.
     */
    protected function tampilkanQris(Invoice|Closure $nota, int $jumlah): bool
    {
        if (! PembayaranMidtrans::siap()) {
            $this->addError('jumlah', 'Pembayaran Midtrans belum diatur.');

            return false;
        }
        if ($jumlah <= 0) {
            $this->addError('jumlah', 'Tidak ada sisa tagihan.');

            return false;
        }
        $nota = $nota instanceof Closure ? $nota() : $nota;
        $nota->jumlahTetap = $jumlah;
        try {
            $t = app(PembayaranMidtrans::class)->buatTagihan($nota, 'qris');
        } catch (Throwable $e) {
            Log::error('QRIS di meja gagal dibuat', ['nota' => $nota->invoice_number, 'pesan' => $e->getMessage()]);
            $this->addError('jumlah', 'QRIS sedang tidak dapat dipakai. Coba lagi atau catat pembayaran manual.');

            return false;
        }
        $this->qris = ['nota' => $nota->id, 'order_id' => $t['order_id'], 'jumlah' => $t['jumlah'], 'qr' => $t['qr']];

        return true;
    }

    public function cekQris(PembayaranMidtrans $midtrans): void
    {
        if (! $this->qris) {
            return;
        }
        if ($nota = Invoice::find($this->qris['nota'])) {
            $midtrans->sinkronkan($nota);
        }
        $bayar = Payment::where('midtrans_order_id', $this->qris['order_id'])->first();
        if ($bayar?->status === 'success') {
            $this->dispatch('toast', teks: 'Pembayaran QRIS '.FolioService::rp($bayar->amount).' diterima.');
            $this->qris = null;
        } elseif (in_array($bayar?->status, ['expired', 'failed'], true)) {
            $this->dispatch('toast', teks: 'QRIS kedaluwarsa atau gagal. Buat ulang bila perlu.', peringatan: true);
            $this->qris = null;
        }
    }

    public function batalQris(PembayaranMidtrans $midtrans): void
    {
        if ($this->qris && $nota = Invoice::find($this->qris['nota'])) {
            $midtrans->akhiriTagihan($nota);
        }
        $this->qris = null;
    }
}
