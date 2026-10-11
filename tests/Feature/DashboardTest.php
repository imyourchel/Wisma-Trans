<?php

namespace Tests\Feature;

use App\Livewire\Staf\DashboardOperasional;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CheckOutService;
use App\Services\Laporan\Format;
use App\Services\Laporan\LaporanHunian;
use App\Services\Laporan\LaporanPendapatan;
use App\Services\Laporan\Periode;
use App\Services\NotaService;
use App\Services\ReservasiService;
use App\Support\Nomor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Dashboard Manager (Hari ini, Bulan ini) dan Front Office: angka sama dengan Laporan dan halaman Check-in/Check-out. */
class DashboardTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->withoutVite();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- Bantuan ---------------- */

    private function pelanggan(string $nama = 'Lukas Wibowo'): Customer
    {
        return Customer::create(['full_name' => $nama, 'phone' => '081377'.str_pad((string) ++$this->n, 2, '0', STR_PAD_LEFT).'2468']);
    }

    /** @param  list<array{0: ?string, 1: string, 2: string, 3: string}>  $baris  kamar, masuk, keluar, status baris */
    private function reservasi(string $status, array $baris, array $tambahan = []): int
    {
        $rsv = DB::table('reservations')->insertGetId(['reservation_code' => 'RSV-D'.(++$this->n), 'customer_id' => $this->pelanggan()->id,
            'booking_channel' => 'walk_in', 'status' => $status, ...$tambahan]);
        foreach ($baris as [$kamar, $masuk, $keluar, $st]) {
            $room = $kamar ? $this->kamar($kamar) : null;
            DB::table('reservation_rooms')->insert(['reservation_id' => $rsv, 'room_type_id' => $room?->room_type_id ?? $this->tipe('STD')->id,
                'room_id' => $room?->id, 'check_in_date' => $masuk, 'check_out_date' => $keluar, 'price_per_night' => 400000, 'status' => $st,
                'actual_check_in_at' => $st === 'booked' ? null : $masuk.' 14:30:00']);
        }

        return $rsv;
    }

    private function nota(string $jenis, string $tanggal, float $jumlah, float $harga): Invoice
    {
        $n = Invoice::create(['invoice_number' => Nomor::nota(), 'invoice_type' => $jenis === 'room' ? 'stay' : 'service', 'customer_id' => $this->pelanggan()->id,
            'payment_term' => 'immediate', 'issue_date' => $tanggal, 'status' => 'issued']);
        $n->items()->create(['item_type' => $jenis, 'description' => $jenis, 'service_date' => $tanggal, 'quantity' => $jumlah, 'unit_price' => $harga, 'subtotal' => $jumlah * $harga]);

        return app(NotaService::class)->hitungUlang($n);
    }

    private function kartu($t, string $bagian): Collection
    {
        return collect($bagian === 'fo' ? $t->viewData('frontOffice') : $t->viewData('manager')[$bagian])->keyBy(fn ($k) => $k['kunci'] ?? $k['label']);
    }

    /* ---------------- 1. Manager sama dengan Laporan ---------------- */

    public function test_D01_manager_okupansi_dan_pendapatan_bulan_ini_sama_dengan_laporan(): void
    {
        $this->reservasi('checked_out', [['101', '2026-10-02', '2026-10-05', 'checked_out']]);
        $this->reservasi('checked_in', [['102', '2026-10-10', '2026-10-14', 'checked_in']]);
        $this->reservasi('checked_out', [['104', '2026-09-03', '2026-09-06', 'checked_out']]);
        $this->nota('room', '2026-10-02', 3, 400000);
        $this->nota('fnb', '2026-10-05', 4, 35000);
        $this->nota('laundry', '2026-10-08', 3, 15000);
        $this->nota('room', '2026-09-03', 3, 380000);
        $this->nota('fnb', '2026-09-20', 2, 35000);

        $t = Livewire::actingAs($this->akun('manager'))->test(DashboardOperasional::class);
        $bulan = $this->kartu($t, 'bulanIni');
        $p = Periode::buat('bulan-ini');
        $uang = app(LaporanPendapatan::class)->diakui($p)['kategori'];
        $uangLalu = app(LaporanPendapatan::class)->diakui($p->sebelumnya())['kategori'];
        $h = app(LaporanHunian::class)->ringkas($p);

        $this->assertSame([$uang['kamar'], $uang['fnb'], $uang['laundry']],
            [$bulan['Pendapatan kamar']['nilai'], $bulan['Pendapatan F&B']['nilai'], $bulan['Pendapatan laundry']['nilai']]);
        $this->assertSame([1200000.0, 140000.0, 45000.0], [$uang['kamar'], $uang['fnb'], $uang['laundry']]);
        $this->assertSame([$uangLalu['kamar'], $uangLalu['fnb']], [$bulan['Pendapatan kamar']['sebelum'], $bulan['Pendapatan F&B']['sebelum']]);
        $this->assertSame([$h['okupansi'], $h['adr']], [$bulan['Okupansi rata-rata']['nilai'], $bulan['ADR']['nilai']]);

        // Teks yang tampil sama dengan halaman Laporan untuk preset "bulan ini"
        $t->assertSee(Format::tampil($uang['kamar'], 'rp'))->assertSee(Format::tampil($h['okupansi'], 'persen'))->assertSee(Format::tampil($h['adr'], 'rp'))
            ->assertSee(route('staf.laporan.pendapatan', ['periode' => 'bulan-ini']), false)
            ->assertSee(route('staf.laporan.hunian', ['periode' => 'hari-ini']), false);
        $this->actingAs($this->akun('manager'))->get(route('staf.laporan.hunian', ['periode' => 'bulan-ini']))->assertSee(Format::tampil($h['okupansi'], 'persen'));
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertOk()->assertSee('Bulan ini')->assertSee('Piutang instansi')->assertSee('wire:poll.60s.visible', false);
    }

    /* ---------------- 2. Front Office ---------------- */

    public function test_D02_front_office_kedatangan_keberangkatan_dan_terlambat(): void
    {
        $this->reservasi('confirmed', [[null, '2026-10-12', '2026-10-14', 'booked']]);
        $this->reservasi('confirmed', [[null, '2026-10-11', '2026-10-13', 'booked']]); // belum datang sejak kemarin
        $this->reservasi('checked_in', [['101', '2026-10-10', '2026-10-12', 'checked_in'], ['102', '2026-10-10', '2026-10-12', 'checked_in']]);
        $this->reservasi('checked_in', [['104', '2026-10-11', '2026-10-12', 'checked_in']]);
        $this->reservasi('checked_in', [['106', '2026-10-09', '2026-10-11', 'checked_in']]); // terlambat sejak kemarin
        $this->reservasi('checked_in', [['107', '2026-10-11', '2026-10-14', 'checked_in']]);
        $this->reservasi('pending_payment', [[null, '2026-10-20', '2026-10-21', 'booked']], ['dp_due_at' => '2026-10-12 18:00:00']);
        $this->reservasi('pending_payment', [[null, '2026-10-20', '2026-10-21', 'booked']], ['dp_due_at' => '2026-10-12 08:00:00']);

        $fo = $this->kartu($t = Livewire::actingAs($this->akun('fo'))->test(DashboardOperasional::class), 'fo');
        $this->assertSame(['2 reservasi', 'termasuk 1 dari kemarin', true], [$fo['kedatangan']['nilai'], $fo['kedatangan']['ket'], $fo['kedatangan']['awas']]);
        $this->assertSame(['3 kamar (2 reservasi)', false], [$fo['keberangkatan']['nilai'], $fo['keberangkatan']['awas']]);
        $this->assertSame(['1 kamar (1 reservasi)', true], [$fo['terlambat']['nilai'], $fo['terlambat']['awas']]);
        $this->assertSame('1 reservasi', $fo['uang-muka']['nilai']);
        $this->assertSame(route('staf.check-out').'#terlambat', $fo['terlambat']['url']);
        $t->assertSee('Front Office hari ini')->assertSee('termasuk 1 dari kemarin');
        $this->assertSame(2, substr_count($t->html(), 'data-awas'));

        // Angka kedatangan sama dengan isi daftar Check-in
        $this->assertSame(2, Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)->viewData('daftar')->count());

        // Setelah pukul 12.00 kamar yang keluar hari ini pindah ke Terlambat, tidak dihitung dua kali
        Carbon::setTestNow('2026-10-12 13:00:00');
        $fo = $this->kartu(Livewire::actingAs($this->akun('fo'))->test(DashboardOperasional::class), 'fo');
        $this->assertSame(['0 kamar (0 reservasi)', '4 kamar (3 reservasi)'], [$fo['keberangkatan']['nilai'], $fo['terlambat']['nilai']]);
    }

    public function test_D03_refund_menunggu_diberi_tanda_awas_dan_kedatangan_hari_ini_saja_tidak(): void
    {
        $this->reservasi('confirmed', [[null, '2026-10-12', '2026-10-13', 'booked']]);
        $n = $this->nota('fnb', '2026-10-05', 2, 35000);
        Payment::create(['payment_code' => 'PAY-D1', 'invoice_id' => $n->id, 'transaction_type' => 'refund', 'payment_purpose' => 'service',
            'amount' => 35000, 'method' => 'cash', 'channel' => 'manual', 'status' => 'pending']);

        $fo = $this->kartu(Livewire::actingAs($this->akun('fo'))->test(DashboardOperasional::class), 'fo');
        $this->assertSame(['1 pengembalian', true], [$fo['refund']['nilai'], $fo['refund']['awas']]);
        $this->assertSame(route('staf.keuangan.pembayaran', ['tab' => 'refund']), $fo['refund']['url']);
        $this->assertSame(['1 reservasi', 'belum check-in', false], [$fo['kedatangan']['nilai'], $fo['kedatangan']['ket'], $fo['kedatangan']['awas']]);
    }

    /* ---------------- 3. Hak akses ---------------- */

    public function test_D04_housekeeping_dan_fnb_tidak_melihat_kartu_pendapatan_maupun_kartu_fo(): void
    {
        foreach (['hk', 'fnb'] as $peran) {
            $this->actingAs($this->akun($peran))->get(route('staf.dashboard'))->assertOk()
                ->assertDontSee('Bulan ini')->assertDontSee('Pendapatan kamar')->assertDontSee('Front Office hari ini')
                ->assertDontSee('Kedatangan hari ini')->assertDontSee('Piutang instansi');
            $t = Livewire::actingAs($this->akun($peran))->test(DashboardOperasional::class);
            $this->assertSame([null, null, null], [$t->viewData('manager'), $t->viewData('frontOffice'), $t->viewData('piutang')]);
        }
        // FO melihat kartu FO, tetapi tidak melihat pendapatan
        $this->actingAs($this->akun('fo'))->get(route('staf.dashboard'))->assertSee('Front Office hari ini')->assertDontSee('Pendapatan kamar');
    }

    /* ---------------- Tambahan: proyeksi dan cache ---------------- */

    public function test_D05_proyeksi_okupansi_menghitung_kedatangan_hari_ini(): void
    {
        $this->reservasi('checked_in', [['101', '2026-10-11', '2026-10-14', 'checked_in'], ['102', '2026-10-11', '2026-10-14', 'checked_in']]);
        $this->reservasi('confirmed', [[null, '2026-10-12', '2026-10-13', 'booked'], [null, '2026-10-12', '2026-10-13', 'booked']]);

        $hari = $this->kartu(Livewire::actingAs($this->akun('manager'))->test(DashboardOperasional::class), 'hariIni');
        $h = app(LaporanHunian::class)->ringkas(Periode::buat('hari-ini'));
        $this->assertSame([2, 32], [$h['terisi'], $h['tersedia']]);
        $this->assertSame(['2 / 32', '2 terisi · +2 akan datang'], [$hari['terisi']['nilai'], $hari['terisi']['ket']]);
        $this->assertSame(['6,3%', '6,3% · 12,5% bila semua datang'], [$hari['okupansi']['nilai'], $hari['okupansi']['ket']]);
        $this->assertSame('Kamar terisi malam ini', $hari['terisi']['label']);
    }

    public function test_D06_bulan_ini_di_cache_dan_dihapus_setelah_pembayaran_dan_check_out(): void
    {
        $this->nota('fnb', '2026-10-05', 2, 35000);
        $nilai = fn () => $this->kartu(Livewire::actingAs($this->akun('manager'))->test(DashboardOperasional::class), 'bulanIni');
        $this->assertSame(70000.0, $nilai()['Pendapatan F&B']['nilai']);

        // Nota baru tanpa pembayaran: masih angka cache
        $n = $this->nota('fnb', '2026-10-06', 1, 35000);
        $this->assertSame(70000.0, $nilai()['Pendapatan F&B']['nilai']);

        // Pembayaran dicatat: cache terhapus, angka langsung berubah
        Payment::create(['payment_code' => 'PAY-D2', 'invoice_id' => $n->id, 'transaction_type' => 'payment', 'payment_purpose' => 'service',
            'amount' => 35000, 'method' => 'cash', 'channel' => 'manual', 'status' => 'success', 'paid_at' => now()]);
        $this->assertSame(105000.0, $nilai()['Pendapatan F&B']['nilai']);

        // Check-out instansi (nota kredit tanpa pembayaran): pendapatan kamar langsung masuk
        Carbon::setTestNow('2026-10-08 15:00:00');
        $i = Institution::create(['name' => 'PT PLN UP3 Palopo', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $pic = Customer::create(['full_name' => 'Fajar Nugroho', 'phone' => '081455500006', 'institution_id' => $i->id]);
        $layanan = app(ReservasiService::class);
        $r = $layanan->ajukanInstansi($pic, $i, CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-10'), 1, 'Pemeliharaan jaringan', null);
        $layanan->setujui($r, $i, [['room_id' => $this->kamar('107')->id, 'price' => 300000]], null, 'satu_nota', null, $this->akun('manager'));
        $layanan->checkIn($r->fresh(), [], false, null, $this->akun('fo'), $r->fresh()->access_token);

        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->assertSame(0.0, $nilai()['Pendapatan kamar']['nilai']);
        app(CheckOutService::class)->proses($r->fresh(), $r->rooms()->pluck('id')->all(), [], $this->akun('fo'));
        $this->assertSame(600000.0, $nilai()['Pendapatan kamar']['nilai']);
    }
}
