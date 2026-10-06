<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;

/**
 * Nota dalam bentuk PDF: dilampirkan di email, dan diunduh lewat tautan bertanda tangan (signed URL)
 * yang dikirim ke WhatsApp, sehingga tamu tidak perlu masuk akun untuk membukanya.
 */
class NotaPdf
{
    /** Tautan unduh berlaku 90 hari; setelah itu tamu tetap bisa mengunduh dari halaman reservasinya. */
    public const MASA_BERLAKU_HARI = 90;

    public function isi(Invoice $nota): string
    {
        $nota->loadMissing(['items', 'customer', 'reservation.rooms.room', 'reservation.rooms.roomType', 'reservation.institution',
            'payments' => fn ($q) => $q->where('status', 'success')->orderBy('paid_at')]);

        // Hanya huruf yang dipakai yang disematkan, supaya lampiran email tetap kecil
        return Pdf::setOption(['isFontSubsettingEnabled' => true])
            ->loadView('pdf.nota', ['nota' => $nota])->setPaper('a4')->output();
    }

    /** INV/2026/10/0010 menjadi Nota-INV-2026-10-0010.pdf */
    public function namaBerkas(Invoice $nota): string
    {
        return 'Nota-'.str_replace('/', '-', $nota->invoice_number).'.pdf';
    }

    public function tautan(Invoice $nota): string
    {
        return URL::temporarySignedRoute('nota.unduh', now()->addDays(self::MASA_BERLAKU_HARI), ['nota' => $nota->id]);
    }
}
