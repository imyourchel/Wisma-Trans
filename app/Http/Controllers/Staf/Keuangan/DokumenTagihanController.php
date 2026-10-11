<?php

namespace App\Http\Controllers\Staf\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\BillingStatement;
use App\Models\Invoice;
use App\Services\PengingatPenagihan;
use App\Services\TagihanBulananService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Dokumen penagihan instansi: PDF tagihan bulanan (Manager, dan tautan bertanda tangan untuk narahubung instansi
 * tanpa akun), serta surat tagihan resmi untuk tagihan di atas ambang (khusus Manager, dicetak dan dikirim manual).
 */
class DokumenTagihanController extends Controller
{
    public function tagihanBulanan(BillingStatement $tagihan, TagihanBulananService $bulanan): Response
    {
        return $this->pdf($bulanan->pdf($tagihan), $bulanan->namaBerkas($tagihan));
    }

    /** Dari WhatsApp/email narahubung; draft dan tagihan batal tidak bisa dibuka. */
    public function unduhPublik(BillingStatement $tagihan, TagihanBulananService $bulanan): Response
    {
        abort_if(in_array($tagihan->status, ['draft', 'cancelled'], true), 404);

        return $this->pdf($bulanan->pdf($tagihan), $bulanan->namaBerkas($tagihan));
    }

    public function surat(string $jenis, int $id): Response
    {
        $t = $jenis === 'nota' ? Invoice::with('institution.primaryContact', 'items')->findOrFail($id)
            : BillingStatement::with('institution.primaryContact')->findOrFail($id);
        abort_unless($t->institution, 404);
        $nomor = PengingatPenagihan::nomor($t);

        $isi = Pdf::setOption(['isFontSubsettingEnabled' => true])->loadView('pdf.surat-tagihan', [
            't' => $t,
            'nomor' => $nomor,
            'sisa' => PengingatPenagihan::sisa($t),
            'rekening' => TagihanBulananService::rekening(),
            'penerima' => TagihanBulananService::penerima($t->institution),
        ])->setPaper('a4')->output();

        return $this->pdf($isi, 'Surat-tagihan-'.str_replace('/', '-', $nomor).'.pdf');
    }

    private function pdf(string $isi, string $nama): Response
    {
        return response($isi, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nama.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
