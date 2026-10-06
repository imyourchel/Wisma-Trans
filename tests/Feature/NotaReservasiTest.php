<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Reservation;
use App\Notifications\PesanReservasi;
use App\Services\NotaPdf;
use App\Services\PembayaranMidtrans;
use App\Services\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Nota reservasi: setelah uang muka dibayar, tautan nota dikirim ke WhatsApp dan PDF-nya ke email. */
class NotaReservasiTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
    }

    private function reservasi(): Reservation
    {
        $tamu = Customer::create(['full_name' => 'Rizky Pratama', 'phone' => '081299110022', 'email' => 'rizky@contoh.id']);

        return app(ReservasiService::class)->buatOnline($tamu, CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-12'), [$this->tipe('DLX')->id => 1]);
    }

    private function bayar(Reservation $r): Reservation
    {
        (new PembayaranMidtrans)->terapkan(['order_id' => 'WT-RSV0001-DP-AAAA', 'transaction_status' => 'settlement',
            'gross_amount' => (string) $r->dp_amount_required, 'payment_type' => 'bank_transfer', 'settlement_time' => '2026-10-06 10:05:00']);

        return $r->fresh();
    }

    public function test_N01_uang_muka_dibayar_mengirim_tautan_nota_ke_whatsapp_dan_pdf_ke_email(): void
    {
        $r = $this->bayar($this->reservasi());
        $nota = $r->stayInvoice;
        $this->assertSame('INV/2026/10/0001', $nota->invoice_number);

        Http::assertSent(fn (Request $req) => $req['target'] === '081299110022'
            && str_contains($req['message'], 'uang muka reservasi RSV-0001 sebesar Rp460.000 sudah kami terima')
            && str_contains($req['message'], 'Nota INV/2026/10/0001 (PDF): '.url('/nota/'.$nota->id))
            && str_contains($req['message'], 'signature='));

        Notification::assertSentOnDemand(PesanReservasi::class, function (PesanReservasi $n, array $saluran, $tujuan) {
            $surat = $n->toMail($tujuan);

            return $tujuan->routes['mail'] === 'rizky@contoh.id'
                && $surat->rawAttachments[0]['name'] === 'Nota-INV-2026-10-0001.pdf'
                && str_starts_with($surat->rawAttachments[0]['data'], '%PDF');
        });
    }

    public function test_N02_tautan_nota_harus_bertanda_tangan(): void
    {
        $r = $this->bayar($this->reservasi());
        $tautan = app(NotaPdf::class)->tautan($r->stayInvoice);

        $jawab = $this->get($tautan)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $jawab->getContent());
        $this->assertStringContainsString('Nota-INV-2026-10-0001.pdf', $jawab->headers->get('Content-Disposition'));

        // Tanpa tanda tangan atau nomor nota diganti: ditolak
        $this->get(route('nota.unduh', ['nota' => $r->stayInvoice->id]))->assertForbidden();
        $this->assertContains($this->get(str_replace('/nota/'.$r->stayInvoice->id, '/nota/999', $tautan))->status(), [403, 404]);
    }

    public function test_N03_tombol_unduh_nota_muncul_setelah_ada_pembayaran(): void
    {
        $r = $this->reservasi();
        $this->get($r->urlStatus())->assertOk()->assertDontSee('Unduh nota (PDF)');

        $r = $this->bayar($r);
        $this->get($r->urlStatus())->assertOk()->assertSee('Unduh nota (PDF)');
    }
}
