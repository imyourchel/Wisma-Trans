<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Livewire\Staf\Layanan\PapanPesanan;
use App\Livewire\Staf\Reservasi\CheckOut;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationLog;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\CheckOutService;
use App\Services\FolioService;
use App\Services\KodeKamar;
use App\Services\LaundryService;
use App\Services\NotaService;
use App\Services\PembayaranMidtrans;
use App\Services\PesananMakananService;
use App\Services\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Check-out: penggabungan biaya ke nota, pelunasan, instansi kredit, dan pengosongan kamar. */
class CheckOutTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private Service $nasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        // Setelah data contoh (yang memasang 10.00): sudah lewat jam check-in 14.00
        Carbon::setTestNow('2026-10-06 15:00:00');
        $this->withoutVite();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte', 'services.midtrans.server_key' => 'uji', 'services.midtrans.client_key' => 'uji']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        // Tidak pernah memanggil Midtrans sandbox sungguhan
        $this->app->instance(PembayaranMidtrans::class, new class extends PembayaranMidtrans
        {
            public array $diakhiri = [];

            public function buatTagihan(BayarOnline $x, string $metode): array
            {
                $t = ['metode' => $metode, 'order_id' => $x->awalanMidtrans().'-UJI'.count(Cache::get(self::kunci($x, 'percobaan'), [])),
                    'jumlah' => $x->jumlahBayar(), 'nomor' => null, 'kode_perusahaan' => null, 'qr' => 'https://contoh.test/qr.png'];
                Cache::put(self::kunci($x, 'tagihan'), $t);
                $this->catatPercobaan($x, $t['order_id'], $t['jumlah']);

                return $t;
            }

            public function sinkronkan(BayarOnline $x): void {}

            public function akhiriTagihan(BayarOnline $x): void
            {
                $this->diakhiri[] = $x->kunciBayar();
                Cache::forget(self::kunci($x, 'tagihan'));
            }
        });
        $this->nasi = Service::create(['code' => 'NG01', 'name' => 'Nasi Goreng', 'category' => 'fnb', 'menu_group' => 'Makanan Utama',
            'department' => 'fnb', 'unit' => 'porsi', 'price' => 35000, 'is_active' => true]);
        Service::create(['code' => 'LDR01', 'name' => 'Laundry Kiloan', 'category' => 'laundry', 'menu_group' => 'Laundry',
            'department' => 'housekeeping', 'unit' => 'kg', 'price' => 15000, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- Bantuan ---------------- */

    /** Reservasi online Standard 6–9 Okt (3 malam × 360.000), uang muka 50% dibayar, check-in di kamar 106. */
    private function perorangan(bool $ktp = false, string $keluar = '2026-10-09'): Reservation
    {
        $tamu = Customer::create(['full_name' => 'Hasan Basri', 'phone' => '081322006677']);
        $r = app(ReservasiService::class)->buatOnline($tamu, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse($keluar), [$this->tipe('STD')->id => 1]);
        $nota = $r->stayInvoice;
        Payment::create(['payment_code' => 'PAY-UJI'.$r->id, 'invoice_id' => $nota->id, 'transaction_type' => 'payment', 'payment_purpose' => 'down_payment',
            'amount' => $r->dp_amount_required, 'method' => 'qris', 'channel' => 'online', 'status' => 'success', 'paid_at' => now()]);
        $nota->paid_amount = $r->dp_amount_required;
        app(NotaService::class)->hitungUlang($nota);
        app(ReservasiService::class)->uangMukaDiterima($r, 'Uji');
        app(ReservasiService::class)->checkIn($r->fresh(), [$r->rooms->first()->id => $this->kamar('106')->id], $ktp, $ktp ? 'Hasan Basri' : null, $this->akun('fo'));

        return $r->fresh();
    }

    /** Tamu datang langsung, kamar Standard 6–8 Okt, langsung check-in. */
    private function langsung(array $kamar, string $keluar = '2026-10-08'): Reservation
    {
        $tamu = Customer::create(['full_name' => 'Yohanes Lado', 'phone' => '081355001122']);
        $r = app(ReservasiService::class)->buatLangsung($tamu, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse($keluar),
            array_map(fn ($n) => $this->kamar($n)->id, $kamar), $this->akun('fo'));
        app(ReservasiService::class)->checkIn($r, [], false, null, $this->akun('fo'));

        return $r->fresh();
    }

    /** Reservasi instansi yang disetujui (Kamar 107, Rp300.000/malam, 6–8 Okt) lalu check-in dengan bukti. */
    private function instansi(array $data, string $skemaLayanan = 'satu_nota'): Reservation
    {
        $i = Institution::create(['name' => 'PT PLN UP3 Palopo', ...$data]);
        $pic = Customer::create(['full_name' => 'Fajar Nugroho', 'phone' => '081455500006', 'institution_id' => $i->id]);
        $layanan = app(ReservasiService::class);
        $r = $layanan->ajukanInstansi($pic, $i, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-08'), 1, 'Pemeliharaan jaringan', null);
        $layanan->setujui($r, $i, [['room_id' => $this->kamar('107')->id, 'price' => 300000]], null, $skemaLayanan, null, $this->akun('manager'));
        $r = $r->fresh();
        $layanan->checkIn($r, [], false, null, $this->akun('fo'), $r->access_token);

        return $r->fresh();
    }

    private function baris(Reservation $r, string $nomor)
    {
        return $r->rooms()->with('reservation')->whereHas('room', fn ($q) => $q->where('room_number', $nomor))->where('status', 'checked_in')->firstOrFail();
    }

    private function makanan(Reservation $r, string $kamar, int $porsi): ServiceOrder
    {
        return app(PesananMakananService::class)->buatDariKasir([['id' => $this->nasi->id, 'qty' => $porsi]], $this->baris($r, $kamar), null,
            'room_service', true, null, null, $this->akun('fnb'));
    }

    private function laundry(Reservation $r, string $kamar, ?float $berat): ServiceOrder
    {
        $o = app(LaundryService::class)->terima($r->customer, $this->baris($r, $kamar), true, 3, null, $this->akun('fo'));

        return $berat ? app(LaundryService::class)->timbang($o, $berat, $this->akun('hk')) : $o;
    }

    private function layar(Reservation $r)
    {
        return Livewire::actingAs($this->akun('fo'))->test(CheckOut::class)->call('open', $r->id);
    }

    /* ---------------- Tes ---------------- */

    public function test_C01_perorangan_folio_lunas_lalu_check_out(): void
    {
        $r = $this->perorangan();
        $o1 = $this->makanan($r, '106', 2);
        $o2 = $this->makanan($r, '106', 1);
        $o3 = $this->laundry($r, '106', 3);

        $f = app(FolioService::class)->rincian($r);
        $this->assertSame(1080000.0, (float) $f['kamar']->sum('subtotal'));
        $this->assertSame(150000.0, (float) $f['layanan']->sum('subtotal'));
        $this->assertSame([1230000.0, 540000.0, 690000.0], [$f['total'], $f['sudah_dibayar'], $f['sisa']]);
        $this->assertContains('Makanan '.$o1->order_code.' (2 item)', $f['layanan']->pluck('deskripsi')->all());
        $this->assertContains('Laundry '.$o3->order_code.' (3 kg)', $f['layanan']->pluck('deskripsi')->all());

        Carbon::setTestNow('2026-10-09 10:00:00');
        $layar = $this->layar($r)->assertSee('Sisa tagihan Rp690.000 belum dibayar.');
        $layar->call('checkOut')->assertHasErrors('status');
        $this->assertSame('checked_in', $r->fresh()->status);
        $this->assertNull($o1->fresh()->invoice_id);

        $layar->set('jumlah', '')->set('metode', 'cash')->call('bayar')->assertHasNoErrors();
        $layar->call('checkOut')->assertHasNoErrors();

        $r->refresh();
        $nota = $r->stayInvoice;
        $this->assertSame('checked_out', $r->status);
        $this->assertSame('vacant_dirty', $this->kamar('106')->status->value);
        $this->assertSame('checked_out', $r->rooms->first()->status);
        $this->assertNotNull($r->rooms->first()->actual_check_out_at);
        foreach ([$o1, $o2, $o3] as $o) {
            $this->assertSame($nota->id, $o->fresh()->invoice_id);
        }
        $this->assertSame(['paid', 1230000.0, 1230000.0], [$nota->status, (float) $nota->total_amount, (float) $nota->paid_amount]);
        $this->assertTrue(Payment::where('invoice_id', $nota->id)->where('payment_purpose', 'settlement')->where('amount', 690000)->where('channel', 'manual')->exists());
        $this->assertSame('Check-out '.$r->reservation_code, $this->kamar('106')->statusLogs()->latest('id')->value('note'));
    }

    public function test_C02_laundry_belum_ditimbang_menghalangi_check_out(): void
    {
        $r = $this->perorangan();
        $o = $this->laundry($r, '106', null);
        Carbon::setTestNow('2026-10-09 10:00:00');

        $f = app(FolioService::class)->rincian($r);
        $this->assertContains("Laundry {$o->order_code} belum ditimbang", $f['penghalang']);
        $this->layar($r)->assertSee("Laundry {$o->order_code} belum ditimbang")
            ->set('jumlah', '')->call('bayar')->call('checkOut')->assertHasErrors('status');
        $this->assertSame('checked_in', $r->fresh()->status);
    }

    public function test_C03_terlambat_satu_baris_per_kamar_dan_pembebasan_wajib_beralasan(): void
    {
        $r = $this->langsung(['107', '108']);
        Carbon::setTestNow('2026-10-08 13:00:00');
        [$b107, $b108] = [$this->baris($r, '107'), $this->baris($r, '108')];

        $f = app(FolioService::class)->rincian($r);
        $this->assertCount(2, $f['terlambat']);
        $this->assertSame(500000.0, (float) $f['terlambat']->sum('subtotal'));

        // Tanpa alasan: ditolak, di layar maupun di service
        $this->layar($r)->set("bebas.{$b108->id}", true)->call('checkOut')->assertHasErrors("alasan.{$b108->id}");
        try {
            app(CheckOutService::class)->proses($r, [$b107->id, $b108->id], ['bebas_terlambat' => [$b108->id => ' ']], $this->akun('fo'));
            $this->fail('Pembebasan tanpa alasan seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey("alasan.{$b108->id}", $e->errors());
        }

        $layar = $this->layar($r)->set("bebas.{$b108->id}", true)->set("alasan.{$b108->id}", 'Menunggu jemputan, disetujui Manager');
        $layar->set('jumlah', '')->call('bayar')->assertHasNoErrors()->call('checkOut')->assertHasNoErrors();

        $nota = $r->fresh()->stayInvoice;
        $telat = $nota->items()->where('item_type', 'late_checkout')->get();
        $this->assertSame(['Terlambat check-out Kamar 107'], $telat->pluck('description')->all());
        $this->assertSame(250000.0, (float) $telat->first()->subtotal);
        $this->assertSame((2 * 2 * 360000) + 250000.0, (float) $nota->total_amount);
        $this->assertTrue(ReservationLog::where('reservation_id', $r->id)->where('note', 'Biaya terlambat Kamar 108 dibebaskan: Menunggu jemputan, disetujui Manager')->exists());
    }

    public function test_C04_keluar_lebih_awal_menagih_malam_yang_dipakai_dan_lebih_bayar_dikembalikan(): void
    {
        $r = $this->perorangan();
        Carbon::setTestNow('2026-10-07 10:00:00');

        $f = app(FolioService::class)->rincian($r, null, ['keluar_awal' => 'dipakai']);
        $this->assertSame([360000.0, 180000.0, 0.0], [$f['total'], $f['lebih'], $f['sisa']]);
        // Tagihan sementara (tanpa pilihan check-out) tetap memakai tanggal pesanan
        $this->assertSame(1080000.0, app(FolioService::class)->rincian($r)['total']);

        $layar = $this->layar($r)->assertSee('Tagih malam yang dipakai')->call('checkOut')->assertHasNoErrors()
            ->assertSee('Kembalikan Rp180.000 ke tamu');
        $r->refresh();
        $nota = $r->stayInvoice;
        $this->assertSame('checked_out', $r->status);
        $this->assertSame('2026-10-07', $r->rooms->first()->check_out_date->toDateString());
        $this->assertSame(['Kamar 106, 1 malam'], $nota->items()->where('item_type', 'room')->pluck('description')->all());
        $this->assertSame(360000.0, (float) $nota->total_amount);

        $layar->set('metode', 'cash')->call('kembalikan', 'stay')->assertHasNoErrors();
        $refund = Payment::where('invoice_id', $nota->id)->where('transaction_type', 'refund')->sole();
        $this->assertSame([180000.0, 'cash', 'success'], [(float) $refund->amount, $refund->method, $refund->status]);
        $this->assertSame(['paid', 360000.0], [$nota->fresh()->status, (float) $nota->fresh()->paid_amount]);
    }

    public function test_C05_instansi_jatuh_tempo_nota_kredit_tanpa_pembayaran(): void
    {
        $r = $this->instansi(['default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        Carbon::setTestNow('2026-10-08 10:00:00');

        $this->layar($r)->assertSee('Ditagihkan ke PT PLN UP3 Palopo, jatuh tempo 22 Okt 2026')
            ->call('checkOut')->assertHasNoErrors();

        $r->refresh();
        $nota = $r->stayInvoice;
        $this->assertSame('checked_out', $r->status);
        $this->assertSame(['credit', '2026-10-22', $r->institution_id, 600000.0], [$nota->payment_term, $nota->due_date->toDateString(), $nota->institution_id, (float) $nota->total_amount]);
        $this->assertSame(0.0, (float) $nota->paid_amount);
    }

    public function test_C06_instansi_tagihan_bulanan_tanpa_jatuh_tempo_dan_periode_tanggal_potong(): void
    {
        $r = $this->instansi(['default_billing_scheme' => 'monthly_fixed_date', 'billing_day' => 25]);
        Carbon::setTestNow('2026-10-08 10:00:00');

        $this->layar($r)->assertSee('Masuk tagihan bulanan periode 26 Sep – 25 Okt 2026, diterbitkan 25 Okt')
            ->call('checkOut')->assertHasNoErrors();
        $nota = $r->fresh()->stayInvoice;
        $this->assertSame(['credit', null], [$nota->payment_term, $nota->due_date]);

        // Tanggal potong yang tidak ada di bulan itu memakai tanggal terakhir bulan
        $i = new Institution(['billing_day' => 31]);
        $p = $i->periodeTagihan(CarbonImmutable::parse('2027-02-10'));
        $this->assertSame(['2027-02-01', '2027-02-28'], [$p['mulai']->toDateString(), $p['akhir']->toDateString()]);
        $p = (new Institution(['billing_day' => 25]))->periodeTagihan(CarbonImmutable::parse('2026-10-26'));
        $this->assertSame(['2026-10-26', '2026-11-25', '2026-11-25'], [$p['mulai']->toDateString(), $p['akhir']->toDateString(), $p['terbit']->toDateString()]);
        $this->assertNull((new Institution)->periodeTagihan(now()));

        // Tanggal tagih belum diatur: tetap boleh check-out
        $lain = $this->instansiKedua(['default_billing_scheme' => 'monthly_fixed_date']);
        $this->layar($lain)->assertSee('Masuk tagihan bulanan (tanggal tagih instansi belum diatur)')->call('checkOut')->assertHasNoErrors();
        $this->assertSame('checked_out', $lain->fresh()->status);
    }

    /** Instansi lain di kamar 108 (untuk tes yang butuh dua reservasi instansi). */
    private function instansiKedua(array $data): Reservation
    {
        Carbon::setTestNow('2026-10-06 15:00:00');
        $i = Institution::create(['name' => 'Dinas Kesehatan Palopo', ...$data]);
        $pic = Customer::create(['full_name' => 'Rani Pasorong', 'phone' => '081455500007', 'institution_id' => $i->id]);
        $layanan = app(ReservasiService::class);
        $r = $layanan->ajukanInstansi($pic, $i, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-08'), 1, null, null);
        $layanan->setujui($r, $i, [['room_id' => $this->kamar('108')->id, 'price' => 300000]], null, 'satu_nota', null, $this->akun('manager'));
        $layanan->checkIn($r->fresh(), [], false, null, $this->akun('fo'), $r->fresh()->access_token);
        Carbon::setTestNow('2026-10-08 10:00:00');

        return $r->fresh();
    }

    public function test_C07_dua_nota_dan_tamu_bayar(): void
    {
        // dua_nota: kamar dan layanan masing-masing nota kredit ke instansi
        $r = $this->instansi(['default_billing_scheme' => 'due_after_checkout', 'due_days' => 7], 'dua_nota');
        $o = $this->makanan($r, '107', 2);
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->layar($r)->call('checkOut')->assertHasNoErrors();
        $nota = Invoice::where('reservation_id', $r->id)->orderBy('id')->get();
        $this->assertSame(['stay', 'service'], $nota->pluck('invoice_type')->all());
        $this->assertSame(['credit', 'credit'], $nota->pluck('payment_term')->all());
        $this->assertSame([$r->institution_id, $r->institution_id], $nota->pluck('institution_id')->all());
        $this->assertSame([600000.0, 70000.0], $nota->map(fn ($n) => (float) $n->total_amount)->all());
        $this->assertSame($nota[1]->id, $o->fresh()->invoice_id);

        // tamu_bayar: nota layanan atas nama pemesan, wajib lunas sebelum check-out
        $lain = $this->instansiKedua(['default_billing_scheme' => 'due_after_checkout', 'due_days' => 7]);
        $lain->update(['service_billing_scheme' => 'tamu_bayar']);
        Carbon::setTestNow('2026-10-07 09:00:00');
        $o2 = $this->makanan($lain, '108', 3);
        Carbon::setTestNow('2026-10-08 10:00:00');

        $layar = $this->layar($lain->fresh())->assertSee('Sisa tagihan Rp105.000 belum dibayar.');
        $layar->call('checkOut')->assertHasErrors('status');
        $layar->set('notaBayar', 'service')->set('jumlah', '')->set('metode', 'qris')->call('bayar')->assertHasNoErrors()
            ->call('checkOut')->assertHasNoErrors();
        $layanan = app(FolioService::class)->notaLayanan($lain->fresh());
        $this->assertSame(['immediate', null, 'paid', 105000.0], [$layanan->payment_term, $layanan->institution_id, $layanan->status, (float) $layanan->total_amount]);
        $this->assertSame($layanan->id, $o2->fresh()->invoice_id);
        $this->assertSame('credit', $lain->fresh()->stayInvoice->payment_term);
    }

    public function test_C08_check_out_sebagian_kamar_belum_menggabungkan_layanan(): void
    {
        $r = $this->langsung(['107', '108']);
        $o = $this->makanan($r, '107', 1);
        Carbon::setTestNow('2026-10-08 10:00:00');
        $b107 = $this->baris($r, '107');
        $b108 = $this->baris($r, '108');

        $this->layar($r)->set("kamar.{$b108->id}", false)->assertSee('Check-out sebagian')
            ->call('checkOut')->assertHasNoErrors();

        $r->refresh();
        $this->assertSame('checked_in', $r->status);
        $this->assertSame('checked_out', $b107->fresh()->status);
        $this->assertSame('checked_in', $b108->fresh()->status);
        $this->assertSame('vacant_dirty', $this->kamar('107')->status->value);
        $this->assertSame('occupied_clean', $this->kamar('108')->status->value);
        $this->assertNull($o->fresh()->invoice_id);
    }

    public function test_C09_pesanan_tagihan_kamar_ditolak_setelah_check_out(): void
    {
        $r = $this->perorangan();
        $baris = $r->rooms->first();
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->layar($r)->set('jumlah', '')->call('bayar')->call('checkOut')->assertHasNoErrors();

        // Kode kamar yang masih tersimpan di session tidak bisa dipakai lagi
        $this->withSession([KodeKamar::SESI => $r->id])->postJson(route('resto.pesan'), [
            'items' => [['id' => $this->nasi->id, 'qty' => 1]], 'serving_type' => 'room_service',
            'reservation_room_id' => $baris->id, 'tagih' => 'kamar',
        ])->assertStatus(422)->assertJsonPath('pesan', KodeKamar::TIDAK_BOLEH);

        $this->expectException(ValidationException::class);
        app(LaundryService::class)->terima($r->customer, $baris->fresh(), true, 2, null);
    }

    public function test_C10_ktp_yang_ditahan_wajib_dikembalikan(): void
    {
        $r = $this->perorangan(ktp: true);
        Carbon::setTestNow('2026-10-09 10:00:00');
        $layar = $this->layar($r)->assertSee('KTP sudah dikembalikan')->set('jumlah', '')->call('bayar');
        $layar->call('checkOut')->assertHasErrors('ktp');
        $this->assertSame('checked_in', $r->fresh()->status);

        $layar->set('ktpKembali', true)->call('checkOut')->assertHasNoErrors();
        $r->refresh();
        $this->assertFalse($r->id_card_held);
        $this->assertTrue(ReservationLog::where('reservation_id', $r->id)->where('event_type', 'id_card_returned')->where('note', 'KTP atas nama Hasan Basri dikembalikan')->exists());
    }

    public function test_C11_notifikasi_midtrans_pelunasan_nota(): void
    {
        $r = $this->perorangan();
        Carbon::setTestNow('2026-10-09 10:00:00');
        $layar = $this->layar($r)->call('mulaiQris')->assertHasNoErrors()->assertSee('Rp540.000');
        $qris = $layar->get('qris');
        $nota = $r->stayInvoice;
        $this->assertSame([540000, 'WT-INV'.str_replace('/', '', substr($nota->invoice_number, 4)).'-UJI0'], [$qris['jumlah'], $qris['order_id']]);

        // Order id yang tidak pernah dibuat untuk nota ini diabaikan
        $this->assertNull((new PembayaranMidtrans)->terapkan(['order_id' => $nota->awalanMidtrans().'-PALSU', 'transaction_status' => 'settlement', 'gross_amount' => '540000.00', 'payment_type' => 'qris']));

        (new PembayaranMidtrans)->terapkan(['order_id' => $qris['order_id'], 'transaction_status' => 'settlement', 'gross_amount' => '540000.00', 'payment_type' => 'qris', 'transaction_id' => 'trx-1']);
        $bayar = Payment::where('midtrans_order_id', $qris['order_id'])->sole();
        $this->assertSame(['settlement', 'success', 'online', 540000.0, null], [$bayar->payment_purpose, $bayar->status, $bayar->channel, (float) $bayar->amount, $bayar->note]);
        $this->assertSame(['paid', 1080000.0], [$nota->fresh()->status, (float) $nota->fresh()->paid_amount]);

        $layar->call('cekQris')->assertSet('qris', null)->call('checkOut')->assertHasNoErrors();
        $this->assertSame('checked_out', $r->fresh()->status);
    }

    public function test_C11b_qris_lama_dibayar_setelah_tunai_dicatat_sebagai_lebih_bayar(): void
    {
        // Sisa Rp500.000: kamar 1 malam Rp360.000 + makanan 4 porsi Rp140.000
        $r = $this->langsung(['107'], '2026-10-07');
        $this->makanan($r, '107', 4);
        Carbon::setTestNow('2026-10-07 10:00:00');

        $layar = $this->layar($r)->call('mulaiQris');
        $qris = $layar->get('qris');
        $this->assertSame(500000, $qris['jumlah']);
        $layar->set('jumlah', '200000')->set('metode', 'cash')->call('bayar')->assertHasNoErrors();
        $nota = $r->stayInvoice;
        $this->assertSame(300000.0, app(FolioService::class)->rincian($r->fresh())['sisa']);

        // QR lama terlanjur dibayar: uang yang masuk tetap dicatat utuh
        (new PembayaranMidtrans)->terapkan(['order_id' => $qris['order_id'], 'transaction_status' => 'settlement', 'gross_amount' => '500000.00', 'payment_type' => 'qris']);
        $bayar = Payment::where('midtrans_order_id', $qris['order_id'])->sole();
        $this->assertSame([500000.0, PembayaranMidtrans::CATATAN_PERLU_REFUND], [(float) $bayar->amount, $bayar->note]);
        $this->assertSame(700000.0, (float) $nota->fresh()->paid_amount);
        $f = app(FolioService::class)->rincian($r->fresh());
        $this->assertSame([200000.0, 0.0], [$f['lebih'], $f['sisa']]);
        // Kelebihannya langsung masuk antrean pengembalian dana
        $refund = Payment::where('invoice_id', $nota->id)->where('transaction_type', 'refund')->sole();
        $this->assertSame(['pending', 200000.0, $bayar->payment_code], [$refund->status, (float) $refund->amount, $refund->kodeAsal()]);

        $this->layar($r)->assertSee('Kembalikan Rp200.000 ke tamu')->call('checkOut')->assertHasNoErrors();
        $this->assertSame('paid', $nota->fresh()->status);
        // Check-out yang menyisakan lebih bayar tidak membuat kewajiban dobel untuk nota yang sama
        $this->assertSame(200000.0, (float) Payment::where('invoice_id', $nota->id)->where('transaction_type', 'refund')->sole()->amount);
    }

    public function test_C12_selain_front_office_tidak_bisa_check_out(): void
    {
        $r = $this->perorangan();
        Carbon::setTestNow('2026-10-09 10:00:00');

        Livewire::actingAs($this->akun('manager'))->test(CheckOut::class)->call('open', $r->id)->assertSee($r->reservation_code)
            ->call('checkOut')->assertForbidden();
        Livewire::actingAs($this->akun('manager'))->test(CheckOut::class)->call('open', $r->id)->set('jumlah', '')->call('bayar')->assertForbidden();
        $this->actingAs($this->akun('hk'))->get(route('staf.check-out'))->assertForbidden();
        $this->actingAs($this->akun('fnb'))->get(route('staf.check-out'))->assertForbidden();
        $this->assertSame('checked_in', $r->fresh()->status);
    }

    public function test_C13_pesanan_yang_sudah_masuk_nota_menginap_tidak_bisa_dibatalkan(): void
    {
        $r = $this->perorangan();
        $o = $this->makanan($r, '106', 2);
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->layar($r)->set('jumlah', '')->call('bayar')->call('checkOut')->assertHasNoErrors();
        $nota = $r->fresh()->stayInvoice;
        $o->refresh();

        Livewire::actingAs($this->akun('fnb'))->test(PapanPesanan::class)->call('open', $o->id)
            ->assertSee("Sudah masuk nota {$nota->invoice_number}, koreksi lewat menu Nota.")
            ->assertDontSee('wire:click="bukaBatal"', false);
        try {
            app(PesananMakananService::class)->batalkan($o, 'Salah input');
            $this->fail('Pesanan yang sudah bernota seharusnya ditolak dibatalkan.');
        } catch (ValidationException $e) {
            $this->assertSame("Sudah masuk nota {$nota->invoice_number}, koreksi lewat menu Nota.", $e->errors()['status'][0]);
        }
        $this->assertSame('pending', $o->fresh()->status);
        $this->assertSame(['paid', 1150000.0], [$nota->fresh()->status, (float) $nota->fresh()->total_amount]);
        $this->assertSame(2, $nota->items()->count());
    }

    public function test_C14_tagihan_sementara_di_daftar_reservasi_dan_halaman_tamu(): void
    {
        $r = $this->perorangan();
        $o = $this->makanan($r, '106', 2);

        $this->get($r->urlStatus())->assertOk()->assertSee('Tagihan sementara')
            ->assertSee('Makanan '.$o->order_code.' (2 item)')->assertSee('Rp1.150.000')->assertSee('Rp540.000');
        Livewire::actingAs($this->akun('fo'))->test(DaftarReservasi::class)->call('open', $r->id)
            ->assertSee('Tagihan sementara')->assertSee('Rp610.000');
    }
}
