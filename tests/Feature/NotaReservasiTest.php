<?php

namespace Tests\Feature;

use App\Livewire\Staf\Keuangan\Nota;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Reservation;
use App\Notifications\PesanReservasi;
use App\Services\Keuangan\NotaPdf;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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

    public function test_N04_kirim_ulang_nota_pribadi_ke_pelanggan(): void
    {
        $nota = $this->bayar($this->reservasi())->stayInvoice;
        Notification::fake();
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);

        Livewire::actingAs($this->akun('fo'))->test(Nota::class)->call('open', $nota->id)->call('kirimUlang')
            ->assertDispatched('toast', teks: 'Nota dikirim ke Rizky Pratama (WhatsApp dan email).');
        Http::assertSent(fn (Request $q) => $q['target'] === '081299110022' && str_contains($q['message'], 'Halo Rizky, berikut nota INV/2026/10/0001 Wisma Trans senilai'));
        Notification::assertSentOnDemand(PesanReservasi::class, fn (PesanReservasi $n, $k, $tujuan) => $tujuan->routes['mail'] === 'rizky@contoh.id' && $n->cc === []);
        Notification::assertSentOnDemandTimes(PesanReservasi::class, 1);
    }

    public function test_N05_kirim_ulang_nota_instansi_ke_email_kantor_tembusan_narahubung_dan_pemohon(): void
    {
        $nota = $this->bayar($this->reservasi())->stayInvoice;
        $siti = Customer::create(['full_name' => 'Siti Rahma', 'phone' => '081377700001', 'email' => 'siti@vale.test']);
        $vale = Institution::create(['name' => 'PT Vale Indonesia Tbk', 'address' => 'Jl. Sumantri Brojonegoro, Sorowako', 'email' => 'kantor@vale.test',
            'primary_contact_id' => $siti->id, 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $siti->update(['institution_id' => $vale->id]);
        $nota->update(['institution_id' => $vale->id]);
        Notification::fake();
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);

        // Email kantor, tembusan narahubung utama dan pemohon reservasi; WhatsApp ke narahubung utama, bukan pemohon
        Livewire::actingAs($this->akun('fo'))->test(Nota::class)->call('open', $nota->id)->call('kirimUlang')
            ->assertDispatched('toast', teks: 'Nota dikirim ke PT Vale Indonesia Tbk (email kantor, tembusan Siti Rahma dan Rizky Pratama; WhatsApp Siti Rahma).');
        Notification::assertSentOnDemand(PesanReservasi::class, fn (PesanReservasi $n, $k, $tujuan) => $tujuan->routes['mail'] === 'kantor@vale.test'
            && $n->cc === ['siti@vale.test', 'rizky@contoh.id'] && $n->sapaan === 'Yth. PT Vale Indonesia Tbk,'
            && str_contains($n->baris[0], 'atas nama PT Vale Indonesia Tbk') && $n->toMail($tujuan)->cc === [['siti@vale.test', null], ['rizky@contoh.id', null]]);
        Http::assertSent(fn (Request $q) => $q['target'] === '081377700001' && str_contains($q['message'], 'Halo Siti, berikut nota INV/2026/10/0001 Wisma Trans atas nama PT Vale Indonesia Tbk'));
        Http::assertNotSent(fn (Request $q) => $q['target'] === '081299110022');

        // Email kantor kosong: langsung ke narahubung utama, pemohon tetap ditembuskan
        $vale->update(['email' => null]);
        Notification::fake();
        Livewire::actingAs($this->akun('fo'))->test(Nota::class)->call('open', $nota->id)->call('kirimUlang')
            ->assertDispatched('toast', teks: 'Nota dikirim ke PT Vale Indonesia Tbk (email Siti Rahma, tembusan Rizky Pratama; WhatsApp Siti Rahma).');
        Notification::assertSentOnDemand(PesanReservasi::class, fn (PesanReservasi $n, $k, $tujuan) => $tujuan->routes['mail'] === 'siti@vale.test' && $n->cc === ['rizky@contoh.id']);

        // Pemohon = narahubung utama: tidak ditembuskan dua kali
        $vale->update(['email' => 'kantor@vale.test']);
        $nota->update(['customer_id' => $siti->id]);
        Notification::fake();
        Livewire::actingAs($this->akun('fo'))->test(Nota::class)->call('open', $nota->id)->call('kirimUlang')
            ->assertDispatched('toast', teks: 'Nota dikirim ke PT Vale Indonesia Tbk (email kantor, tembusan Siti Rahma; WhatsApp Siti Rahma).');
        Notification::assertSentOnDemand(PesanReservasi::class, fn (PesanReservasi $n, $k, $tujuan) => $tujuan->routes['mail'] === 'kantor@vale.test' && $n->cc === ['siti@vale.test']);
    }
}
