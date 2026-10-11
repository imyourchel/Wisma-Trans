<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Services\Laporan\LaporanHunian;
use App\Services\Laporan\LaporanOperasional;
use App\Services\Laporan\LaporanPelanggan;
use App\Services\Laporan\LaporanPendapatan;
use App\Services\Laporan\Periode;
use App\Services\NotaService;
use App\Support\Nomor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Laporan Manager: pendapatan diakui dan kas masuk, hunian (okupansi, ADR, RevPAR), pelanggan, operasional, ekspor. */
class LaporanManagerTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private int $hp = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- Bantuan ---------------- */

    private function pelanggan(string $nama = 'Lukas Wibowo', ?int $instansi = null): Customer
    {
        return Customer::create(['full_name' => $nama, 'phone' => '0852110077'.str_pad((string) ++$this->hp, 2, '0', STR_PAD_LEFT), 'institution_id' => $instansi]);
    }

    /** @param  list<array{0: string, 1: string, 2: float, 3: float, 4?: string}>  $baris  jenis, tanggal, jumlah, harga, deskripsi */
    private function nota(string $jenis, array $baris, ?float $diskon = null, string $status = 'issued', ?int $instansi = null): Invoice
    {
        $n = Invoice::create(['invoice_number' => Nomor::nota(), 'invoice_type' => $jenis, 'customer_id' => $this->pelanggan()->id, 'institution_id' => $instansi,
            'payment_term' => 'immediate', 'issue_date' => '2026-10-09', 'status' => 'issued', 'discount_type' => $diskon ? 'amount' : null, 'discount_value' => $diskon]);
        foreach ($baris as $b) {
            $n->items()->create(['item_type' => $b[0], 'description' => $b[4] ?? $b[0], 'service_date' => $b[1], 'quantity' => $b[2], 'unit_price' => $b[3], 'subtotal' => $b[2] * $b[3]]);
        }
        $n = app(NotaService::class)->hitungUlang($n);
        if ($status === 'cancelled') {
            $n->update(['status' => 'cancelled']);
        }

        return $n;
    }

    /** Data contoh 3 hari (9–11 Okt). */
    private function dataPendapatan(): array
    {
        return [
            'layanan' => $this->nota('service', [['fnb', '2026-10-09', 2, 50000], ['laundry', '2026-10-10', 2, 15000]], diskon: 13000),
            'menginap' => $this->nota('stay', [['room', '2026-10-10', 3, 300000, 'Kamar 106, 3 malam'], ['late_checkout', '2026-10-11', 1, 250000]]),
            'aula' => $this->nota('hall', [['hall', '2026-10-11', 1, 2000000]]),
            'batal' => $this->nota('service', [['fnb', '2026-10-10', 10, 50000]], status: 'cancelled'),
            'luar' => $this->nota('service', [['fnb', '2026-10-08', 2, 35000]]),
        ];
    }

    private function menginap(string $kamar, string $masuk, string $keluar, string $status, float $harga, ?Customer $tamu = null, ?int $instansi = null, ?array $diskon = null): int
    {
        $tamu ??= $this->pelanggan();
        $rsv = DB::table('reservations')->insertGetId(['reservation_code' => 'RSV-T'.(++$this->hp), 'customer_id' => $tamu->id, 'institution_id' => $instansi,
            'booking_channel' => $instansi ? 'online' : 'walk_in', 'status' => $status === 'booked' ? 'confirmed' : ($status === 'checked_out' ? 'checked_out' : 'checked_in')]);
        $room = $this->kamar($kamar);
        DB::table('reservation_rooms')->insert(['reservation_id' => $rsv, 'room_type_id' => $room->room_type_id, 'room_id' => $room->id,
            'check_in_date' => $masuk, 'check_out_date' => $keluar, 'price_per_night' => $harga, 'status' => $status,
            'discount_type' => $diskon[0] ?? null, 'discount_value' => $diskon[1] ?? null, 'actual_check_in_at' => $status === 'booked' ? null : $masuk.' 14:30:00']);

        return $rsv;
    }

    /* ---------------- 1. Pendapatan diakui ---------------- */

    public function test_LP01_pendapatan_per_kategori_nota_batal_dan_alokasi_diskon(): void
    {
        $n = $this->dataPendapatan();
        $d = app(LaporanPendapatan::class)->diakui(Periode::buat('rentang', '2026-10-09', '2026-10-11'));
        // F&B dan laundry setelah diskon nota 10%; kamar 2 dari 3 malam (malam 12 Okt di luar periode)
        $this->assertEqualsWithDelta(['kamar' => 600000.0, 'fnb' => 90000.0, 'laundry' => 27000.0, 'aula' => 2000000.0, 'terlambat' => 250000.0, 'lainnya' => 0.0],
            $d['kategori'], 0.01);
        $this->assertEqualsWithDelta(2967000.0, $d['total'], 0.01);
        $this->assertEqualsWithDelta(90000.0, $d['kelompok']['2026-10-09']['fnb'], 0.01);
        $this->assertEqualsWithDelta(300000.0, $d['kelompok']['2026-10-11']['kamar'], 0.01);

        // Alokasi diskon tidak mengubah total nota
        $semua = app(LaporanPendapatan::class)->potongan(Periode::buat('rentang', '2026-10-01', '2026-10-31'));
        $this->assertEqualsWithDelta((float) $n['layanan']->total_amount, $semua->where('nota', $n['layanan']->id)->sum('nilai'), 0.01);
        $this->assertEqualsWithDelta((float) $n['menginap']->total_amount, $semua->where('nota', $n['menginap']->id)->sum('nilai'), 0.01);
        $this->assertSame(0, $semua->where('nota', $n['batal']->id)->count());

        // Baris kamar 3 malam (10–12 Okt) dengan periode 11–12 Okt: 2/3 subtotal
        $sebagian = app(LaporanPendapatan::class)->diakui(Periode::buat('rentang', '2026-10-11', '2026-10-12'));
        $this->assertEqualsWithDelta(600000.0, $sebagian['kategori']['kamar'], 0.01);
    }

    /* ---------------- 2. Kas masuk ---------------- */

    public function test_LP02_kas_masuk_mengurangi_refund_dan_menghitung_tagihan_bulanan(): void
    {
        $n = $this->nota('service', [['fnb', '2026-10-10', 2, 50000]]);
        $i = Institution::create(['name' => 'Dinas PU', 'default_billing_scheme' => 'monthly_fixed_date', 'billing_day' => 25]);
        $bs = DB::table('billing_statements')->insertGetId(['statement_number' => 'BS/2026/10/0001', 'institution_id' => $i->id, 'period_start' => '2026-09-26',
            'period_end' => '2026-10-25', 'due_date' => '2026-11-08', 'total_amount' => 300000, 'paid_amount' => 300000, 'status' => 'paid']);
        $bayar = fn (array $x) => DB::table('payments')->insert([...['payment_code' => 'PAY-'.(++$this->hp), 'transaction_type' => 'payment', 'payment_purpose' => 'service',
            'method' => 'cash', 'channel' => 'manual', 'status' => 'success', 'paid_at' => '2026-10-10 09:00:00', 'invoice_id' => $n->id], ...$x]);
        $bayar(['amount' => 100000]);
        $bayar(['amount' => 20000, 'transaction_type' => 'refund']);
        $bayar(['amount' => 30000, 'transaction_type' => 'refund', 'status' => 'pending', 'paid_at' => null]);
        $bayar(['amount' => 300000, 'invoice_id' => null, 'billing_statement_id' => $bs, 'method' => 'bank_transfer', 'payment_purpose' => 'settlement', 'paid_at' => '2026-10-11 10:00:00']);
        $bayar(['amount' => 999000, 'paid_at' => '2026-10-05 10:00:00']);

        $k = app(LaporanPendapatan::class)->kasMasuk(Periode::buat('rentang', '2026-10-09', '2026-10-11'));
        $this->assertSame([400000.0, 20000.0, 380000.0, 300000.0], [$k['masuk'], $k['refund'], $k['bersih'], $k['bulanan']]);
        $this->assertSame(['bank_transfer' => 300000.0, 'cash' => 80000.0], $k['metode']);
    }

    /* ---------------- 3. Hunian ---------------- */

    public function test_LP03_okupansi_adr_revpar_dan_tamu_instansi_yang_masih_menginap(): void
    {
        // 10 kamar aktif
        DB::table('rooms')->where('id', '>', 10)->update(['is_active' => 0]);
        $p = Periode::buat('rentang', '2026-10-10', '2026-10-11');
        $this->menginap('101', '2026-10-10', '2026-10-12', 'checked_out', 300000);
        // Rombongan instansi masih menginap, belum bernota (diskon 10%: Rp360.000 per malam)
        $i = Institution::create(['name' => 'PT Vale', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $this->menginap('102', '2026-10-10', '2026-10-14', 'checked_in', 400000, instansi: $i->id, diskon: ['percent', 10]);
        $this->menginap('104', '2026-10-11', '2026-10-12', 'moved', 200000);
        $this->menginap('106', '2026-10-10', '2026-10-12', 'booked', 999000);

        $h = app(LaporanHunian::class)->ringkas($p);
        $this->assertSame([20, 5], [$h['tersedia'], $h['terisi']]);
        $this->assertEqualsWithDelta(25.0, $h['okupansi'], 0.001);
        $this->assertEqualsWithDelta(1520000.0, $h['nilai'], 0.01);
        $this->assertEqualsWithDelta(304000.0, $h['adr'], 0.01);
        $this->assertEqualsWithDelta(76000.0, $h['revpar'], 0.01);
        // Laporan Pendapatan belum memuat kamar yang belum bernota
        $this->assertSame(0.0, app(LaporanPendapatan::class)->diakui($p)['kategori']['kamar']);
        // Kamar 103 Perbaikan sejak 5 Okt (log sebelum periode): 2 malam hilang
        $this->assertSame(2, app(LaporanHunian::class)->hilangPerbaikan($p));
        $this->actingAs($this->akun('manager'))->get(route('staf.laporan.hunian', $p->query()))->assertOk()
            ->assertSee('25,0%')->assertSee('Rp304.000')->assertSee('Hunian memakai nilai kamar terjual');
    }

    /* ---------------- 4. Pelanggan ---------------- */

    public function test_LP04_tamu_berulang_dari_kunjungan_sebelum_periode(): void
    {
        $lama = $this->pelanggan('Hasan Basri');
        $baru = $this->pelanggan('Dian Permata');
        $this->menginap('101', '2026-09-01', '2026-09-03', 'checked_out', 300000, $lama);
        $this->menginap('102', '2026-10-10', '2026-10-11', 'checked_out', 300000, $lama);
        $this->menginap('104', '2026-10-10', '2026-10-12', 'checked_in', 300000, $baru);

        $t = app(LaporanPelanggan::class)->tamu(Periode::buat('rentang', '2026-10-09', '2026-10-11'));
        $this->assertSame(['tamu' => 2, 'baru' => 1, 'berulang' => 1], $t);
        $this->actingAs($this->akun('manager'))->get(route('staf.laporan.pelanggan', ['periode' => 'rentang', 'dari' => '2026-10-09', 'sampai' => '2026-10-11']))
            ->assertOk()->assertSee('10 pelanggan teratas')->assertSee('Hasan Basri');
    }

    /* ---------------- 5. Periode, ekspor, dan operasional ---------------- */

    public function test_LP05_periode_pembanding(): void
    {
        $label = fn (Periode $p) => [$p->mulai->toDateString(), $p->akhir->toDateString()];
        $this->assertSame(['2026-09-01', '2026-09-12'], $label(Periode::buat('bulan-ini')->sebelumnya()));
        $this->assertSame(['2026-10-11', '2026-10-11'], $label(Periode::buat('hari-ini')->sebelumnya()));
        $this->assertSame(['2026-09-29', '2026-10-05'], $label(Periode::buat('7-hari')->sebelumnya()));
        $this->assertSame(['2026-08-01', '2026-08-31'], $label(Periode::buat('bulan-lalu')->sebelumnya()));
        $this->assertSame(['2026-10-06', '2026-10-08'], $label(Periode::buat('rentang', '2026-10-09', '2026-10-11')->sebelumnya()));
        $this->assertTrue(Periode::buat('rentang', '2026-01-01', '2026-10-11')->bulanan());
    }

    public function test_LP06_csv_dan_pdf_mengikuti_penyaring(): void
    {
        $this->dataPendapatan();
        $q = ['periode' => 'rentang', 'dari' => '2026-10-09', 'sampai' => '2026-10-11'];

        $csv = $this->actingAs($this->akun('manager'))->get(route('staf.laporan.pendapatan', [...$q, 'format' => 'csv']));
        $csv->assertOk();
        $isi = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi);
        $this->assertStringContainsString('Periode;"9 Okt – 11 Okt 2026";Pembanding;"6 Okt – 8 Okt 2026"', $isi);
        $this->assertStringContainsString('"Pendapatan diakui";2967000;', $isi);
        $this->assertStringContainsString('Aula;2000000;', $isi);
        $this->assertStringContainsString('attachment; filename=Laporan-Pendapatan-20261009-20261011.csv', (string) $csv->headers->get('Content-Disposition'));

        $pdf = $this->actingAs($this->akun('manager'))->get(route('staf.laporan.pendapatan', [...$q, 'format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Laporan-Pendapatan-20261009-20261011.pdf', (string) $pdf->headers->get('Content-Disposition'));

        // Halaman memuat tautan ekspor dengan penyaring yang sama
        $this->actingAs($this->akun('manager'))->get(route('staf.laporan.pendapatan', $q))->assertOk()
            ->assertSee(e(route('staf.laporan.pendapatan', [...$q, 'format' => 'csv'])), false)
            ->assertSee('Rp2.967.000')->assertSee('Pesanan yang ditagihkan ke kamar masuk laporan setelah tamu check-out');
    }

    public function test_LP07_perawatan_rutin_tidak_memengaruhi_rata_rata_perbaikan(): void
    {
        $mnt = fn (array $x) => DB::table('maintenance_requests')->insert([...['request_code' => 'MNT-'.str_pad((string) ++$this->hp, 4, '0', STR_PAD_LEFT),
            'room_id' => $this->kamar('101')->id, 'damage_description' => 'Uji', 'priority' => 'medium', 'blocks_room' => 0], ...$x]);
        $mnt(['maintenance_type' => 'corrective', 'reported_at' => '2026-10-10 08:00:00', 'completed_at' => '2026-10-10 12:00:00', 'status' => 'completed']);
        $mnt(['maintenance_type' => 'corrective', 'reported_at' => '2026-10-10 09:00:00', 'completed_at' => '2026-10-10 11:00:00', 'status' => 'completed', 'resolution_note' => 'Jalur cepat: AC normal']);
        $mnt(['maintenance_type' => 'preventive', 'reported_at' => '2026-10-10 10:00:00', 'completed_at' => '2026-10-10 10:00:00', 'status' => 'completed']);

        $o = app(LaporanOperasional::class)->hitung(Periode::buat('rentang', '2026-10-09', '2026-10-11'));
        $this->assertSame([2, 2, 1, 1], [$o['mntBaru'], $o['mntSelesai'], $o['jalurCepat'], $o['rutin']]);
        $this->assertEqualsWithDelta(3 * 3600, $o['mntRata'], 0.01);
        $this->actingAs($this->akun('manager'))->get(route('staf.laporan.operasional', ['periode' => 'rentang', 'dari' => '2026-10-09', 'sampai' => '2026-10-11']))
            ->assertOk()->assertSee('3 jam')->assertSee('5 menu terlaris');
    }

    /* ---------------- 6. Hak akses ---------------- */

    public function test_LP08_hanya_manager(): void
    {
        foreach (['pendapatan', 'hunian', 'pelanggan', 'operasional'] as $laporan) {
            foreach (['fo', 'fnb', 'hk', 'admin'] as $akun) {
                $this->actingAs($this->akun($akun))->get(route('staf.laporan.'.$laporan))->assertForbidden();
            }
            $this->actingAs($this->akun('manager'))->get(route('staf.laporan.'.$laporan))->assertOk()->assertSee('dibanding');
        }
    }
}
