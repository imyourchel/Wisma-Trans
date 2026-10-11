<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Livewire\Staf\Keuangan\Nota;
use App\Livewire\Staf\Keuangan\Pembayaran;
use App\Models\BillingStatement;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Services\CheckOutService;
use App\Services\KoreksiNota;
use App\Services\NotaService;
use App\Services\PembayaranMidtrans;
use App\Services\PembayaranService;
use App\Services\PesananMakananService;
use App\Services\ReservasiService;
use App\Support\Nomor;
use App\Support\RekapKas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Menu Keuangan: Nota, Pembayaran, rekap kas, dan antrean pengembalian dana. */
class KeuanganTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private Service $nasi;

    private int $hp = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Carbon::setTestNow('2026-10-06 15:00:00');
        $this->withoutVite();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte', 'services.midtrans.server_key' => 'uji', 'services.midtrans.client_key' => 'uji']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        // Tidak pernah memanggil Midtrans sandbox sungguhan
        $this->app->instance(PembayaranMidtrans::class, new class extends PembayaranMidtrans
        {
            public function buatTagihan(BayarOnline $x, string $metode): array
            {
                $t = ['metode' => $metode, 'order_id' => $x->awalanMidtrans().'-UJI1', 'jumlah' => $x->jumlahBayar(), 'nomor' => null, 'kode_perusahaan' => null, 'qr' => null];
                Cache::put(self::kunci($x, 'tagihan'), $t);
                $this->catatPercobaan($x, $t['order_id'], $t['jumlah']);

                return $t;
            }

            public function sinkronkan(BayarOnline $x): void {}

            public function akhiriTagihan(BayarOnline $x): void
            {
                Cache::forget(self::kunci($x, 'tagihan'));
            }
        });
        $this->nasi = Service::create(['code' => 'NG01', 'name' => 'Nasi Goreng', 'category' => 'fnb', 'menu_group' => 'Makanan Utama',
            'department' => 'fnb', 'unit' => 'porsi', 'price' => 35000, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- Bantuan ---------------- */

    private function pelanggan(string $nama = 'Lukas Wibowo'): Customer
    {
        return Customer::create(['full_name' => $nama, 'phone' => '0852110088'.str_pad((string) ++$this->hp, 2, '0', STR_PAD_LEFT)]);
    }

    /** Nota langsung dengan satu baris; jenis nota service (baris fnb/laundry) atau hall. */
    private function nota(float $total = 100000, string $baris = 'fnb', string $jenis = 'service'): Invoice
    {
        $n = Invoice::create(['invoice_number' => Nomor::nota(), 'invoice_type' => $jenis, 'customer_id' => $this->pelanggan()->id,
            'payment_term' => 'immediate', 'issue_date' => today()->toDateString(), 'status' => 'issued']);
        $n->items()->create(['item_type' => $baris, 'description' => $baris === 'hall' ? 'Sewa Aula Utama' : 'Nasi Goreng', 'service_date' => today()->toDateString(),
            'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total]);

        return app(NotaService::class)->hitungUlang($n);
    }

    private function bayarPenuh(Invoice $n): Invoice
    {
        app(PembayaranService::class)->catatManual($n, (float) $n->total_amount, 'cash', 'service', $this->akun('fo'));

        return $n->fresh();
    }

    private function layarNota(string $akun = 'fo')
    {
        return Livewire::actingAs($this->akun($akun))->test(Nota::class);
    }

    /** Tamu datang langsung kamar 107 (6–7 Okt), memesan makanan tagihan kamar, lalu check-out lunas. */
    private function sudahCheckOut(): array
    {
        $fo = $this->akun('fo');
        $r = app(ReservasiService::class)->buatLangsung($this->pelanggan('Yohanes Lado'), CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-07'), [$this->kamar('107')->id], $fo);
        app(ReservasiService::class)->checkIn($r, [], false, null, $fo);
        $r = $r->fresh();
        $o = app(PesananMakananService::class)->buatDariKasir([['id' => $this->nasi->id, 'qty' => 2]], $r->rooms->first()->load('reservation'), null, 'room_service', true, null, null, $this->akun('fnb'));
        Carbon::setTestNow('2026-10-07 10:00:00');
        $nota = $r->stayInvoice;
        app(PembayaranService::class)->catatManual($nota, 430000, 'cash', 'settlement', $fo);
        app(CheckOutService::class)->proses($r, [$r->rooms->first()->id], [], $fo);

        return [$r->fresh(), $o->fresh(), $nota->fresh()];
    }

    private function antrean(): Collection
    {
        return Payment::where('transaction_type', 'refund')->where('status', 'pending')->get();
    }

    /* ---------------- 1. Pembayaran ---------------- */

    public function test_K01_bayar_sebagian_lalu_sisanya_dan_tidak_boleh_melebihi_sisa(): void
    {
        $n = $this->nota(100000);
        $layar = $this->layarNota()->call('open', $n->id)->assertSee($n->invoice_number)->assertSee('Rp100.000');

        $layar->call('mulai', 'bayar')->set('jumlah', '150000')->set('metode', 'cash')->call('bayar')->assertHasErrors('jumlah');
        $this->assertSame('issued', $n->fresh()->status);

        $layar->call('mulai', 'bayar')->set('jumlah', '40000')->set('metode', 'cash')->call('bayar')->assertHasNoErrors();
        $this->assertSame(['partially_paid', 40000.0], [$n->fresh()->status, (float) $n->fresh()->paid_amount]);

        $layar->call('mulai', 'bayar')->set('jumlah', '')->set('metode', 'qris')->call('bayar')->assertHasNoErrors();
        $this->assertSame(['paid', 100000.0], [$n->fresh()->status, (float) $n->fresh()->paid_amount]);
        $this->assertSame([40000.0, 60000.0], Payment::where('invoice_id', $n->id)->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['service', 'manual', 3], [Payment::where('invoice_id', $n->id)->value('payment_purpose'), Payment::where('invoice_id', $n->id)->value('channel'), (int) Payment::where('invoice_id', $n->id)->first()->received_by]);
    }

    /* ---------------- 2. Hak akses ---------------- */

    public function test_K02_fnb_hanya_melihat_nota_makanan_dan_housekeeping_ditolak(): void
    {
        $makanan = $this->nota(70000, 'fnb');
        $laundry = $this->nota(30000, 'laundry');
        $aula = $this->nota(2000000, 'hall', 'hall');
        $fo = $this->akun('fo');
        $r = app(ReservasiService::class)->buatLangsung($this->pelanggan('Yohanes Lado'), CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-07'), [$this->kamar('107')->id], $fo);
        $menginap = $r->stayInvoice;
        $this->bayarPenuh($makanan);
        $this->bayarPenuh($aula);

        $this->layarNota('fnb')->assertSee($makanan->invoice_number)
            ->assertDontSee($menginap->invoice_number)->assertDontSee($aula->invoice_number)->assertDontSee($laundry->invoice_number);
        $this->layarNota('fo')->assertSee($makanan->invoice_number)->assertSee($menginap->invoice_number)->assertSee($aula->invoice_number);
        Livewire::actingAs($this->akun('fnb'))->test(Pembayaran::class)->assertSee('Rp70.000')->assertDontSee('Rp2.000.000');
        try {
            $this->layarNota('fnb')->call('open', $menginap->id);
            $this->fail('F&B seharusnya tidak bisa membuka nota menginap.');
        } catch (ModelNotFoundException) {
        }

        foreach (['staf.keuangan.nota', 'staf.keuangan.pembayaran', 'staf.keuangan.pembayaran.cetak'] as $rute) {
            $this->actingAs($this->akun('hk'))->get(route($rute))->assertForbidden();
        }
        $this->actingAs($this->akun('fnb'))->get(route('staf.keuangan.nota'))->assertOk();
    }

    /* ---------------- 3. Diskon dan koreksi ---------------- */

    public function test_K03_diskon_hanya_manager_beralasan_dan_tidak_untuk_nota_lunas(): void
    {
        $n = $this->nota(100000);
        $this->layarNota('fo')->call('open', $n->id)->call('mulai', 'diskon')->assertForbidden();
        $this->layarNota('fo')->call('open', $n->id)->set('diskonNilai', '10')->set('alasan', 'Langganan')->call('simpanDiskon')->assertForbidden();

        $layar = $this->layarNota('manager')->call('open', $n->id)->call('mulai', 'diskon')
            ->set('diskonJenis', 'percent')->set('diskonNilai', '10')->set('alasan', '')->call('simpanDiskon')->assertHasErrors('alasan');
        $layar->set('alasan', 'Tamu langganan')->call('simpanDiskon')->assertHasNoErrors();
        $n->refresh();
        $this->assertSame(['percent', 10000.0, 90000.0], [$n->discount_type, (float) $n->discount_amount, (float) $n->total_amount]);
        $this->assertStringContainsString('Diskon 10%: Tamu langganan (Lina Hartono, 6 Okt 2026 15.00)', $n->note);

        $lunas = $this->bayarPenuh($this->nota(50000));
        $this->layarNota('manager')->call('open', $lunas->id)->assertDontSee('Diskon nota')
            ->set('diskonNilai', '10')->set('alasan', 'Coba')->call('simpanDiskon')->assertHasErrors('diskon');
        $this->assertNull($lunas->fresh()->discount_type);
    }

    public function test_K04_koreksi_yang_membuat_lebih_bayar_mengajukan_refund(): void
    {
        $n = $this->bayarPenuh($this->nota(100000));
        $baris = $n->items()->sole();
        $this->layarNota('manager')->call('open', $n->id)->call('mulai', 'koreksi', $baris->id)
            ->set('barisJumlah', '1')->set('barisHarga', '60000')->set('alasan', 'Salah harga')->call('simpanBaris')->assertHasNoErrors();

        $n->refresh();
        $this->assertSame([60000.0, 100000.0, 'paid'], [(float) $n->total_amount, (float) $n->paid_amount, $n->status]);
        $refund = $this->antrean()->sole();
        $this->assertSame([$n->id, 40000.0], [$refund->invoice_id, (float) $refund->amount]);
        $this->layarNota('manager')->call('open', $n->id)->assertSee('Lebih bayar')->assertSee('Rp40.000 menunggu dikembalikan');
    }

    public function test_K05_hapus_baris_pesanan_membatalkan_pesanan_dan_melepas_nota(): void
    {
        [, $o, $nota] = $this->sudahCheckOut();
        $this->assertSame([$nota->id, 430000.0], [$o->invoice_id, (float) $nota->total_amount]);
        $baris = $nota->items()->where('item_type', 'fnb')->sole();

        $this->layarNota('manager')->call('open', $nota->id)->call('mulai', 'hapus', $baris->id)
            ->set('alasan', 'Tamu tidak menerima pesanan')->call('hapusBaris')->assertHasNoErrors();
        $o->refresh();
        $this->assertSame(['cancelled', null], [$o->status, $o->invoice_id]);
        $this->assertStringContainsString('Dibatalkan lewat koreksi nota', $o->note);
        $this->assertSame(360000.0, (float) $nota->fresh()->total_amount);
        $this->assertSame(70000.0, (float) $this->antrean()->sole()->amount);
    }

    /* ---------------- 4. Larangan perubahan ---------------- */

    public function test_K06_larangan_batal_lunas_tagihan_bulanan_dan_tamu_menginap(): void
    {
        $koreksi = app(KoreksiNota::class);
        $lunas = $this->bayarPenuh($this->nota(50000));
        $this->layarNota('manager')->call('open', $lunas->id)->call('mulai', 'batal')->set('alasan', 'Salah input')->call('batalkanNota')->assertHasErrors('batal');
        $this->assertSame('paid', $lunas->fresh()->status);

        // Nota dalam tagihan bulanan
        $i = Institution::create(['name' => 'PT PLN UP3 Palopo', 'default_billing_scheme' => 'monthly_fixed_date', 'billing_day' => 25]);
        $bs = BillingStatement::create(['statement_number' => 'BS/2026/10/0001', 'institution_id' => $i->id, 'period_start' => '2026-09-26',
            'period_end' => '2026-10-25', 'due_date' => '2026-11-08', 'status' => 'issued']);
        $bulanan = $this->nota(80000);
        $bulanan->update(['billing_statement_id' => $bs->id, 'payment_term' => 'credit']);
        $this->layarNota('manager')->call('open', $bulanan->id)->assertSee('Bagian dari tagihan bulanan BS/2026/10/0001.')->assertDontSee('Batalkan nota');
        try {
            $koreksi->ubahBaris($bulanan->items()->sole(), 1, 1000, 'Coba', $this->akun('manager'));
            $this->fail('Nota tagihan bulanan seharusnya tidak bisa dikoreksi.');
        } catch (ValidationException $e) {
            $this->assertSame('Bagian dari tagihan bulanan BS/2026/10/0001.', $e->errors()['nota'][0]);
        }

        // Nota menginap reservasi yang masih check-in: hanya bisa dibayar
        $fo = $this->akun('fo');
        $r = app(ReservasiService::class)->buatLangsung($this->pelanggan('Yohanes Lado'), CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-08'), [$this->kamar('107')->id], $fo);
        app(ReservasiService::class)->checkIn($r, [], false, null, $fo);
        $menginap = $r->fresh()->stayInvoice;
        $this->layarNota('manager')->call('open', $menginap->id)->assertSee('Tamu masih menginap')
            ->set('diskonNilai', '10')->set('alasan', 'Coba')->call('simpanDiskon')->assertHasErrors('nota');
        $this->layarNota('fo')->call('open', $menginap->id)->assertSee('Catat pembayaran')
            ->call('mulai', 'bayar')->set('jumlah', '100000')->call('bayar')->assertHasNoErrors();
        $this->assertSame(100000.0, (float) $menginap->fresh()->paid_amount);
    }

    /* ---------------- 5. Reservasi batal ---------------- */

    public function test_K07_reservasi_batal_mengajukan_refund_lalu_diselesaikan(): void
    {
        $r = app(ReservasiService::class)->buatOnline($this->pelanggan('Hasan Basri'), CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-22'), [$this->tipe('STD')->id => 1]);
        $r->update(['dp_amount_required' => 500000]);
        $nota = $r->stayInvoice;
        $dp = Payment::create(['payment_code' => 'PAY-0901', 'invoice_id' => $nota->id, 'transaction_type' => 'payment', 'payment_purpose' => 'down_payment',
            'amount' => 500000, 'method' => 'virtual_account', 'channel' => 'online', 'status' => 'success', 'paid_at' => now()]);
        $nota->update(['paid_amount' => 500000]);
        app(ReservasiService::class)->uangMukaDiterima($r, 'Uji');

        $kembali = app(ReservasiService::class)->batalkan($r->fresh(), 'Acara batal', null);
        $this->assertSame(250000.0, $kembali);
        $refund = $this->antrean()->sole();
        $this->assertSame([$nota->id, 250000.0, 'down_payment', 'PAY-0901'], [$refund->invoice_id, (float) $refund->amount, $refund->payment_purpose, $refund->kodeAsal()]);
        $this->assertSame([500000.0, 'cancelled'], [(float) $nota->fresh()->paid_amount, $nota->fresh()->status]);

        $layar = Livewire::actingAs($this->akun('fo'))->test(Pembayaran::class)->set('tab', 'refund')
            ->assertSee($nota->invoice_number)->assertSee('Rp250.000')->assertSee('PAY-0901');
        $layar->call('mulai', $refund->id, 'selesai')->set('metodeRefund', 'bank_transfer')->set('catatan', 'Transfer ke BCA')->call('selesaikan')->assertHasNoErrors()
            ->assertSee('Tidak ada dana yang perlu dikembalikan.');
        $refund->refresh();
        $this->assertSame(['success', 'bank_transfer', 3], [$refund->status, $refund->method, (int) $refund->received_by]);
        $this->assertNotNull($refund->paid_at);
        $this->assertSame([250000.0, 'cancelled'], [(float) $nota->fresh()->paid_amount, $nota->fresh()->status]);
        $this->assertSame($dp->id, Payment::where('payment_code', $refund->kodeAsal())->value('id'));
    }

    /* ---------------- 6. Pesanan makanan ---------------- */

    public function test_K08_pesanan_lunas_dibatalkan_dan_bayar_ganda_lewat_midtrans(): void
    {
        $layanan = app(PesananMakananService::class);
        $o = $layanan->buatDariKasir([['id' => $this->nasi->id, 'qty' => 2]], null, $this->pelanggan(), 'take_away', false, 'cash', null, $this->akun('fnb'));
        $layanan->batalkan($o->fresh('invoice'), 'Tamu pergi');
        $refund = $this->antrean()->sole();
        $this->assertSame([$o->invoice_id, 70000.0], [$refund->invoice_id, (float) $refund->amount]);
        $this->assertSame(['cancelled', 'cancelled'], [$o->fresh()->status, $o->fresh()->invoice->status]);

        // Pesanan lain sudah lunas di kasir, lalu notifikasi Midtrans sukses masuk
        $lain = $layanan->buatDariKasir([['id' => $this->nasi->id, 'qty' => 1]], null, $this->pelanggan('Rina Pasorong'), 'take_away', false, 'cash', null, $this->akun('fnb'));
        (new PembayaranMidtrans)->terapkan(['order_id' => $lain->awalanMidtrans().'-UJI9', 'transaction_status' => 'settlement', 'gross_amount' => '35000.00', 'payment_type' => 'qris']);
        $nota = $lain->fresh()->invoice;
        $this->assertSame([70000.0, 'paid'], [(float) $nota->paid_amount, $nota->status]);
        $this->assertSame(35000.0, (float) $this->antrean()->firstWhere('invoice_id', $nota->id)->amount);
        $this->assertCount(2, $this->antrean());
    }

    /* ---------------- 8. Antrean ---------------- */

    public function test_K09_hanguskan_hanya_manager_beralasan_dan_dashboard_menghitung_pending(): void
    {
        $n = $this->bayarPenuh($this->nota(100000));
        $refund = app(PembayaranService::class)->ajukanRefund($n, 30000, 'Uji pengembalian');
        // Pengajuan kedua untuk nota yang sama digabung, tidak dobel
        app(PembayaranService::class)->ajukanRefund($n, 10000, 'Tambahan');
        $this->assertSame(40000.0, (float) $this->antrean()->sole()->amount);
        $this->assertSame(100000.0, (float) $n->fresh()->paid_amount);

        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertSeeInOrder(['Perlu pengembalian dana', '1']);
        $this->assertSame(1, PembayaranMidtrans::jumlahPerluRefund());

        Livewire::actingAs($this->akun('fo'))->test(Pembayaran::class)->set('tab', 'refund')->assertDontSee('>Hanguskan<', false)
            ->call('mulai', $refund->id, 'hangus')->assertForbidden();
        $layar = Livewire::actingAs($this->akun('manager'))->test(Pembayaran::class)->set('tab', 'refund')
            ->call('mulai', $refund->id, 'hangus')->set('catatan', '')->call('hanguskan')->assertHasErrors('catatan');
        $layar->set('catatan', 'Tamu tidak bisa dihubungi 30 hari')->call('hanguskan')->assertHasNoErrors();
        $refund->refresh();
        $this->assertSame('expired', $refund->status);
        $this->assertStringContainsString('Dihanguskan Lina Hartono', $refund->note);
        $this->assertSame([100000.0, 0], [(float) $n->fresh()->paid_amount, PembayaranMidtrans::jumlahPerluRefund()]);
    }

    /* ---------------- 9. Rekap kas ---------------- */

    public function test_K10_rekap_kas_per_metode_mengurangi_refund_berhasil(): void
    {
        $tunai = $this->bayarPenuh($this->nota(100000));
        $qris = $this->nota(50000);
        app(PembayaranService::class)->catatManual($qris, 50000, 'qris', 'service', $this->akun('fnb'));
        $refund = app(PembayaranService::class)->ajukanRefund($tunai, 20000, 'Salah hitung');
        app(PembayaranService::class)->selesaikanRefund($refund, 'cash', $this->akun('fo'));
        app(PembayaranService::class)->ajukanRefund($qris->fresh(), 30000, 'Masih menunggu');
        // Transaksi kemarin tidak ikut
        Payment::create(['payment_code' => 'PAY-0800', 'invoice_id' => $tunai->id, 'transaction_type' => 'payment', 'payment_purpose' => 'service',
            'amount' => 999000, 'method' => 'cash', 'channel' => 'manual', 'status' => 'success', 'paid_at' => '2026-10-05 10:00:00']);

        $rekap = RekapKas::untuk(today(), $this->akun('manager'));
        $this->assertSame(['masuk' => 100000.0, 'keluar' => 20000.0, 'bersih' => 80000.0], $rekap['metode']['cash']);
        $this->assertSame(50000.0, $rekap['metode']['qris']['bersih']);
        $this->assertSame([150000.0, 20000.0, 130000.0, 3], [$rekap['masuk'], $rekap['refund'], $rekap['bersih'], $rekap['jumlah']]);

        Livewire::actingAs($this->akun('manager'))->test(Pembayaran::class)->assertSee('Rp130.000')->assertSee('Menunggu dikembalikan');
        $this->actingAs($this->akun('fo'))->get(route('staf.keuangan.pembayaran.cetak', ['tanggal' => '2026-10-06']))->assertOk()
            ->assertSee('Rekap kas Wisma Trans')->assertSee('Rp130.000');
    }
}
