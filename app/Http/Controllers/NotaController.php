<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\NotaPdf;
use Illuminate\Http\Response;

/** Unduh nota PDF lewat tautan bertanda tangan (dari WhatsApp, halaman reservasi, atau panel staf). */
class NotaController extends Controller
{
    public function unduh(Invoice $nota, NotaPdf $pdf): Response
    {
        abort_if($nota->status === 'cancelled', 404);

        return response($pdf->isi($nota), 200, [
            'Content-Type' => 'application/pdf',
            // inline: langsung terbuka di ponsel; nama berkas dipakai saat disimpan
            'Content-Disposition' => 'inline; filename="'.$pdf->namaBerkas($nota).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
