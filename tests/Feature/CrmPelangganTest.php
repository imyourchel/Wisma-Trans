<?php

namespace Tests\Feature;

use App\Livewire\Staf\Pelanggan\DaftarPelanggan;
use App\Livewire\Staf\Pelanggan\Instansi;
use App\Livewire\Staf\Pelanggan\RiwayatTamu;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Services\Keuangan\NotaService;
use App\Services\Keuangan\PiutangService;
use App\Services\Laporan\LaporanHunian;
use App\Services\Laporan\LaporanPelanggan;
use App\Services\Laporan\LaporanPendapatan;
use App\Services\Laporan\PeringkatPelanggan;
use App\Services\Laporan\Periode;
use App\Services\Pelanggan\ProfilPelanggan;
use App\Services\Reservasi\CheckOutService;
use App\Services\Reservasi\ReservasiService;
use App\Support\Nomor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** CRM: profil dan rekomendasi pelanggan, Daftar Pelanggan, Instansi, Riwayat Tamu, dan hak akses. */
class CrmPelangganTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private int $n = 0;

    private Service $nasi;

    private Service $mie;

    private Service $laundry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Carbon::setTestNow('2026-10-12 15:00:00');
        $this->withoutVite();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        $this->nasi = Service::create(['code' => 'NG02', 'name' => 'Nasi Goreng Spesial', 'category' => 'fnb', 'department' => 'fnb', 'unit' => 'porsi', 'price' => 35000, 'is_active' => true]);
        $this->mie = Service::create(['code' => 'MG01', 'name' => 'Mie Goreng', 'category' => 'fnb', 'department' => 'fnb', 'unit' => 'porsi', 'price' => 30000, 'is_active' => true]);
        $this->laundry = Service::create(['code' => 'LDR01', 'name' => 'Laundry Kiloan', 'category' => 'laundry', 'department' => 'housekeeping', 'unit' => 'kg', 'price' => 15000, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- Bantuan ---------------- */

    private function pelanggan(string $nama, ?int $instansi = null): Customer
    {
        return Customer::create(['full_name' => $nama, 'phone' => '081322'.str_pad((string) ++$this->n, 2, '0', STR_PAD_LEFT).'4567', 'institution_id' => $instansi]);
    }

    /**
     * Reservasi dengan baris kamar [nomor, masuk, keluar, status baris?].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $baris
     */
    private function kunjungan(Customer $c, array $baris, string $status = 'checked_out', ?int $instansi = null): int
    {
        $rsv = DB::table('reservations')->insertGetId(['reservation_code' => 'RSV-C'.(++$this->n), 'customer_id' => $c->id, 'institution_id' => $instansi,
            'booking_channel' => $instansi ? 'online' : 'walk_in', 'status' => $status]);
        $bawaan = ['checked_out' => 'checked_out', 'checked_in' => 'checked_in'][$status] ?? 'booked';
        foreach ($baris as $b) {
            $room = $this->kamar($b[0]);
            DB::table('reservation_rooms')->insert(['reservation_id' => $rsv, 'room_type_id' => $room->room_type_id,
                'room_id' => ($b[3] ?? $bawaan) === 'booked' ? null : $room->id,
                'check_in_date' => $b[1], 'check_out_date' => $b[2], 'price_per_night' => 400000, 'status' => $b[3] ?? $bawaan]);
        }

        return $rsv;
    }

    private function barisKamar(int $rsv): int
    {
        return (int) DB::table('reservation_rooms')->where('reservation_id', $rsv)->where('status', '!=', 'moved')->value('id');
    }

    private function pesan(Customer $c, ?int $barisKamar, Service $s, float $jumlah, string $status = 'delivered'): void
    {
        $o = DB::table('service_orders')->insertGetId(['order_code' => 'ORD-C'.(++$this->n), 'order_type' => $barisKamar ? 'in_house' : 'outside_guest',
            'customer_id' => $c->id, 'reservation_room_id' => $barisKamar, 'serving_type' => 'room_service', 'payment_mode' => 'charge_to_room',
            'order_date' => '2026-09-01 12:00:00', 'status' => $status]);
        DB::table('service_order_items')->insert(['service_order_id' => $o, 'service_id' => $s->id, 'quantity' => $jumlah,
            'unit_price' => $s->price, 'subtotal' => $jumlah * (float) $s->price]);
    }

    private function nota(Customer $c, float $total, ?int $instansi = null, string $status = 'paid', string $termin = 'immediate'): Invoice
    {
        return Invoice::create(['invoice_number' => Nomor::nota(), 'invoice_type' => 'service', 'customer_id' => $c->id, 'institution_id' => $instansi,
            'payment_term' => $termin, 'issue_date' => '2026-09-10', 'subtotal' => $total, 'total_amount' => $total, 'status' => $status]);
    }

    private function bayar(Invoice $n, float $jumlah, string $jenis = 'payment', string $status = 'success'): void
    {
        Payment::create(['payment_code' => 'PAY-C'.(++$this->n), 'invoice_id' => $n->id, 'transaction_type' => $jenis, 'payment_purpose' => 'service',
            'amount' => $jumlah, 'method' => 'cash', 'channel' => 'manual', 'status' => $status, 'paid_at' => $status === 'success' ? '2026-09-10 10:00:00' : null]);
    }

    private function profil(Customer $c): array
    {
        ProfilPelanggan::lupakan($c->id);

        return app(ProfilPelanggan::class)->untuk($c);
    }

    /* ---------------- 1. Segmen ---------------- */

    public function test_CRM01_segmen_mengikuti_segmen_pelanggan_dan_sama_dengan_laporan(): void
    {
        $vale = Institution::create(['name' => 'PT Vale Indonesia', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $andi = $this->pelanggan('Andi Pratama');
        $this->kunjungan($andi, [['201', '2026-10-01', '2026-10-03']], instansi: $vale->id);
        $this->kunjungan($andi, [['101', '2026-10-05', '2026-10-06']]);
        $budi = $this->pelanggan('Budi Hartawan');
        $this->kunjungan($budi, [['102', '2026-10-02', '2026-10-04']]);
        $narahubung = $this->pelanggan('Citra Narahubung', $vale->id);

        $this->assertSame('B2B (1 kunjungan instansi, 1 kunjungan pribadi)', $this->profil($andi)['segmen']['rincian']);
        $this->assertFalse($this->profil($budi)['segmen']['b2b']);
        $this->assertTrue($this->profil($narahubung)['segmen']['b2b']);

        // Porsi malam B2B/B2C di Riwayat Tamu sama dengan Laporan Pelanggan untuk data yang sama
        $p = Periode::buat('rentang', '2026-10-01', '2026-10-11');
        $laporan = app(LaporanPelanggan::class)->segmen(app(LaporanPendapatan::class)->potongan($p), app(LaporanHunian::class)->malamTerisi($p));
        $riwayat = Livewire::actingAs($this->akun('manager'))->test(RiwayatTamu::class)->set('dari', '2026-10-01')->set('sampai', '2026-10-11')->instance()->ringkasan();
        $this->assertSame([2, 3], [$riwayat['malam_b2b'], $riwayat['malam_b2c']]);
        $this->assertSame([$laporan['b2b']['malam'], $laporan['b2c']['malam']], [$riwayat['malam_b2b'], $riwayat['malam_b2c']]);

        Livewire::actingAs($this->akun('manager'))->test(DaftarPelanggan::class)->set('segmen', 'b2b')
            ->assertSee('Andi Pratama')->assertSee('Citra Narahubung')->assertDontSee('Budi Hartawan')
            ->call('open', $andi->id)->assertSee('B2B (1 kunjungan instansi, 1 kunjungan pribadi)');
    }

    /* ---------------- 2. Rekomendasi kamar dan tamu baru ---------------- */

    public function test_CRM02_kamar_favorit_dari_kamar_terakhir_dan_tamu_baru(): void
    {
        $rudi = $this->pelanggan('Rudi Tandiarrang');
        foreach (['2026-08-01', '2026-08-08', '2026-08-15', '2026-08-22'] as $m) {
            $this->kunjungan($rudi, [['205', $m, CarbonImmutable::parse($m)->addDays(2)->toDateString()]]);
        }
        // Pindah dari 205 ke 104: kamar lama tidak dihitung sebagai favorit, malamnya tetap dihitung
        $this->kunjungan($rudi, [['205', '2026-08-29', '2026-08-30', 'moved'], ['104', '2026-08-30', '2026-08-31', 'checked_out']]);

        $p = $this->profil($rudi);
        $this->assertSame([5, 10], [$p['kunjungan']['jumlah'], $p['kunjungan']['malam']]);
        $this->assertSame(['205', 4], [$p['kamar']['nomor'][0]['nomor'], $p['kamar']['nomor'][0]['jumlah']]);
        $this->assertContains('Siapkan Kamar 205 (dipakai 4 dari 5 kunjungan)', $p['rekomendasi']);
        $this->assertContains('Tawarkan tipe Deluxe Room', $p['rekomendasi']);
        $this->assertSame('205', $p['kamar']['favorit']['nomor']);

        // Kamar lama yang ditinggalkan karena masalah tidak pernah menjadi favorit
        $sinta = $this->pelanggan('Sinta Wulandari');
        $this->kunjungan($sinta, [['206', '2026-09-01', '2026-09-02', 'moved'], ['207', '2026-09-02', '2026-09-04', 'checked_out']]);
        $this->kunjungan($sinta, [['206', '2026-09-10', '2026-09-11', 'moved'], ['209', '2026-09-11', '2026-09-12', 'checked_out']]);
        $this->assertNotContains('206', array_column($this->profil($sinta)['kamar']['nomor'], 'nomor'));
        $this->assertStringNotContainsString('206', implode(' ', $this->profil($sinta)['rekomendasi']));

        // Satu kunjungan: tanpa rekomendasi, tampil sebagai tamu baru
        $wati = $this->pelanggan('Wati Lestari');
        $rsv = $this->kunjungan($wati, [['205', '2026-09-20', '2026-09-22']]);
        $p = $this->profil($wati);
        $this->assertSame([], $p['rekomendasi']);
        $this->assertNull($p['kamar']['favorit']);
        Livewire::actingAs($this->akun('fo'))->test(DaftarReservasi::class)->call('open', $rsv)
            ->assertSee('Tamu baru')->assertDontSee('data-rekomendasi', false);
    }

    /* ---------------- 3. Laundry dan menu ---------------- */

    public function test_CRM03_rekomendasi_laundry_dan_menu_sesuai_ambang_pesanan_batal_tidak_dihitung(): void
    {
        $lukas = $this->pelanggan('Lukas Wibowo');
        $k1 = $this->kunjungan($lukas, [['101', '2026-09-01', '2026-09-02']]);
        $k2 = $this->kunjungan($lukas, [['102', '2026-09-08', '2026-09-09']]);
        $this->pesan($lukas, $this->barisKamar($k1), $this->laundry, 3);
        $this->pesan($lukas, $this->barisKamar($k1), $this->nasi, 1);
        $this->pesan($lukas, $this->barisKamar($k2), $this->nasi, 2);
        foreach ([1, 2, 3] as $_) {
            $this->pesan($lukas, $this->barisKamar($k2), $this->mie, 1, 'cancelled');
        }

        $p = $this->profil($lukas);
        $this->assertContains('Tawarkan laundry saat check-in', $p['rekomendasi']);
        $this->assertContains('Menu favorit: Nasi Goreng Spesial', $p['rekomendasi']);
        $this->assertSame(['Nasi Goreng Spesial'], array_column($p['layanan']['menu'], 'nama'));
        $this->assertSame([3.0, 1, 1], [$p['layanan']['laundry_kg'], $p['layanan']['laundry_pesanan'], $p['layanan']['laundry_kunjungan']]);

        // Laundry di 1 dari 3 kunjungan, laundry batal di kunjungan lain, menu hanya sekali: tidak ada rekomendasi layanan
        $maria = $this->pelanggan('Maria Tandi');
        $m1 = $this->kunjungan($maria, [['103', '2026-09-01', '2026-09-02']]);
        $m2 = $this->kunjungan($maria, [['104', '2026-09-05', '2026-09-06']]);
        $this->kunjungan($maria, [['106', '2026-09-10', '2026-09-11']]);
        $this->pesan($maria, $this->barisKamar($m1), $this->laundry, 2);
        $this->pesan($maria, $this->barisKamar($m2), $this->laundry, 4, 'cancelled');
        $this->pesan($maria, $this->barisKamar($m1), $this->nasi, 1);
        $this->pesan($maria, $this->barisKamar($m1), $this->mie, 1);
        $this->pesan($maria, $this->barisKamar($m2), $this->mie, 1, 'cancelled');

        $p = $this->profil($maria);
        $this->assertSame(1, $p['layanan']['laundry_kunjungan']);
        $this->assertNotContains('Tawarkan laundry saat check-in', $p['rekomendasi']);
        $this->assertSame([], array_values(array_filter($p['rekomendasi'], fn ($s) => str_starts_with($s, 'Menu favorit'))));
    }

    /* ---------------- 4. Total belanja ---------------- */

    public function test_CRM04_total_belanja_mengurangi_refund_mengabaikan_nota_batal_dan_nota_instansi(): void
    {
        $yohana = $this->pelanggan('Yohana Palullungan');
        $n = $this->nota($yohana, 500000);
        $this->bayar($n, 500000);
        $this->bayar($n, 100000, 'refund');
        $this->bayar($n, 50000, 'refund', 'pending');
        $batal = $this->nota($yohana, 200000, status: 'cancelled');
        $this->bayar($batal, 200000);
        $this->assertSame(400000.0, $this->profil($yohana)['nilai']['belanja']);

        // PIC: nota instansi lunas tidak dihitung, hanya pesanan makanan pribadinya
        $pln = Institution::create(['name' => 'PT PLN UP3 Palopo', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $fajar = $this->pelanggan('Fajar Nugroho', $pln->id);
        $this->bayar($this->nota($fajar, 1000000, $pln->id), 1000000);
        $this->bayar($this->nota($fajar, 75000), 75000);
        $this->assertSame(75000.0, $this->profil($fajar)['nilai']['belanja']);

        Livewire::actingAs($this->akun('manager'))->test(DaftarPelanggan::class)->set('cari', 'Fajar')
            ->assertSee('Rp75.000')->assertDontSee('Rp1.075.000')
            ->call('open', $fajar->id)->assertSee(ProfilPelanggan::CATATAN_INSTANSI);
    }

    /* ---------------- 5. Usulan kamar saat check-in ---------------- */

    public function test_CRM05_check_in_mengusulkan_kamar_favorit_hanya_bila_bebas_siap_dan_tipenya_sama(): void
    {
        $rudi = $this->pelanggan('Rudi Tandiarrang');
        $this->kunjungan($rudi, [['205', '2026-08-01', '2026-08-03']]);
        $this->kunjungan($rudi, [['205', '2026-09-01', '2026-09-03']]);
        $dlx = $this->kunjungan($rudi, [['204', '2026-10-12', '2026-10-14', 'booked']], 'confirmed');
        $layar = fn (int $rsv) => Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)->call('open', $rsv);

        // Belum siap (kotor): tidak diusulkan
        DB::table('rooms')->where('room_number', '205')->update(['status' => 'vacant_dirty']);
        $layar($dlx)->assertViewHas('usulan', [])->assertDontSee('Usulan: Kamar 205');

        // Siap, bebas, tipe sama: diusulkan, tetapi tidak dipilih otomatis
        DB::table('rooms')->where('room_number', '205')->update(['status' => 'available']);
        $baris = $this->barisKamar($dlx);
        $l = $layar($dlx)->assertSee('Usulan: Kamar 205')->assertSet('penempatan', [$baris => '']);
        $l->set('penempatan.'.$baris, (string) $this->kamar('205')->id)->assertSet('penempatan', [$baris => (string) $this->kamar('205')->id]);

        // Tipe berbeda (Standard): tidak diusulkan
        $std = $this->kunjungan($rudi, [['105', '2026-10-12', '2026-10-13', 'booked']], 'confirmed');
        $layar($std)->assertViewHas('usulan', []);

        // Kamar 205 terisi tamu lain: tidak ada usulan
        $this->kunjungan($this->pelanggan('Tamu Lain'), [['205', '2026-10-11', '2026-10-13']], 'checked_in');
        $layar($dlx)->assertViewHas('usulan', [])->assertDontSee('Usulan: Kamar 205');
    }

    /* ---------------- 6. Instansi ---------------- */

    public function test_CRM06_instansi_validasi_skema_pendapatan_piutang_dan_narahubung(): void
    {
        $t = Livewire::actingAs($this->akun('manager'))->test(Instansi::class)->call('mulaiTambah')
            ->set('nama', 'Dinas Kesehatan Luwu Timur')->set('skema', 'monthly_fixed_date')->set('tanggalTagih', '')
            ->call('simpan')->assertHasErrors(['tanggalTagih']);
        $t->set('skema', 'due_after_checkout')->set('jatuhTempo', '')->call('simpan')->assertHasErrors(['jatuhTempo']);
        $t->set('jatuhTempo', '400')->call('simpan')->assertHasErrors(['jatuhTempo']);
        $t->set('skema', 'monthly_fixed_date')->set('tanggalTagih', '25')->set('jatuhTempo', '')->call('simpan')->assertHasNoErrors();
        $dinkes = Institution::where('name', 'Dinas Kesehatan Luwu Timur')->firstOrFail();
        $this->assertSame([25, null], [$dinkes->billing_day, $dinkes->due_days]);

        // Pendapatan dari PeringkatPelanggan, piutang dari PiutangService
        $vale = Institution::create(['name' => 'PT Vale Indonesia', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $pic = $this->pelanggan('Hendra Saputra', $vale->id);
        $rina = $this->pelanggan('Rina Maharani', $vale->id);
        $vale->update(['primary_contact_id' => $pic->id]);
        $n = Invoice::create(['invoice_number' => Nomor::nota(), 'invoice_type' => 'stay', 'customer_id' => $pic->id, 'institution_id' => $vale->id,
            'payment_term' => 'credit', 'issue_date' => '2026-09-03', 'due_date' => '2026-09-17', 'status' => 'issued', 'paid_amount' => 300000]);
        $n->items()->create(['item_type' => 'room', 'description' => 'Kamar 201, 2 malam', 'service_date' => '2026-09-01', 'quantity' => 2, 'unit_price' => 400000, 'subtotal' => 800000]);
        app(NotaService::class)->hitungUlang($n);

        $l = Livewire::actingAs($this->akun('manager'))->test(Instansi::class)->assertSee('Pendapatan, 12 bulan terakhir');
        $baris = $l->viewData('daftar')->firstWhere('instansi.id', $vale->id);
        $peringkat = app(PeringkatPelanggan::class)->instansi($l->instance()->periodeTerpilih(), PHP_INT_MAX)->firstWhere('id', $vale->id);
        $this->assertSame(800000.0, $baris['pendapatan']);
        $this->assertSame($peringkat['pendapatan'], $baris['pendapatan']);
        $this->assertSame(500000.0, $baris['piutang']);
        $this->assertSame(app(PiutangService::class)->perInstansi()->firstWhere('instansi.id', $vale->id)['sisa'], $baris['piutang']);

        // Narahubung utama tidak bisa dinonaktifkan sebelum ada penggantinya
        $l->call('open', $vale->id)->call('nonaktifkan', $pic->id)->assertHasErrors(['narahubung']);
        $this->assertTrue($pic->fresh()->is_active);
        $l->call('jadikanUtama', $rina->id)->call('nonaktifkan', $pic->id)->assertHasNoErrors();
        $this->assertSame([$rina->id, false], [$vale->fresh()->primary_contact_id, $pic->fresh()->is_active]);
        $l->call('jadikanUtama', $pic->id)->assertHasErrors(['narahubung']);
        $l->call('aktifkan', $pic->id);
        $this->assertTrue($pic->fresh()->is_active);

        // Tambah narahubung: buat baru, atau tautkan pelanggan lama; narahubung instansi lain ditolak
        $l->call('mulaiNarahubung')->set('hpNarahubung', '081377884455')->set('namaNarahubung', 'Yusuf Pagiling')->call('simpanNarahubung')->assertHasNoErrors();
        $this->assertSame($vale->id, Customer::where('phone', '081377884455')->value('institution_id'));
        $lepas = $this->pelanggan('Daniel Sampe');
        $l->call('mulaiNarahubung')->set('hpNarahubung', $lepas->phone)->call('simpanNarahubung')->assertHasNoErrors();
        $this->assertSame($vale->id, $lepas->fresh()->institution_id);
        $orangDinkes = $this->pelanggan('Agus Dinkes', $dinkes->id);
        $l->call('mulaiNarahubung')->set('hpNarahubung', $orangDinkes->phone)->call('simpanNarahubung');
        $this->assertSame('Sudah menjadi narahubung Dinas Kesehatan Luwu Timur.', $l->errors()->first('hpNarahubung'));
        $this->assertSame($dinkes->id, $orangDinkes->fresh()->institution_id);
    }

    /* ---------------- 7. HP dobel ---------------- */

    public function test_CRM07_hp_dobel_ditolak_dengan_nama_pemiliknya(): void
    {
        $andi = $this->pelanggan('Andi Pratama');
        $budi = $this->pelanggan('Budi Hartawan');
        $hapus = $this->pelanggan('Cahyo Terhapus');
        $hapus->delete();

        $t = Livewire::actingAs($this->akun('fo'))->test(DaftarPelanggan::class)->call('open', $budi->id)->call('mulaiUbah')
            ->set('hp', $andi->phone)->call('simpanUbah');
        $this->assertSame('Nomor ini sudah dipakai pelanggan Andi Pratama', $t->errors()->first('hp'));
        $t->set('hp', $hapus->phone)->call('simpanUbah');
        $this->assertSame('Nomor ini dipakai pelanggan yang sudah dihapus (Cahyo Terhapus)', $t->errors()->first('hp'));

        $t->set('hp', '0813 9988 7766')->set('email', 'budi@contoh.id')->set('alamat', 'Jl. Soekarno-Hatta, Malili')->call('simpanUbah')->assertHasNoErrors();
        $this->assertSame(['081399887766', 'budi@contoh.id'], [$budi->fresh()->phone, $budi->fresh()->email]);
    }

    /* ---------------- 8. Hak akses ---------------- */

    public function test_CRM08_hak_akses_pelanggan_instansi_dan_riwayat(): void
    {
        $c = $this->pelanggan('Andi Pratama');
        $this->bayar($this->nota($c, 250000), 250000);

        $this->actingAs($this->akun('fo'))->get(route('staf.pelanggan'))->assertOk()->assertSee('Andi Pratama')
            ->assertDontSee('Total belanja')->assertDontSee('Rp250.000');
        $this->actingAs($this->akun('manager'))->get(route('staf.pelanggan'))->assertOk()->assertSee('Total belanja')->assertSee('Rp250.000');
        Livewire::actingAs($this->akun('fo'))->test(DaftarPelanggan::class)->call('open', $c->id)->assertDontSee('Total belanja pribadi');

        $this->actingAs($this->akun('manager'))->get(route('staf.pelanggan.instansi'))->assertOk();
        $this->actingAs($this->akun('manager'))->get(route('staf.pelanggan.riwayat'))->assertOk();
        $this->actingAs($this->akun('fo'))->get(route('staf.pelanggan.instansi'))->assertForbidden();
        $this->actingAs($this->akun('fo'))->get(route('staf.pelanggan.riwayat'))->assertForbidden();
        foreach (['hk', 'fnb'] as $peran) {
            foreach (['staf.pelanggan', 'staf.pelanggan.instansi', 'staf.pelanggan.riwayat'] as $route) {
                $this->actingAs($this->akun($peran))->get(route($route))->assertForbidden();
            }
        }
    }

    /* ---------------- 9. CSV Riwayat Tamu ---------------- */

    public function test_CRM09_csv_riwayat_tamu_mengikuti_penyaring_dan_diawali_bom(): void
    {
        $vale = Institution::create(['name' => 'PT Vale Indonesia', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $this->kunjungan($this->pelanggan('Dewi Instansi'), [['201', '2026-10-01', '2026-10-03']], instansi: $vale->id);
        $this->kunjungan($this->pelanggan('Eko Perorangan'), [['101', '2026-10-02', '2026-10-05']]);
        $this->kunjungan($this->pelanggan('Fani Lama'), [['102', '2026-06-01', '2026-06-02']]);

        $t = Livewire::actingAs($this->akun('manager'))->test(RiwayatTamu::class)
            ->set('dari', '2026-09-01')->set('sampai', '2026-10-11')->set('segmen', 'b2c')
            ->assertSee('Eko Perorangan')->assertDontSee('Dewi Instansi')->assertDontSee('Fani Lama')
            ->call('unduhCsv');
        $unduh = $t->effects['download'];
        $isi = base64_decode($unduh['content']);
        $this->assertSame('Riwayat-Tamu-20260901-20261011.csv', $unduh['name']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi);
        $this->assertStringContainsString('Masuk;Keluar;Reservasi;Pelanggan;Segmen', $isi);
        $this->assertStringContainsString('"Eko Perorangan";B2C;;101;3;0;Tidak;Tidak;Tidak', $isi);
        $this->assertStringNotContainsString('Dewi Instansi', $isi);
        $this->assertStringNotContainsString('Fani Lama', $isi);
    }

    /* ---------------- 11–14. Kunjungan pribadi dan pola rombongan ---------------- */

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function rombongan(array $kamar, string $masuk, string $keluar): array
    {
        return array_map(fn ($k) => [(string) $k, $masuk, $keluar], $kamar);
    }

    public function test_CRM11_pesanan_rombongan_tidak_menjadi_kebiasaan_pribadi_pic(): void
    {
        $pln = Institution::create(['name' => 'PT PLN UP3 Palopo', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $teh = Service::create(['code' => 'TM01', 'name' => 'Teh Manis', 'category' => 'fnb', 'department' => 'fnb', 'unit' => 'gelas', 'price' => 8000, 'is_active' => true]);
        $fajar = $this->pelanggan('Fajar Nugroho', $pln->id);
        foreach ([['2026-08-01', '2026-08-03', range(101, 110)], ['2026-09-01', '2026-09-03', range(111, 116) + [6 => 201, 7 => 202, 8 => 204, 9 => 206]]] as [$m, $k, $kamar]) {
            $rsv = $this->kunjungan($fajar, $this->rombongan($kamar, $m, $k), instansi: $pln->id);
            $baris = DB::table('reservation_rooms')->where('reservation_id', $rsv)->pluck('id');
            for ($x = 0; $x < 15; $x++) {
                $this->pesan($fajar, $baris[$x % 10], $this->nasi, 1);
            }
        }
        // Kunjungan pribadi: satu kamar atas nama instansi (dinas sendiri) dan satu kamar perorangan
        $dinas = $this->kunjungan($fajar, [['207', '2026-09-20', '2026-09-21']], instansi: $pln->id);
        $liburan = $this->kunjungan($fajar, [['111', '2026-10-01', '2026-10-02']]);
        $this->pesan($fajar, $this->barisKamar($dinas), $teh, 1);
        $this->pesan($fajar, $this->barisKamar($liburan), $teh, 2);

        $p = $this->profil($fajar);
        $this->assertSame([4, 2, 2], [$p['kunjungan']['jumlah'], $p['kunjungan']['pribadi'], $p['kunjungan']['rombongan']]);
        $this->assertContains('Menu favorit: Teh Manis', $p['rekomendasi']);
        $this->assertStringNotContainsString('Nasi Goreng', implode(' ', $p['rekomendasi']));
        $this->assertSame(['Teh Manis'], array_column($p['layanan']['menu'], 'nama'));
        $this->assertSame('Rekomendasi dihitung dari 2 kunjungan pribadi; 2 kunjungan rombongan dihitung di halaman Instansi.', $p['catatan']);

        // Jumlah kunjungan di Daftar Pelanggan tetap semua kunjungan
        Livewire::actingAs($this->akun('manager'))->test(DaftarPelanggan::class)->set('cari', 'Fajar')
            ->assertViewHas('daftar', fn ($d) => (int) $d->first()->kunjungan === 4)
            ->call('open', $fajar->id)->assertSee($p['catatan']);
    }

    public function test_CRM12_pic_yang_hanya_datang_bersama_rombongan_tampil_tamu_baru_pribadi(): void
    {
        $pln = Institution::create(['name' => 'PT PLN UP3 Palopo', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $fajar = $this->pelanggan('Fajar Nugroho', $pln->id);
        $this->kunjungan($fajar, $this->rombongan(['101', '102', '104'], '2026-08-01', '2026-08-03'), instansi: $pln->id);
        $this->kunjungan($fajar, $this->rombongan(['101', '102', '104'], '2026-09-01', '2026-09-03'), instansi: $pln->id);

        $p = $this->profil($fajar);
        $this->assertSame([2, 0], [$p['kunjungan']['jumlah'], $p['kunjungan']['pribadi']]);
        $this->assertSame([], $p['rekomendasi']);
        $this->assertNull($p['kamar']['favorit']);
        $this->assertSame('Tamu baru (pribadi)', ProfilPelanggan::labelBerikutnya($p));

        Livewire::actingAs($this->akun('fo'))->test(ReservasiLangsung::class)->set('hp', $fajar->phone)
            ->assertSee('Pelanggan terdaftar')->assertSee('Tamu baru (pribadi)')->assertDontSee('data-rekomendasi', false);
    }

    public function test_CRM13_pola_rombongan_instansi_dari_semua_reservasinya(): void
    {
        $vale = Institution::create(['name' => 'PT Vale Indonesia', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $nasiKotak = Service::create(['code' => 'NK01', 'name' => 'Nasi Kotak Ayam', 'category' => 'fnb', 'department' => 'fnb', 'unit' => 'kotak', 'price' => 30000, 'is_active' => true]);
        $pic = $this->pelanggan('Hendra Saputra', $vale->id);
        $r1 = $this->kunjungan($pic, $this->rombongan(range(101, 110), '2026-03-02', '2026-03-04'), instansi: $vale->id);
        $r2 = $this->kunjungan($pic, $this->rombongan(range(111, 116), '2025-09-01', '2025-09-04'), instansi: $vale->id);
        $this->kunjungan($pic, [['201', '2025-03-10', '2025-03-11']], instansi: $vale->id);
        foreach ([$r1, $r1, $r1, $r2] as $rsv) {
            $this->pesan($pic, $this->barisKamar($rsv), $nasiKotak, 10);
        }
        $this->pesan($pic, $this->barisKamar($r2), $this->nasi, 1);
        $this->pesan($pic, $this->barisKamar($r1), $this->laundry, 5);

        $pola = app(ProfilPelanggan::class)->untukInstansi($vale);
        $this->assertSame([3, 5.7, 2.0, 17], [$pola['rombongan'], $pola['rata_kamar'], $pola['rata_malam'], $pola['kamar_total']]);
        $this->assertSame(['Nasi Kotak Ayam', 4, 'makan siang'], [$pola['menu'][0]['nama'], $pola['menu'][0]['pesanan'], $pola['menu'][0]['waktu']]);
        $this->assertSame(['Nasi Kotak Ayam', 'Nasi Goreng Spesial'], array_column($pola['menu'], 'nama'));
        $this->assertSame(['Standard Room', 10], [$pola['tipe'][0]['nama'], $pola['tipe'][0]['jumlah']]);
        $this->assertSame(['Biasanya 6 kamar Standard selama 2 malam', 'Siapkan paket makan siang: Nasi Kotak Ayam', 'Biasanya datang Maret'], $pola['rekomendasi']);

        Livewire::actingAs($this->akun('manager'))->test(Instansi::class)->call('open', $vale->id)
            ->assertSee('Pola rombongan')->assertSee('Siapkan paket makan siang: Nasi Kotak Ayam');

        // Muncul juga saat Manager menyetujui pengajuan dari instansi yang sudah pernah datang
        ProfilPelanggan::lupakan($pic->id, $vale->id);
        $r = app(ReservasiService::class)->ajukanInstansi($pic, $vale, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-22'), 5, 'Pelatihan K3', null);
        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)->call('open', $r->id, 'setuju')
            ->assertSee('Pola rombongan PT Vale Indonesia')->assertSee('Biasanya 6 kamar Standard selama 2 malam');
    }

    public function test_CRM14_reservasi_instansi_satu_kamar_adalah_kunjungan_pribadi_pic(): void
    {
        $vale = Institution::create(['name' => 'PT Vale Indonesia', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $pic = $this->pelanggan('Hendra Saputra', $vale->id);
        $this->kunjungan($pic, [['205', '2026-08-01', '2026-08-04']], instansi: $vale->id);
        $this->kunjungan($pic, [['205', '2026-09-01', '2026-09-04']], instansi: $vale->id);
        $this->kunjungan($pic, $this->rombongan(['101', '102'], '2026-09-10', '2026-09-12'), instansi: $vale->id);

        $p = $this->profil($pic);
        $this->assertSame([3, 2, 1], [$p['kunjungan']['jumlah'], $p['kunjungan']['pribadi'], $p['kunjungan']['rombongan']]);
        $this->assertContains('Siapkan Kamar 205 (dipakai 2 dari 2 kunjungan)', $p['rekomendasi']);
        $this->assertContains('Biasanya menginap 3 malam', $p['rekomendasi']);
        $this->assertSame('Kunjungan ke-3 (pribadi)', ProfilPelanggan::labelBerikutnya($p));
    }

    /* ---------------- 10. Cache profil ---------------- */

    public function test_CRM10_cache_profil_dihapus_setelah_check_out(): void
    {
        $i = Institution::create(['name' => 'PT PLN UP3 Palopo', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $pic = $this->pelanggan('Fajar Nugroho', $i->id);
        $this->kunjungan($pic, [['107', '2026-08-01', '2026-08-03']]);
        $this->assertSame(1, $this->profil($pic)['kunjungan']['jumlah']);

        Carbon::setTestNow('2026-10-12 15:00:00');
        $layanan = app(ReservasiService::class);
        $r = $layanan->ajukanInstansi($pic, $i, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-14'), 1, 'Pemeliharaan jaringan', null);
        $layanan->setujui($r, $i, [['room_id' => $this->kamar('107')->id, 'price' => 300000]], null, 'satu_nota', null, $this->akun('manager'));
        $r = $r->fresh();
        $layanan->checkIn($r, [], false, null, $this->akun('fo'), $r->access_token);

        // Tersimpan di cache: kunjungan baru yang ditulis langsung belum terlihat
        $this->kunjungan($pic, [['108', '2026-08-10', '2026-08-11']]);
        $this->assertSame(1, app(ProfilPelanggan::class)->untuk($pic)['kunjungan']['jumlah']);

        Carbon::setTestNow('2026-10-14 10:00:00');
        app(CheckOutService::class)->proses($r->fresh(), $r->rooms()->pluck('id')->all(), [], $this->akun('fo'));
        $this->assertSame('checked_out', $r->fresh()->status);
        $this->assertSame(3, app(ProfilPelanggan::class)->untuk($pic)['kunjungan']['jumlah']);
    }
}
