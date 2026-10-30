<?php

namespace Tests\Feature;

use App\Livewire\Staf\Layanan\Kasir;
use App\Livewire\Staf\Layanan\KetersediaanMenu;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Livewire\Tamu\PesanKamar;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\Layanan\PesananMakananService;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\FolioService;
use App\Services\Reservasi\ReservasiService;
use App\Support\Pelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Tambahan pemesanan kamar: Sarapan tambahan (SRP01) disembunyikan dari menu kafe; Extra bed (EXB01) dan Sarapan tambahan
 * dipilih per kamar saat memesan, ikut total dan uang muka, dan ditagihkan di nota menginap.
 */
class TambahanKamarTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private Service $extraBed;

    private Service $sarapan;

    private Service $teh;

    /** Pesanan Deluxe 10–13 Okt 2026 (3 malam) untuk 2 dewasa. */
    private const CARI = ['masuk' => '2026-10-10', 'keluar' => '2026-10-13', 'tamu' => '2-0'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        Notification::fake();
        config(['services.midtrans.server_key' => null, 'services.midtrans.client_key' => null, 'services.fonnte.token' => null]);

        $this->extraBed = Service::create(['code' => 'EXB01', 'name' => 'Extra Bed', 'description' => 'Kasur tambahan, maksimal 1 per kamar. Belum termasuk sarapan.',
            'category' => 'amenity', 'department' => 'housekeeping', 'unit' => 'malam', 'price' => 100000, 'is_active' => true]);
        $this->sarapan = Service::create(['code' => 'SRP01', 'name' => 'Sarapan Tambahan', 'description' => 'Satu kali sarapan untuk 1 orang, disajikan setiap pagi selama menginap.',
            'category' => 'fnb', 'menu_group' => 'Paket', 'department' => 'fnb', 'unit' => 'porsi', 'price' => 80000, 'is_active' => true]);
        $this->teh = Service::create(['code' => 'TEH01', 'name' => 'Teh Manis', 'category' => 'fnb', 'menu_group' => 'Teh',
            'department' => 'fnb', 'unit' => 'gelas', 'price' => 10000, 'is_active' => true]);
    }

    private function formulir(array $cari = self::CARI)
    {
        return Livewire::withQueryParams($cari)->test(PesanKamar::class, ['kode' => 'dlx']);
    }

    public function test_D1_sarapan_tambahan_tidak_tampil_dan_ditolak_di_resto_kasir_dan_ketersediaan_menu(): void
    {
        $this->get(route('menu'))->assertOk()->assertSee('Teh Manis')->assertDontSee('Sarapan Tambahan');

        $kasir = Livewire::actingAs($this->akun('fnb'))->test(Kasir::class)->assertSee('Teh Manis')->assertDontSee('Sarapan Tambahan');
        $this->assertNotContains($this->sarapan->id, $kasir->viewData('grup')->flatten()->pluck('id')->all());

        Livewire::actingAs($this->akun('fnb'))->test(KetersediaanMenu::class)->assertSee('Teh Manis')->assertDontSee('Sarapan Tambahan')
            ->call('tandaiHabis', $this->sarapan->id)->assertHasErrors('menu');
        $this->assertFalse($this->sarapan->fresh()->is_sold_out);

        // Pesanan Resto berisi SRP01 ditolak di server (sebelum tagihan Midtrans dibuat)
        config(['services.midtrans.server_key' => 'uji-server-key', 'services.midtrans.client_key' => 'uji-client-key']);
        $this->postJson(route('resto.pesan'), [
            'items' => [['id' => $this->sarapan->id, 'qty' => 1]], 'name' => 'Dian Permata', 'phone' => '081234567890',
            'serving_type' => 'take_away', 'metode' => 'bca',
        ])->assertUnprocessable()->assertJsonPath('hapus', [$this->sarapan->id]);

        // Kasir: tidak bisa ditambah ke keranjang, dan layanan pesanan menolaknya
        try {
            app(PesananMakananService::class)->buatDariKasir([['id' => $this->sarapan->id, 'qty' => 1]], null, null, 'dine_in', false, 'cash', null, $this->akun('fnb'));
            $this->fail('Sarapan tambahan seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('Sarapan Tambahan sedang tidak tersedia.', $e->errors()['keranjang'][0]);
        }
        $this->assertSame(0, ServiceOrder::count());
        $this->assertSame(['TEH01'], Service::menuKafe()->pluck('code')->all());
    }

    public function test_D2_sarapan_tambahan_tetap_dikelola_admin(): void
    {
        $this->actingAs($this->akun('admin'))->get(route('staf.layanan.index'))->assertOk()
            ->assertSee('Sarapan Tambahan')->assertSee('SRP01')->assertSee('Extra Bed');
    }

    public function test_D3_extra_bed_dan_sarapan_tambahan_ikut_total_uang_muka_dan_nota(): void
    {
        // Kamar 460.000 × 3 = 1.380.000; Extra bed 100.000 × 3 = 300.000; Sarapan 2 × 80.000 × 3 = 480.000
        $f = $this->formulir()->assertSee('Tambahan')->assertSee('Extra bed')->assertSee('+Rp100.000/malam')
            ->assertSee('Kasur tambahan, maksimal 1 per kamar. Belum termasuk sarapan.')->assertDontSee('termasuk sarapan 1 orang')
            ->assertDontSee('data-pilihan-tambahan="sarapan"', false)
            ->call('aturTambahan', 1, 'extra', 1)
            // Sarapan tambahan muncul tidak dicentang, dengan petunjuk netral
            ->assertSee('data-pilihan-tambahan="sarapan"', false)->assertSee('Sarapan tambahan')->assertSee('+Rp80.000/orang')
            ->assertSee('Satu kali sarapan untuk 1 orang, disajikan setiap pagi selama menginap.')
            ->assertSet('tambahan.1', ['extra' => 1, 'sarapan' => 0])
            ->assertSee('Tamu extra bed belum mendapat sarapan. Centang sarapan tambahan bila diperlukan.')->assertSee('data-petunjuk-sarapan', false)
            ->call('aturTambahan', 1, 'sarapan', 1)->assertDontSee('data-petunjuk-sarapan', false)->call('aturTambahan', 1, 'sarapan', 2)
            ->assertSet('tambahan.1', ['extra' => 1, 'sarapan' => 2])->assertSee('maks 3 orang')
            ->assertSee('Extra bed × 1 · 3 malam')->assertSee('Rp300.000')
            ->assertSee('Sarapan tambahan · 2 orang × 3 malam')->assertSee('Rp480.000')
            ->assertSee('Rp2.160.000')->assertSee('Bayar Rp1.080.000');

        // Batas porsi: kapasitas Deluxe 2 + 1 extra bed = 3
        $f->call('aturTambahan', 1, 'sarapan', 9)->assertSet('tambahan.1.sarapan', 3)->call('aturTambahan', 1, 'sarapan', 2);

        $f->set('nama', 'Rizky Pratama')->set('hp', '0812 9911 0022')->set('metode', 'bca')->call('pesan')->assertHasNoErrors();
        $r = Reservation::sole();
        $nota = $r->stayInvoice;
        $this->assertSame(1080000.0, (float) $r->dp_amount_required);
        $this->assertSame(2160000.0, (float) $nota->total_amount);
        $this->assertSame(
            [['room', 3.0, 1380000.0], ['amenity', 3.0, 300000.0], ['fnb', 6.0, 480000.0]],
            $nota->items()->orderBy('id')->get()->map(fn ($i) => [$i->item_type, (float) $i->quantity, (float) $i->subtotal])->all()
        );
        $o = ServiceOrder::tambahanKamar()->sole();
        $this->assertSame([$nota->id, '2026-10-10', '2026-10-13'], [$o->invoice_id, $o->service_start_date->toDateString(), $o->service_end_date->toDateString()]);
        // Dua baris nota terpisah dengan teks yang sama seperti ringkasan pemesanan
        $this->assertStringStartsWith('Extra bed × 1 · 3 malam, Kamar 111 (TMB/2026/10/', $nota->items()->where('item_type', 'amenity')->value('description'));
        $this->assertStringStartsWith('Sarapan tambahan · 2 orang × 3 malam, Kamar 111 (TMB/2026/10/', $nota->items()->where('item_type', 'fnb')->value('description'));

        // Bukan pesanan dapur: tidak masuk antrean, riwayat, dan daftar pesanan biasa
        $this->assertSame(0, ServiceOrder::count());
        $this->assertSame(0, PesananMakananService::diDapur()->count());

        // Ditampilkan di WhatsApp konfirmasi dan halaman reservasi tamu
        $this->assertStringContainsString("Kamar 111 · Deluxe Room · Twin Double\n10 Okt 2026 – 13 Okt 2026\nTambahan: Kamar 111: Extra bed × 1 · Sarapan tambahan 2 orang\nTotal Rp2.160.000", app(NotifikasiReservasi::class)->teksDibuat($r));
        $this->get($r->urlStatus())->assertOk()->assertSee('+ Extra bed × 1 · Sarapan tambahan 2 orang')->assertDontSee('porsi');

        // Ubah tanggal menjadi 2 malam: tambahan ikut dihitung ulang (920.000 + 200.000 + 320.000)
        $r->update(['status' => 'confirmed']);
        app(ReservasiService::class)->ubahTanggal($r->fresh(), CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-12'), null);
        $this->assertSame(1440000.0, (float) $nota->fresh()->total_amount);
        $this->assertSame([2.0, 4.0], $nota->items()->whereIn('item_type', ['amenity', 'fnb'])->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertSame('2026-10-12', $o->fresh()->service_end_date->toDateString());

        // Batal: tambahan ikut batal
        app(ReservasiService::class)->batalkan($r->fresh(), 'Uji', null);
        $this->assertSame('cancelled', $o->fresh()->status);
    }

    public function test_D4_sarapan_tambahan_tanpa_extra_bed_ditolak(): void
    {
        // Formulir: sarapan tidak bisa dicentang tanpa extra bed
        $this->formulir()->call('aturTambahan', 1, 'sarapan', 2)->assertSet('tambahan.1', ['extra' => 0, 'sarapan' => 0])
            // Isian yang diubah langsung tetap diperiksa di server
            ->set('tambahan', [1 => ['extra' => 0, 'sarapan' => 2]])
            ->set('nama', 'Rizky Pratama')->set('hp', '081299110022')->set('metode', 'bca')->call('pesan')
            ->assertHasErrors(['tambahan' => 'Sarapan tambahan hanya bisa dipilih bersama extra bed.']);
        $this->assertSame(0, Reservation::count());

        $layanan = app(ReservasiService::class);
        $pemesan = Pelanggan::dariIsian(['name' => 'Rizky Pratama', 'phone' => '081299110022', 'email' => null], null, 'hp');
        foreach ([
            [['extra' => 0, 'sarapan' => 1], 'Sarapan tambahan hanya bisa dipilih bersama extra bed.'],
            [['extra' => 2, 'sarapan' => 0], 'Extra bed maksimal 1 per kamar.'],
            [['extra' => 1, 'sarapan' => 4], 'Sarapan tambahan kamar 1 maksimal 3 orang.'],
        ] as [$pilih, $pesan]) {
            try {
                $layanan->buatOnline($pemesan, CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-13'), [2 => 1], null, [2 => [$pilih]]);
                $this->fail('Seharusnya ditolak: '.$pesan);
            } catch (ValidationException $e) {
                $this->assertSame($pesan, $e->errors()['tambahan'][0]);
            }
        }
        $this->assertSame(0, Reservation::count());
    }

    public function test_D5_melepas_extra_bed_ikut_melepas_sarapan_tambahan(): void
    {
        $this->formulir()
            ->call('aturTambahan', 1, 'extra', 1)->call('aturTambahan', 1, 'sarapan', 2)
            ->assertSee('data-pilihan-tambahan="sarapan"', false)
            ->call('aturTambahan', 1, 'extra', 0)
            ->assertSet('tambahan.1', ['extra' => 0, 'sarapan' => 0])
            ->assertDontSee('data-pilihan-tambahan="sarapan"', false)
            ->assertDontSee('Sarapan tambahan · 2 orang')->assertSee('Bayar Rp690.000');
    }

    public function test_D6_extra_bed_lebih_dari_satu_memakai_stepper(): void
    {
        DB::table('settings')->updateOrInsert(['setting_key' => 'extra_bed_max_qty'], ['value' => '2', 'type' => 'integer']);
        Cache::forget('settings.all');

        $this->formulir()->call('aturTambahan', 1, 'extra', 1)->assertSee('maks 2 extra bed')
            ->call('aturTambahan', 1, 'extra', 2)->call('aturTambahan', 1, 'sarapan', 4)
            ->assertSet('tambahan.1', ['extra' => 2, 'sarapan' => 4])
            // Extra bed dikurangi: porsi sarapan ikut dibatasi (2 + 1)
            ->call('aturTambahan', 1, 'extra', 1)->assertSet('tambahan.1', ['extra' => 1, 'sarapan' => 3]);
    }

    public function test_D7_walk_in_front_office_dengan_tambahan_dan_tampil_di_check_in(): void
    {
        Carbon::setTestNow('2026-10-06 15:00:00');
        $kamar = $this->kamar('104'); // Standard, maks 2 orang
        Livewire::actingAs($this->akun('fo'))->test(ReservasiLangsung::class)
            ->set('kamar', [(string) $kamar->id])->set('langsungCheckIn', false)
            ->call('aturTambahan', $kamar->id, 'sarapan', 1)->assertSet('tambahan', [$kamar->id => ['extra' => 0, 'sarapan' => 0]])
            ->call('aturTambahan', $kamar->id, 'extra', 1)->call('aturTambahan', $kamar->id, 'sarapan', 3)
            ->assertSee('Sarapan tambahan · 3 orang × 1 malam')->assertSee('Rp700.000')
            ->set('nama', 'Agus Salim')->set('hp', '081277003344')
            ->call('simpan')->assertHasNoErrors()->assertRedirect();

        $r = Reservation::sole();
        // 360.000 + 100.000 + 3 × 80.000
        $this->assertSame(700000.0, (float) $r->stayInvoice->total_amount);
        $this->assertStringContainsString('Kamar 104', $r->stayInvoice->items()->where('item_type', 'fnb')->value('description'));

        Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)->assertSee('+ Extra bed × 1 · Sarapan tambahan 3 orang');
        $this->assertSame(700000.0, app(FolioService::class)->rincian($r->fresh())['nota']['stay']['total']);
    }

    public function test_D8_extra_bed_tanpa_sarapan_tambahan_hanya_satu_baris_di_nota(): void
    {
        // Kamar 460.000 × 3 = 1.380.000; Extra bed 100.000 × 3 = 300.000; tanpa sarapan tambahan
        $f = $this->formulir()->call('aturTambahan', 1, 'extra', 1)
            ->assertSet('tambahan.1', ['extra' => 1, 'sarapan' => 0])
            ->assertSee('Tamu extra bed belum mendapat sarapan. Centang sarapan tambahan bila diperlukan.')
            ->assertSee('Extra bed × 1 · 3 malam')->assertDontSee('Sarapan tambahan ·')->assertSee('Rp1.680.000');
        $f->set('nama', 'Rizky Pratama')->set('hp', '081299110022')->set('metode', 'bca')->call('pesan')->assertHasNoErrors();

        $r = Reservation::sole();
        $nota = $r->stayInvoice;
        $this->assertSame(1680000.0, (float) $nota->total_amount);
        $this->assertSame(['room', 'amenity'], $nota->items()->orderBy('id')->pluck('item_type')->all());
        $this->assertSame(0, $nota->items()->where('item_type', 'fnb')->count());
        $this->assertStringStartsWith('Extra bed × 1 · 3 malam, Kamar 111 (TMB/', $nota->items()->where('item_type', 'amenity')->value('description'));
        $this->assertSame(1, ServiceOrder::tambahanKamar()->sole()->items()->count());

        // Pesan dan halaman tamu hanya menyebut extra bed
        $this->assertStringContainsString('Tambahan: Kamar 111: Extra bed × 1'."\n", app(NotifikasiReservasi::class)->teksDibuat($r));
        $this->get($r->urlStatus())->assertOk()->assertSee('+ Extra bed × 1')->assertDontSee('Sarapan tambahan');
    }
}
