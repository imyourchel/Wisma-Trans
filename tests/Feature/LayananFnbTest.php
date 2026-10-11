<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Livewire\Staf\Layanan\Kasir;
use App\Livewire\Staf\Layanan\KetersediaanMenu;
use App\Livewire\Staf\Layanan\PapanPesanan;
use App\Livewire\Staf\Layanan\RiwayatPesanan;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ReservationRoom;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Layanan\PesananMakananService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Layanan F&B sisi staf: Papan Pesanan, Kasir, Riwayat Pesanan, Ketersediaan Menu.
 * Data contoh 6 Okt 2026 10.00 WITA; Budi Santoso (RSV-0012) menginap di kamar 205.
 */
class LayananFnbTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const HALAMAN = ['staf.fnb.kasir', 'staf.fnb.papan', 'staf.fnb.riwayat', 'staf.fnb.ketersediaan'];

    private Service $nasi;

    private Service $teh;

    private Service $ikan;

    private ReservationRoom $kamar205;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        config(['services.fonnte.token' => 'uji-fonnte', 'services.midtrans.server_key' => 'uji-server-key', 'services.midtrans.client_key' => 'uji-client-key']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        // Tagihan online tidak meminta ke Midtrans sungguhan
        $this->app->instance(PembayaranMidtrans::class, new class extends PembayaranMidtrans
        {
            public function buatTagihan(BayarOnline $order, string $metode): array
            {
                $tagihan = ['metode' => $metode, 'order_id' => 'WT-UJI', 'jumlah' => $order->jumlahBayar(), 'nomor' => '8277000012345678', 'kode_perusahaan' => null, 'qr' => null];
                Cache::put(PembayaranMidtrans::kunci($order, 'tagihan'), $tagihan);

                return $tagihan;
            }

            public function sinkronkan(BayarOnline $order): void {}

            /** Kode pesanan yang tagihannya diakhiri; tidak menghubungi Midtrans sungguhan */
            public array $diakhiri = [];

            public function akhiriTagihan(BayarOnline $x): void
            {
                $this->diakhiri[] = $x->keteranganBayar();
                Cache::forget(PembayaranMidtrans::kunci($x, 'tagihan'));
            }
        });
        DB::table('settings')->insert(['setting_key' => 'fnb_max_wait_minutes', 'value' => '20', 'type' => 'integer']);

        $this->nasi = $this->menu('F-NG', 'Nasi Goreng', 35000);
        $this->teh = $this->menu('F-TM', 'Teh Manis', 10000);
        $this->ikan = $this->menu('F-IB', 'Ikan Bakar', null);
        $this->kamar205 = $this->menginap('RSV-0012', '205', 'Budi Santoso', 'checked_in');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function menu(string $kode, string $nama, ?int $harga): Service
    {
        return Service::create([
            'code' => $kode, 'name' => $nama, 'category' => 'fnb', 'menu_group' => 'Makanan Utama',
            'department' => 'fnb', 'unit' => 'porsi', 'price' => $harga, 'is_active' => true,
        ]);
    }

    private function menginap(string $kode, string $kamar, string $nama, string $status): ReservationRoom
    {
        $tamu = Customer::create(['full_name' => $nama, 'phone' => '0813'.random_int(10000000, 99999999)]);
        $rsv = DB::table('reservations')->insertGetId([
            'reservation_code' => $kode, 'customer_id' => $tamu->id, 'booking_channel' => 'walk_in', 'status' => $status,
        ]);

        return ReservationRoom::create([
            'reservation_id' => $rsv, 'room_type_id' => $this->kamar($kamar)->room_type_id, 'room_id' => $this->kamar($kamar)->id,
            'check_in_date' => '2026-10-05', 'check_out_date' => '2026-10-08', 'price_per_night' => 460000, 'status' => $status,
        ]);
    }

    /** Pesanan online dari halaman Resto (belum dibayar). */
    private function pesanOnline(?array $items = null): ServiceOrder
    {
        $this->postJson(route('resto.pesan'), [
            'items' => $items ?? [['id' => $this->nasi->id, 'qty' => 1, 'note' => 'tidak pedas']],
            'name' => 'Dian Permata', 'phone' => '081234567890', 'serving_type' => 'take_away', 'metode' => 'bca',
        ])->assertOk();

        return ServiceOrder::latest('id')->firstOrFail();
    }

    private function lunasMidtrans(ServiceOrder $o): void
    {
        (new PembayaranMidtrans)->terapkan(['order_id' => $o->awalanMidtrans().'-UJI1', 'transaction_status' => 'settlement',
            'gross_amount' => number_format($o->jumlahBayar(), 2, '.', ''), 'payment_type' => 'bank_transfer']);
    }

    /** Pesanan kasir: tamu kamar 205 ditagihkan ke kamar, atau tamu luar bayar tunai. */
    private function kasir(bool $tamuLuar = false, ?Service $menu = null, int $qty = 1): ServiceOrder
    {
        return app(PesananMakananService::class)->buatDariKasir(
            [['id' => ($menu ?? $this->nasi)->id, 'qty' => $qty]],
            $tamuLuar ? null : $this->kamar205->load('reservation.customer'),
            $tamuLuar ? Customer::firstOrCreate(['phone' => '082199887766'], ['full_name' => 'Yudi Kurniawan']) : null,
            $tamuLuar ? 'take_away' : 'room_service', ! $tamuLuar, $tamuLuar ? 'cash' : null, null, $this->akun('fnb'));
    }

    private function papan(string $akun = 'fnb')
    {
        return Livewire::actingAs($this->akun($akun))->test(PapanPesanan::class);
    }

    /* ---------- 1. Hak akses ---------- */

    public function test_01_hak_akses_per_peran(): void
    {
        foreach (self::HALAMAN as $r) {
            $this->actingAs($this->akun('fnb'))->get(route($r))->assertOk();
            foreach (['admin', 'hk'] as $akun) {
                $this->actingAs($this->akun($akun))->get(route($r))->assertForbidden();
            }
        }
        foreach (['staf.fnb.papan', 'staf.fnb.riwayat', 'staf.fnb.ketersediaan'] as $r) {
            $this->actingAs($this->akun('manager'))->get(route($r))->assertOk();
            $this->actingAs($this->akun('fo'))->get(route($r))->assertForbidden();
        }
        $this->actingAs($this->akun('manager'))->get(route('staf.fnb.kasir'))->assertForbidden();
        $this->actingAs($this->akun('fo'))->get(route('staf.fnb.kasir'))->assertOk();

        // Manager hanya memantau: tombol tidak tampil dan pemanggilan langsung ditolak
        $o = $this->kasir();
        $this->papan('manager')->assertSee($o->order_code)->assertDontSee('Mulai masak')->assertDontSee('Tersedia lagi')
            ->call('mulai', $o->id)->assertForbidden();
        $this->papan('manager')->call('open', $o->id)->call('bukaBatal')->assertForbidden();
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanMenu::class)->assertDontSee('Tandai habis')
            ->call('tandaiHabis', $this->nasi->id)->assertForbidden();
        $this->assertSame('pending', $o->fresh()->status);
        $this->assertFalse($this->nasi->fresh()->habis());
        $this->papan()->call('mulai', $o->id)->assertHasNoErrors();
        $this->assertSame('in_progress', $o->fresh()->status);

        // Menu sidebar dan kartu dasbor
        $this->actingAs($this->akun('fnb'))->get(route('staf.dashboard'))
            ->assertSee(route('staf.fnb.papan'))->assertSee(route('staf.fnb.kasir'))
            ->assertSeeInOrder(['Pesanan aktif', '1', 'Terlambat', '0', 'Menunggu pembayaran', '0', 'Menu habis', '0']);
        $this->actingAs($this->akun('fo'))->get(route('staf.dashboard'))->assertSee(route('staf.fnb.kasir'))->assertDontSee(route('staf.fnb.papan'));
    }

    /* ---------- 2. Pesanan online masuk Antre setelah lunas ---------- */

    public function test_02_pesanan_online_masuk_antre_setelah_lunas(): void
    {
        $o = $this->pesanOnline();
        $papan = $this->papan();
        $this->assertCount(0, $papan->viewData('kolom')['pending']);
        $this->assertSame([$o->id], $papan->viewData('menungguBayar')->pluck('id')->all());
        $papan->call('mulai', $o->id)->assertHasErrors('status');

        $this->lunasMidtrans($o);
        $papan = $this->papan();
        $this->assertSame([$o->id], $papan->viewData('kolom')['pending']->pluck('id')->all());
        $this->assertCount(0, $papan->viewData('menungguBayar'));
        $papan->assertSee('tidak pedas')->assertSee('Tamu luar · Ambil di tempat')->assertSee('Lunas');
    }

    /* ---------- 3. Alur dapur ---------- */

    public function test_03_alur_dapur_dan_whatsapp_saat_siap(): void
    {
        $o = $this->kasir(tamuLuar: true);
        $papan = $this->papan();

        $papan->call('serahkan', $o->id)->assertHasErrors('status');
        $papan->call('siap', $o->id)->assertHasErrors('status');
        $this->assertSame('pending', $o->fresh()->status);

        $papan->call('mulai', $o->id)->assertHasNoErrors();
        $this->assertSame('in_progress', $o->fresh()->status);
        $papan->call('siap', $o->id)->assertHasNoErrors();
        $this->assertSame('ready', $o->fresh()->status);
        Http::assertSent(fn (Request $q) => str_contains((string) $q['message'], "pesanan {$o->order_code} sudah siap"));

        // Detail tamu luar berstatus Siap: tautan kirim ulang WhatsApp
        $papan->call('open', $o->id)->assertSee('Kirim ulang WhatsApp');
        $papan->call('serahkan', $o->id)->assertHasNoErrors();
        $this->assertSame('delivered', $o->fresh()->status);
        $this->assertSame(1, $this->papan()->viewData('selesaiHariIni'));
    }

    /* ---------- 4. Terlambat ---------- */

    public function test_04_tanda_terlambat_setelah_batas_tunggu(): void
    {
        $o = $this->kasir();

        $this->travelTo(Carbon::parse('2026-10-06 10:20:00'));
        $this->assertSame(0, $this->papan()->viewData('terlambat'));

        $this->travelTo(Carbon::parse('2026-10-06 10:21:00'));
        $papan = $this->papan();
        $this->assertSame(1, $papan->viewData('terlambat'));
        $papan->assertSeeInOrder([$o->order_code, 'Terlambat', '10.00 · 21 menit']);

        // Sudah siap: tidak lagi terlambat
        $papan->call('mulai', $o->id)->call('siap', $o->id);
        $this->assertSame(0, $this->papan()->viewData('terlambat'));
    }

    /* ---------- 5. Pembatalan ---------- */

    public function test_05_pembatalan(): void
    {
        // Belum lunas: berhasil, nota ikut dibatalkan; alasan wajib
        $online = $this->pesanOnline();
        $this->papan()->call('open', $online->id)->call('bukaBatal')->set('alasan', '  ')->call('batalkan')
            ->assertHasErrors(['alasan' => 'required'])
            ->set('alasan', str_repeat('a', 151))->call('batalkan')->assertHasErrors(['alasan' => 'max'])
            ->set('alasan', 'Tamu tidak jadi memesan')->call('batalkan')->assertHasNoErrors();
        $this->assertSame(['cancelled', 'cancelled'], [$online->fresh()->status, $online->fresh()->invoice->status]);
        $this->assertStringContainsString('Dibatalkan: Tamu tidak jadi memesan', $online->fresh()->note);

        // Sudah lunas: boleh dibatalkan, seluruh yang terbayar masuk antrean pengembalian dana
        $lunas = $this->kasir(tamuLuar: true);
        $this->papan()->call('open', $lunas->id)->assertSee('>Batalkan<', false)
            ->call('bukaBatal')->set('alasan', 'Salah input')->call('batalkan')->assertHasNoErrors();
        $lunas->refresh();
        $this->assertSame(['cancelled', 'cancelled'], [$lunas->status, $lunas->invoice->status]);
        $refund = Payment::where('invoice_id', $lunas->invoice_id)->where('transaction_type', 'refund')->sole();
        $this->assertSame(['pending', (float) $lunas->invoice->paid_amount], [$refund->status, (float) $refund->amount]);
        $this->assertStringContainsString('dibatalkan: Salah input', $refund->note);
    }

    /* ---------- 6. Kasir ---------- */

    public function test_06_kasir_tamu_menginap_dan_tamu_luar(): void
    {
        // Tamu menginap, tagihan kamar: tanpa nota, langsung masuk dapur
        $kasir = Livewire::actingAs($this->akun('fnb'))->test(Kasir::class);
        $this->assertSame([$this->kamar205->id => '205 · Budi Santoso (RSV-0012)'], $kasir->viewData('kamarTerisi')->all());
        $kasir->call('tambah', $this->nasi->id)->call('tambah', $this->nasi->id)
            ->set('keranjang.'.$this->nasi->id.'.catatan', 'tidak pedas')
            ->set('kamar', (string) $this->kamar205->id)->set('penyajian', 'room_service')
            ->call('simpan')->assertHasNoErrors()->assertDispatched('toast', teks: 'Pesanan ORD/2026/10/0001 masuk dapur.')
            ->assertSet('keranjang', []);
        $o = ServiceOrder::with('items')->latest('id')->first();
        $this->assertSame(['charge_to_room', null, 'in_house', 'room_service'], [$o->payment_mode, $o->invoice_id, $o->order_type, $o->serving_type]);
        $this->assertSame([2.0, 'tidak pedas'], [(float) $o->items->first()->quantity, $o->items->first()->note]);
        $this->assertTrue(PesananMakananService::diDapur()->whereKey($o->id)->exists());

        // Tamu luar bayar tunai oleh FO: nota lunas, pembayaran atas nama kasir, tautan status untuk WhatsApp
        $fo = $this->akun('fo');
        Livewire::actingAs($fo)->test(Kasir::class)
            ->set('jenis', 'luar')->set('nama', 'Yudi Kurniawan')->set('hp', '082199887766')
            ->call('tambah', $this->teh->id)->set('metode', 'cash')
            ->call('simpan')->assertHasNoErrors()->assertSet('terakhir.kode', 'ORD/2026/10/0002')->assertSee('Kirim lewat WhatsApp');
        $o = ServiceOrder::with('invoice.payments')->latest('id')->first();
        $this->assertSame(['pay_now', 'paid', 'outside_guest'], [$o->payment_mode, $o->invoice->status, $o->order_type]);
        $this->assertDatabaseHas('payments', ['invoice_id' => $o->invoice_id, 'method' => 'cash', 'status' => 'success', 'received_by' => $fo->id, 'amount' => 10000]);
        $this->assertTrue(PesananMakananService::diDapur()->whereKey($o->id)->exists());
    }

    public function test_06b_kasir_menolak_isian_yang_tidak_sah(): void
    {
        $kasir = fn () => Livewire::actingAs($this->akun('fnb'))->test(Kasir::class);

        // Antar ke kamar untuk tamu luar
        $t = $kasir()->set('jenis', 'luar')->set('nama', 'Yudi Kurniawan')->set('hp', '082199887766')
            ->call('tambah', $this->teh->id)->set('penyajian', 'room_service')->call('simpan');
        $this->assertSame('Antar ke kamar hanya untuk tamu menginap.', $t->errors()->first('penyajian'));

        // Kamar yang sudah check-out
        $keluar = $this->menginap('RSV-0009', '206', 'Hasan Basri', 'checked_out');
        $t = $kasir()->call('tambah', $this->teh->id)->set('kamar', (string) $keluar->id)->call('simpan');
        $this->assertSame('Kamar ini tidak sedang ditempati.', $t->errors()->first('kamar'));

        // Menu harga pasar: tanpa harga ditolak, dengan harga diterima
        $t = $kasir()->call('tambah', $this->ikan->id)->assertSee('Harga sesuai ukuran')->set('kamar', (string) $this->kamar205->id)->call('simpan');
        $this->assertSame('Isi harga Ikan Bakar sesuai ukuran.', $t->errors()->first('keranjang'));
        $t->set('keranjang.'.$this->ikan->id.'.harga', '85.000')->call('simpan')->assertHasNoErrors();
        $this->assertSame(85000.0, (float) ServiceOrder::latest('id')->first()->items->first()->unit_price);
        $this->assertSame(0, ServiceOrder::where('status', 'cancelled')->count());
        $this->assertSame(1, ServiceOrder::count());
    }

    /* ---------- 7. Menu habis setelah masuk keranjang ---------- */

    public function test_07_menu_habis_setelah_masuk_keranjang_ditolak_saat_simpan(): void
    {
        $sebelum = $this->kasir();
        $t = Livewire::actingAs($this->akun('fnb'))->test(Kasir::class)
            ->call('tambah', $this->nasi->id)->set('kamar', (string) $this->kamar205->id);

        app(PesananMakananService::class)->aturKetersediaan($this->nasi, true);
        $t->call('simpan');
        $this->assertSame('Nasi Goreng sedang tidak tersedia.', $t->errors()->first('keranjang'));
        $this->assertSame(1, ServiceOrder::count());
        // Menu habis di Kasir tampil redup dan tidak bisa ditambah
        Livewire::actingAs($this->akun('fnb'))->test(Kasir::class)->assertSee('Habis')
            ->call('tambah', $this->nasi->id)->assertHasErrors('keranjang')->assertSet('keranjang', []);

        // Pesanan yang masuk sebelum ditandai habis tetap diproses
        $this->papan()->call('mulai', $sebelum->id)->call('siap', $sebelum->id)->call('serahkan', $sebelum->id)->assertHasNoErrors();
        $this->assertSame('delivered', $sebelum->fresh()->status);
    }

    /* ---------- 8. Ketersediaan Menu ---------- */

    public function test_08_ketersediaan_menu(): void
    {
        $halaman = Livewire::actingAs($this->akun('fnb'))->test(KetersediaanMenu::class)
            ->call('tandaiHabis', $this->nasi->id)->assertDispatched('toast', teks: 'Nasi Goreng ditandai habis.');
        $this->assertSame([true, true], [$this->nasi->fresh()->is_sold_out, $this->nasi->fresh()->is_active]);
        $halaman->set('saring', 'habis')->assertSee('Nasi Goreng')->assertDontSee('Teh Manis');

        // Papan Pesanan: bagian "Menu sedang habis"
        $papan = $this->papan();
        $this->assertSame(['Nasi Goreng'], $papan->viewData('menuHabis')->pluck('name')->all());
        $papan->assertSee('Menu sedang habis (1)');

        // Halaman Resto tamu: redup dan tidak bisa dipesan
        $this->get(route('menu'))->assertOk()->assertSee('Sedang tidak tersedia');
        $this->postJson(route('resto.pesan'), [
            'items' => [['id' => $this->nasi->id, 'qty' => 1]], 'name' => 'Dian Permata', 'phone' => '081234567890',
            'serving_type' => 'take_away', 'metode' => 'bca',
        ])->assertUnprocessable()->assertJsonPath('hapus', [$this->nasi->id]);

        // Tersedia lagi dari Papan Pesanan
        $papan->call('tersediaLagi', $this->nasi->id)->assertDispatched('toast', teks: 'Nasi Goreng tersedia lagi.');
        $this->assertSame([false, true], [$this->nasi->fresh()->is_sold_out, $this->nasi->fresh()->is_active]);

        // Menu nonaktif tidak tampil dan tidak bisa diubah ketersediaannya
        $this->teh->update(['is_active' => false]);
        Livewire::actingAs($this->akun('fnb'))->test(KetersediaanMenu::class)->assertDontSee('Teh Manis')
            ->call('tandaiHabis', $this->teh->id)->assertHasErrors('menu');
        $this->assertFalse($this->teh->fresh()->is_sold_out);
    }

    /* ---------- 9. Riwayat ---------- */

    public function test_09_riwayat_penyaring_dan_ringkasan(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 19:00:00'));
        $kemarin = $this->kasir(tamuLuar: true, menu: $this->teh, qty: 3);
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

        $kamar = $this->kasir(qty: 2);                          // tagihan kamar Rp70.000
        $luar = $this->kasir(tamuLuar: true, menu: $this->teh); // lunas Rp10.000
        $belum = $this->pesanOnline();                           // belum dibayar Rp35.000
        $batal = $this->pesanOnline([['id' => $this->teh->id, 'qty' => 4]]);
        app(PesananMakananService::class)->batalkan($batal, 'Tamu tidak jadi');

        $r = Livewire::actingAs($this->akun('manager'))->test(RiwayatPesanan::class)->assertSet('tanggal', '2026-10-06');
        $this->assertSame(4, $r->viewData('daftar')->total());
        $ringkasan = $r->viewData('ringkasan');
        $this->assertSame([4, 10000.0, 70000.0, 1], [$ringkasan['jumlah'], $ringkasan['lunas'], $ringkasan['tagihanKamar'], $ringkasan['batal']]);
        // Terlaris tanpa pesanan batal: Nasi Goreng 3 porsi (2 + 1), Teh Manis 1
        $this->assertSame([['Nasi Goreng', 3], ['Teh Manis', 1]], $ringkasan['terlaris']->map(fn ($t) => [$t->name, (int) $t->porsi])->all());
        $r->assertSee('Nasi Goreng ×2')->assertSee('Rp70.000');

        $ids = fn ($t) => collect($t->viewData('daftar')->items())->pluck('id')->sort()->values()->all();
        $this->assertSame([$luar->id], $ids($r->set('bayar', 'lunas')));
        $this->assertSame([$kamar->id], $ids($r->set('bayar', 'tagihan-kamar')));
        $this->assertSame([$belum->id], $ids($r->set('bayar', 'belum-dibayar')));
        $this->assertSame([$batal->id], $ids($r->set('bayar', 'dibatalkan')));
        $this->assertSame([$batal->id], $ids($r->set('bayar', '')->set('status', 'cancelled')));
        $this->assertSame([$kamar->id], $ids($r->set('status', '')->set('jenis', 'in_house')));
        $this->assertSame([$kamar->id], $ids($r->set('jenis', '')->set('cari', 'Budi')));

        $r->set('cari', '')->set('tanggal', '2026-10-05');
        $this->assertSame([$kemarin->id], $ids($r));
        $this->assertSame([1, 30000.0, 0.0], [$r->viewData('ringkasan')['jumlah'], $r->viewData('ringkasan')['lunas'], $r->viewData('ringkasan')['tagihanKamar']]);

        // Detail sama dengan Papan Pesanan, tanpa tombol dapur; laundry tidak ikut
        $r->call('open', $kemarin->id)->assertSee('Riwayat waktu')->assertSee('Rp30.000')->assertDontSee('Mulai masak');
    }

    private function midtrans(): PembayaranMidtrans
    {
        return app(PembayaranMidtrans::class);
    }

    /* ---------- 11–12. Celah pembayaran: tagihan online diakhiri, pembayaran terlambat ditandai ---------- */

    public function test_11_dibayar_tunai_lalu_notifikasi_midtrans_sukses_perlu_pengembalian_dana(): void
    {
        $o = $this->pesanOnline();
        $this->assertNotNull($this->midtrans()->tagihanAktif($o));

        Livewire::actingAs($this->akun('fo'))->test(Kasir::class)->call('mulaiBayar', $o->id)->set('metodeBayar', 'cash')->call('terimaBayar')->assertHasNoErrors();
        // bayar() mengakhiri VA/QRIS yang sudah dibuat
        $this->assertSame(["Pesanan {$o->order_code}"], $this->midtrans()->diakhiri);
        $this->assertNull($this->midtrans()->tagihanAktif($o));

        // Tamu ternyata sudah transfer sebelum tagihannya diakhiri
        $this->lunasMidtrans($o);
        $online = Payment::where('invoice_id', $o->invoice_id)->where('channel', 'online')->where('transaction_type', 'payment')->sole();
        $this->assertSame(['success', PembayaranMidtrans::CATATAN_PERLU_REFUND], [$online->status, $online->note]);
        $o->refresh();
        // Uang yang masuk selalu menambah paid_amount; kelebihannya menjadi refund pending
        $this->assertSame(['pending', 'paid', 70000.0], [$o->status, $o->invoice->status, (float) $o->invoice->paid_amount]);
        $refund = Payment::where('invoice_id', $o->invoice_id)->where('transaction_type', 'refund')->sole();
        $this->assertSame(['pending', 35000.0, $online->payment_code], [$refund->status, (float) $refund->amount, $refund->kodeAsal()]);
        $this->assertSame(1, PembayaranMidtrans::jumlahPerluRefund());

        // Notifikasi yang sama datang lagi: tidak dicatat dua kali
        $this->lunasMidtrans($o);
        $this->assertSame(1, PembayaranMidtrans::jumlahPerluRefund());
        $this->assertSame(35000.0, (float) $refund->fresh()->amount);

        foreach (['fnb', 'manager'] as $akun) {
            $this->actingAs($this->akun($akun))->get(route('staf.dashboard'))->assertSeeInOrder(['Perlu pengembalian dana', '1']);
        }
        $this->papan()->assertSee('Perlu pengembalian dana: 1 pembayaran');
    }

    public function test_12_dibatalkan_lalu_notifikasi_midtrans_sukses_tetap_batal(): void
    {
        $o = $this->pesanOnline();
        $this->actingAs($this->akun('fnb'))->get(route('staf.dashboard'))->assertDontSee('Perlu pengembalian dana');
        $this->papan()->assertDontSee('Perlu pengembalian dana');

        $this->papan()->call('open', $o->id)->call('bukaBatal')->set('alasan', 'Tamu tidak jadi')->call('batalkan')->assertHasNoErrors();
        // batalkan() mengakhiri VA/QRIS yang sudah dibuat
        $this->assertSame(["Pesanan {$o->order_code}"], $this->midtrans()->diakhiri);

        $this->lunasMidtrans($o);
        $o->refresh();
        $this->assertSame(['cancelled', 'cancelled'], [$o->status, $o->invoice->status]);
        $this->assertSame(PembayaranMidtrans::CATATAN_PERLU_REFUND, Payment::where('invoice_id', $o->invoice_id)->where('transaction_type', 'payment')->sole()->note);
        $this->assertSame([35000.0, 'pending'], [(float) $o->invoice->paid_amount, Payment::where('invoice_id', $o->invoice_id)->where('transaction_type', 'refund')->sole()->status]);
        $this->assertFalse(PesananMakananService::diDapur()->whereKey($o->id)->exists());
        $this->assertCount(0, $this->papan()->viewData('kolom')['pending']);
        $this->papan()->assertSee('Perlu pengembalian dana: 1 pembayaran');
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertSeeInOrder(['Perlu pengembalian dana', '1']);
    }

    /* ---------- 10. Terima pembayaran di Kasir ---------- */

    public function test_10_terima_pembayaran_pesanan_online_di_kasir(): void
    {
        $o = $this->pesanOnline();
        $fo = $this->akun('fo');
        $kasir = Livewire::actingAs($fo)->test(Kasir::class)->assertSee('Belum dibayar (1)')->assertSee($o->order_code);
        $this->assertFalse(PesananMakananService::diDapur()->whereKey($o->id)->exists());

        $kasir->call('mulaiBayar', $o->id)->set('metodeBayar', 'qris')->call('terimaBayar')->assertHasNoErrors()
            ->assertDispatched('toast', teks: "Pembayaran {$o->order_code} diterima. Pesanan masuk dapur.")
            ->assertSee('Belum dibayar (0)');
        $this->assertSame('paid', $o->fresh()->invoice->status);
        $this->assertDatabaseHas('payments', ['invoice_id' => $o->invoice_id, 'method' => 'qris', 'received_by' => $fo->id, 'amount' => 35000]);
        $this->assertSame([$o->id], $this->papan()->viewData('kolom')['pending']->pluck('id')->all());
    }
}
