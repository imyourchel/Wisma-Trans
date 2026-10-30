<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Keuangan\NotaService;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Aturan bisnis Modul 3 (reservasi) di tingkat layanan. Waktu acuan: Selasa, 6 Okt 2026 pukul 10.00 WITA. */
class ReservasiTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private ReservasiService $reservasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        $this->reservasi = app(ReservasiService::class);
    }

    private function tamu(string $nama = 'Rizky Pratama', string $hp = '081299110022'): Customer
    {
        return Customer::firstOrCreate(['phone' => $hp], ['full_name' => $nama, 'is_active' => true]);
    }

    private function tgl(string $t): CarbonImmutable
    {
        return CarbonImmutable::parse($t);
    }

    private function online(array $pesanan, string $masuk = '2026-10-10', string $keluar = '2026-10-13', ?Customer $c = null): Reservation
    {
        $ids = collect($pesanan)->mapWithKeys(fn ($n, $kode) => [$this->tipe($kode)->id => $n])->all();

        return $this->reservasi->buatOnline($c ?? $this->tamu(), $this->tgl($masuk), $this->tgl($keluar), $ids);
    }

    private function galat(callable $f, string $pesan): void
    {
        try {
            $f();
            $this->fail('Seharusnya ditolak: '.$pesan);
        } catch (ValidationException $e) {
            $this->assertContains($pesan, collect($e->errors())->flatten()->all());
        }
    }

    public function test_R01_reservasi_online_menghitung_uang_muka_batas_bayar_dan_menyalin_harga(): void
    {
        $r = $this->online(['DLX' => 2]);

        $this->assertSame('pending_payment', $r->status);
        $this->assertSame('RSV-0001', $r->reservation_code);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $r->access_token);
        // 2 kamar × 3 malam × Rp460.000 = Rp2.760.000; uang muka 50%
        $this->assertSame(1380000.0, (float) $r->dp_amount_required);
        $this->assertTrue($r->dp_due_at->equalTo(now()->addHours(24)));
        $this->assertSame(2760000.0, (float) $r->stayInvoice->total_amount);
        $this->assertSame(['booked', 'booked'], $r->rooms->pluck('status')->all());
        // Nomor kamar langsung ditetapkan (Deluxe bernomor terkecil yang bebas), jadi jenis kasurnya terjamin
        $this->assertSame(['111', '112'], $r->rooms->map(fn ($b) => $b->room->room_number)->all());
        $this->assertDatabaseHas('reservation_logs', ['reservation_id' => $r->id, 'status' => 'pending_payment', 'changed_by' => null]);

        // Harga master berubah: harga reservasi tetap
        $this->tipe('DLX')->update(['base_price' => 500000]);
        $this->assertSame(460000.0, (float) $r->rooms()->first()->price_per_night);
    }

    public function test_R02_ketersediaan_menghitung_pesanan_lain_dan_kamar_perbaikan_tidak_dijual(): void
    {
        $sisa = fn () => app(KetersediaanKamar::class)->sisa($this->tgl('2026-10-10'), $this->tgl('2026-10-12'));
        // STD 10 kamar, kamar 103 sedang diperbaiki
        $this->assertSame(9, $sisa()[$this->tipe('STD')->id]);

        $this->online(['FAM' => 5]);
        $this->assertSame(0, $sisa()[$this->tipe('FAM')->id]);
        $this->galat(fn () => $this->online(['FAM' => 1]), 'Family Room sudah penuh pada tanggal itu.');
        $this->galat(fn () => $this->online(['STD' => 10]), 'Standard Room tinggal 9 kamar pada tanggal itu.');
        // Tanggal yang tidak beririsan tetap bisa
        $this->assertSame('pending_payment', $this->online(['FAM' => 1], '2026-10-13', '2026-10-14')->status);
    }

    public function test_R03_uang_muka_tidak_dibayar_dibatalkan_otomatis_dan_kamar_kembali_tersedia(): void
    {
        $r = $this->online(['FAM' => 5]);
        Payment::create(['payment_code' => 'PAY-0001', 'invoice_id' => $r->stayInvoice->id, 'transaction_type' => 'payment', 'payment_purpose' => 'down_payment',
            'amount' => $r->dp_amount_required, 'method' => 'virtual_account', 'channel' => 'online', 'status' => 'pending', 'midtrans_order_id' => 'WT-RSV0001-DP-AAAA']);

        Carbon::setTestNow(now()->addHours(23));
        $this->assertSame([], $this->reservasi->batalkanKedaluwarsa());

        Carbon::setTestNow(now()->addHours(1));
        $this->artisan('reservasi:batalkan-kedaluwarsa')->assertSuccessful();

        $r->refresh();
        $this->assertSame('cancelled', $r->status);
        $this->assertSame(['cancelled'], $r->rooms->pluck('status')->unique()->values()->all());
        $this->assertSame('cancelled', $r->stayInvoice->status);
        $this->assertSame('expired', Payment::sole()->status);
        $this->assertDatabaseHas('reservation_logs', ['reservation_id' => $r->id, 'status' => 'cancelled', 'note' => 'Batal otomatis: uang muka tidak dibayar dalam 24 jam']);
        $this->assertSame(5, app(KetersediaanKamar::class)->sisa($this->tgl('2026-10-10'), $this->tgl('2026-10-13'))[$this->tipe('FAM')->id]);
    }

    public function test_R04_notifikasi_midtrans_sukses_mengonfirmasi_reservasi_sekali_saja(): void
    {
        $r = $this->online(['STD' => 1], '2026-10-10', '2026-10-11');
        $status = ['order_id' => 'WT-RSV0001-DP-K3J9', 'transaction_status' => 'settlement', 'gross_amount' => '180000.00', 'payment_type' => 'qris', 'transaction_id' => 'TRX-1'];

        // Jumlah lain diabaikan
        $this->assertNull((new PembayaranMidtrans)->terapkan(['gross_amount' => '90000.00'] + $status));
        $this->assertSame('pending_payment', $r->fresh()->status);

        (new PembayaranMidtrans)->terapkan($status);
        (new PembayaranMidtrans)->terapkan($status);

        $r->refresh();
        $this->assertSame('confirmed', $r->status);
        $this->assertTrue($r->sudahDibayar());
        $bayar = Payment::sole();
        $this->assertSame(['down_payment', 'qris', 'success'], [$bayar->payment_purpose, $bayar->method, $bayar->status]);
        $this->assertSame(['partially_paid', 180000.0], [$r->stayInvoice->status, (float) $r->stayInvoice->paid_amount]);
        $this->assertSame(1, $r->logs()->where('status', 'confirmed')->count());
    }

    private function dibayar(Reservation $r): Reservation
    {
        (new PembayaranMidtrans)->terapkan(['order_id' => 'WT-'.str_replace('-', '', $r->reservation_code).'-DP-AAAA', 'transaction_status' => 'settlement',
            'gross_amount' => (string) $r->dp_amount_required, 'payment_type' => 'qris']);

        return $r->fresh();
    }

    public function test_R05_batal_lebih_dari_sehari_sebelum_kedatangan_uang_muka_kembali_separuh(): void
    {
        // Uang muka Rp180.000 → kembali Rp90.000
        $r = $this->dibayar($this->online(['STD' => 1], '2026-10-10', '2026-10-11'));
        $this->assertSame(90000.0, $this->reservasi->batalkan($r, 'Rencana berubah', null));
        $r->refresh();
        $this->assertSame('cancelled', $r->status);
        $this->assertStringContainsString('dana kembali 50% dari uang muka (Rp90.000)', $r->logs()->latest('id')->first()->note);

        // H-1: uang muka hangus
        $r2 = $this->dibayar($this->online(['STD' => 1], '2026-10-07', '2026-10-08', $this->tamu('Dian Permata', '081388776655')));
        $this->assertSame(1, $this->reservasi->hariMenujuKedatangan($r2));
        $this->assertSame(0.0, $this->reservasi->batalkan($r2, null, $this->akun('fo')));
        $this->assertStringContainsString('uang muka hangus', $r2->logs()->latest('id')->first()->note);
    }

    public function test_R06_ubah_tanggal_harga_tetap_dan_dicatat(): void
    {
        $r = $this->dibayar($this->online(['FAM' => 1], '2026-10-10', '2026-10-12'));
        $this->tipe('FAM')->update(['base_price' => 600000]);

        $this->reservasi->ubahTanggal($r, $this->tgl('2026-10-15'), $this->tgl('2026-10-17'), null);

        $r->refresh()->load('rooms');
        $this->assertSame('2026-10-15', $r->masuk()->toDateString());
        $this->assertSame(550000.0, (float) $r->rooms->first()->price_per_night);
        $this->assertSame(1100000.0, (float) $r->stayInvoice->total_amount);
        $this->assertSame('Tanggal diubah dari 10–12 Okt 2026 menjadi 15–17 Okt 2026, harga tetap', $r->logs()->latest('id')->first()->note);

        // Kurang dari dua hari sebelum kedatangan tidak boleh
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->galat(fn () => $this->reservasi->ubahTanggal($r, $this->tgl('2026-10-20'), $this->tgl('2026-10-21'), null),
            'Tanggal hanya bisa diubah lebih dari 1 hari sebelum kedatangan.');
    }

    public function test_R07_tamu_tidak_datang_ditandai_no_show(): void
    {
        $r = $this->dibayar($this->online(['STD' => 1], '2026-10-06', '2026-10-07'));

        Carbon::setTestNow('2026-10-06 23:00:00');
        $this->assertSame(0, $this->reservasi->tandaiTidakDatang());

        Carbon::setTestNow('2026-10-07 00:05:00');
        $this->artisan('reservasi:tandai-tidak-datang')->assertSuccessful();
        $r->refresh();
        $this->assertSame('no_show', $r->status);
        $this->assertSame('cancelled', $r->stayInvoice->status);
        $this->assertSame('Tamu tidak datang, uang muka hangus', $r->stayInvoice->note);
    }

    public function test_R08_walk_in_lalu_check_in_mulai_jam_check_in(): void
    {
        $fo = $this->akun('fo');
        $r = $this->reservasi->buatLangsung($this->tamu('Agus Salim', '081277003344'), $this->tgl('2026-10-06'), $this->tgl('2026-10-07'), [$this->kamar('104')->id], $fo);
        $this->assertSame(['confirmed', 'walk_in', $fo->id], [$r->status, $r->booking_channel, $r->created_by]);
        $this->assertSame('Kamar 104 · Twin Single, 1 malam', $r->stayInvoice->items()->sole()->description);

        // Kamar yang sudah dipesan tidak bisa dipilih lagi
        $this->galat(fn () => $this->reservasi->buatLangsung($this->tamu('Hasan Basri', '081322006677'), $this->tgl('2026-10-06'), $this->tgl('2026-10-08'), [$this->kamar('104')->id], $fo),
            'Kamar 104 sudah dipesan pada tanggal itu.');

        // Sebelum jam early check-in dihitung menginap malam sebelumnya (early check-in: lihat EarlyCheckInTest)
        Carbon::setTestNow('2026-10-06 05:30:00');
        $this->galat(fn () => $this->reservasi->checkIn($r, [], true, 'Salim Agus Wijaya', $fo),
            'Kedatangan sebelum pukul 06.00 dihitung menginap malam sebelumnya. Ubah tanggal masuk reservasi terlebih dahulu.');

        Carbon::setTestNow('2026-10-06 15:05:00');
        $this->galat(fn () => $this->reservasi->checkIn($r, [], true, '', $fo), 'Isi nama sesuai KTP yang ditahan.');
        $this->reservasi->checkIn($r, [], true, 'Salim Agus Wijaya', $fo);

        $r->refresh();
        $this->assertSame(['checked_in', true, 'Salim Agus Wijaya'], [$r->status, $r->id_card_held, $r->id_card_holder_name]);
        $this->assertSame(RoomStatus::OccupiedClean, $this->kamar('104')->status);
        $this->assertDatabaseHas('room_status_logs', ['room_id' => $this->kamar('104')->id, 'status' => 'occupied_clean', 'changed_by' => $fo->id, 'note' => 'Check-in '.$r->reservation_code]);
        $this->assertDatabaseHas('reservation_logs', ['reservation_id' => $r->id, 'event_type' => 'id_card_received', 'note' => 'KTP atas nama Salim Agus Wijaya']);
    }

    public function test_R09_check_in_online_menetapkan_nomor_kamar_yang_siap_dan_sesuai_tipe(): void
    {
        $r = $this->dibayar($this->online(['DLX' => 1], '2026-10-06', '2026-10-08'));
        $baris = $r->rooms->first();
        $fo = $this->akun('fo');
        Carbon::setTestNow('2026-10-06 14:00:00');

        $this->assertSame($this->kamar('111')->id, $baris->room_id);
        $this->galat(fn () => $this->reservasi->checkIn($r, [$baris->id => $this->kamar('101')->id], false, null, $fo), 'Kamar 101 bukan tipe yang dipesan.');
        $this->galat(fn () => $this->reservasi->checkIn($r, [$baris->id => $this->kamar('201')->id], false, null, $fo), 'Kamar 201 belum siap (Kosong, kotor).');

        $this->reservasi->checkIn($r, [$baris->id => $this->kamar('113')->id], false, null, $fo);
        $this->assertSame([$this->kamar('113')->id, 'checked_in'], [$baris->fresh()->room_id, $baris->fresh()->status]);
        $this->assertSame('Kamar 113 · Twin Double, 2 malam', $r->stayInvoice->items()->sole()->description);
    }

    public function test_R10_pengajuan_instansi_disetujui_dengan_kamar_dan_potongan_atau_ditolak(): void
    {
        $dinkes = Institution::create(['code' => 'DINKES', 'name' => 'Dinas Kesehatan Luwu Timur', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $rudi = Customer::create(['full_name' => 'Rudi Hartawan', 'phone' => '081355500004', 'institution_id' => $dinkes->id, 'is_active' => true]);

        $r = $this->reservasi->ajukanInstansi($rudi, null, $this->tgl('2026-10-22'), $this->tgl('2026-10-26'), 3, 'Monitoring puskesmas', null);
        $this->assertSame(['waiting_approval', $dinkes->id, 0], [$r->status, $r->institution_id, $r->rooms()->count()]);
        $this->assertSame(3, $r->request->room_count);
        $this->assertSame('Pengajuan: 3 kamar, 22–26 Okt 2026', $r->logs()->sole()->note);

        $this->reservasi->setujui($r, $dinkes, [
            ['room_id' => $this->kamar('111')->id, 'price' => 460000],
            ['room_id' => $this->kamar('113')->id, 'price' => 460000, 'discount_type' => 'percent', 'discount_value' => 20],
            ['room_id' => $this->kamar('114')->id, 'price' => 0],
        ], 'due_after_checkout', 'tamu_bayar', 'Kamar 113 potongan 20%, kamar 114 gratis', $this->akun('manager'));

        $r->refresh();
        $this->assertSame(['confirmed', null, 'tamu_bayar'], [$r->status, $r->billing_scheme, $r->service_billing_scheme]);
        $this->assertSame([1840000.0, 1472000.0, 0.0], $r->rooms->map->biaya()->all());
        $this->assertNull($r->dp_amount_required);

        $r2 = $this->reservasi->ajukanInstansi($rudi, null, $this->tgl('2026-10-20'), $this->tgl('2026-10-22'), 12, 'Pelatihan kader posyandu', null);
        $this->reservasi->tolak($r2, 'Hanya tersisa 7 kamar pada tanggal itu', $this->akun('manager'));
        $this->assertSame('rejected', $r2->fresh()->status);
        $this->galat(fn () => $this->reservasi->tolak($r2, 'lagi', $this->akun('manager')), 'Pengajuan ini sudah diputuskan.');
    }

    public function test_R11_perpanjang_dan_pindah_kamar_tanpa_merugikan_tamu(): void
    {
        $fo = $this->akun('fo');
        $r = $this->reservasi->buatLangsung($this->tamu(), $this->tgl('2026-10-06'), $this->tgl('2026-10-09'), [$this->kamar('209')->id], $fo);
        Carbon::setTestNow('2026-10-06 14:30:00');
        $this->reservasi->checkIn($r, [], false, null, $fo);
        $baris = $r->rooms()->sole();

        $this->reservasi->perpanjang($baris, $this->tgl('2026-10-10'), $fo);
        $this->assertSame('2026-10-10', $baris->fresh()->check_out_date->toDateString());

        // Hari kedua AC rusak: pindah ke kamar 207 (data contoh: 207 terisi, jadi pakai 115)
        Carbon::setTestNow('2026-10-07 10:00:00');
        $baru = $this->reservasi->pindahKamar($baris->fresh(), $this->kamar('115'), 'AC rusak', $fo);

        $this->assertSame(['moved', '2026-10-07'], [$baris->fresh()->status, $baris->fresh()->check_out_date->toDateString()]);
        $this->assertSame([$baris->id, '2026-10-07', '2026-10-10', 460000.0], [$baru->moved_from_id, $baru->check_in_date->toDateString(), $baru->check_out_date->toDateString(), (float) $baru->price_per_night]);
        $this->assertSame(RoomStatus::VacantDirty, $this->kamar('209')->status);
        $this->assertSame(RoomStatus::OccupiedClean, $this->kamar('115')->status);
        // Total malam tetap 4: 1 malam di 209 + 3 malam di 115
        $this->assertSame(['Kamar 209 · Twin Double, 1 malam', 'Kamar 115 · Twin Double, 3 malam'], $r->stayInvoice->items()->orderBy('id')->pluck('description')->all());
        $this->assertSame(1840000.0, (float) $r->stayInvoice->fresh()->total_amount);
        $this->assertSame('Pindah dari kamar 209 ke 115: AC rusak', $r->logs()->latest('id')->first()->note);
    }

    public function test_R12_nota_dinkes_cocok_dengan_contoh_dokumen(): void
    {
        $c = $this->tamu('Rudi Hartawan', '081355500004');
        $nota = Invoice::create(['invoice_number' => 'INV/2026/09/0004', 'invoice_type' => 'stay', 'customer_id' => $c->id, 'payment_term' => 'credit',
            'discount_type' => 'amount', 'discount_value' => 50000, 'status' => 'issued']);
        foreach ([[460000, null, null], [460000, 'percent', 20], [0, null, null]] as [$harga, $jenis, $nilai]) {
            $kotor = $harga * 4;
            $potong = $jenis ? $kotor * $nilai / 100 : 0;
            $nota->items()->create(['item_type' => 'room', 'description' => 'Kamar', 'quantity' => 4, 'unit_price' => $harga,
                'discount_type' => $jenis, 'discount_value' => $nilai, 'discount_amount' => $potong, 'subtotal' => $kotor - $potong]);
        }
        $nota->items()->create(['item_type' => 'late_checkout', 'description' => 'Terlambat keluar', 'quantity' => 1, 'unit_price' => 250000, 'subtotal' => 250000]);

        app(NotaService::class)->hitungUlang($nota);
        $this->assertSame([3562000.0, 50000.0, 3512000.0], [(float) $nota->subtotal, (float) $nota->discount_amount, (float) $nota->total_amount]);
    }

    public function test_R13_pengaturan_dibaca_dari_tabel_settings(): void
    {
        DB::table('settings')->insert([
            ['setting_key' => 'dp_min_percent', 'value' => '30', 'type' => 'integer'],
            ['setting_key' => 'dp_due_hours', 'value' => '6', 'type' => 'integer'],
        ]);
        cache()->forget('settings.all');

        $r = $this->online(['STD' => 1], '2026-10-10', '2026-10-11');
        $this->assertSame(108000.0, (float) $r->dp_amount_required);
        $this->assertTrue($r->dp_due_at->equalTo(now()->addHours(6)));
    }
}
