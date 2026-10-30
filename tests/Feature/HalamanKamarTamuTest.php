<?php

namespace Tests\Feature;

use App\Livewire\Tamu\DaftarKamar;
use App\Livewire\Tamu\DetailKamar;
use App\Models\RoomType;
use App\Services\Kamar\KetersediaanKamar;
use App\Support\PencarianInap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Halaman Kamar untuk tamu: daftar tipe dengan baris per jenis kasur, detail, cek ketersediaan, dan isi otomatis termurah.
 * Data contoh: STD 10 kamar, DLX 15, FAM 5, VIP 2; hari ini 6 Okt 2026.
 */
class HalamanKamarTamuTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
    }

    /** Satu reservasi berisi $jumlah baris kamar untuk tipe tertentu. */
    private function pesanan(string $tipe, string $masuk, string $keluar, int $jumlah = 1, string $status = 'confirmed', string $statusKamar = 'booked', ?string $batasDp = null): void
    {
        static $n = 0;
        $n++;
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => "Tamu {$n}", 'phone' => '0812000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]);
        $rsv = DB::table('reservations')->insertGetId([
            'reservation_code' => "RSV-U{$n}", 'customer_id' => $pelanggan, 'booking_channel' => 'online', 'status' => $status, 'dp_due_at' => $batasDp,
        ]);
        for ($i = 0; $i < $jumlah; $i++) {
            DB::table('reservation_rooms')->insert([
                'reservation_id' => $rsv, 'room_type_id' => $this->tipe($tipe)->id, 'check_in_date' => $masuk, 'check_out_date' => $keluar,
                'price_per_night' => $this->tipe($tipe)->base_price, 'status' => $statusKamar,
            ]);
        }
    }

    private function sisa(string $tipe, string $masuk, string $keluar): int
    {
        return app(KetersediaanKamar::class)->sisa(CarbonImmutable::parse($masuk), CarbonImmutable::parse($keluar))[$this->tipe($tipe)->id];
    }

    public function test_KT01_pemakaian_dihitung_per_malam_tertinggi(): void
    {
        // Dua pemesanan VIP yang tidak beririsan satu sama lain hanya memakai 1 kamar per malam
        $this->pesanan('VIP', '2026-10-06', '2026-10-07');
        $this->pesanan('VIP', '2026-10-08', '2026-10-09');
        $this->assertSame(1, $this->sisa('VIP', '2026-10-06', '2026-10-09'));

        // Malam 7 Okt masih kosong; check-out 7 Okt tidak memakai malam 7 Okt
        $this->assertSame(2, $this->sisa('VIP', '2026-10-07', '2026-10-08'));

        $this->pesanan('VIP', '2026-10-08', '2026-10-10');
        $this->assertSame(0, $this->sisa('VIP', '2026-10-06', '2026-10-09'));
    }

    public function test_KT02_hanya_pemesanan_yang_menahan_kamar_dihitung(): void
    {
        $this->pesanan('FAM', '2026-10-06', '2026-10-08', 1, 'cancelled', 'cancelled');
        $this->pesanan('FAM', '2026-10-06', '2026-10-08', 1, 'checked_out', 'checked_out');
        $this->pesanan('FAM', '2026-10-06', '2026-10-08', 1, 'confirmed', 'moved');
        $this->pesanan('FAM', '2026-10-06', '2026-10-08', 1, 'pending_payment', 'booked', '2026-10-06 09:00:00'); // batas DP lewat
        $this->assertSame(5, $this->sisa('FAM', '2026-10-06', '2026-10-08'));

        $this->pesanan('FAM', '2026-10-06', '2026-10-08', 1, 'pending_payment', 'booked', '2026-10-07 10:00:00');
        $this->pesanan('FAM', '2026-10-06', '2026-10-08', 1, 'waiting_approval');
        $this->pesanan('FAM', '2026-10-06', '2026-10-08', 1, 'checked_in', 'checked_in');
        $this->assertSame(2, $this->sisa('FAM', '2026-10-06', '2026-10-08'));
    }

    public function test_KT03_kamar_nonaktif_tidak_dihitung(): void
    {
        DB::table('rooms')->where('room_type_id', $this->tipe('VIP')->id)->limit(1)->update(['is_active' => false]);

        $this->assertSame(1, $this->sisa('VIP', '2026-10-06', '2026-10-07'));
    }

    public function test_DK01_tanpa_tanggal_tampil_harga_dan_stepper_nonaktif_urut_termurah(): void
    {
        Livewire::test(DaftarKamar::class)
            ->assertViewHas('tipe', fn ($t) => $t->pluck('code')->all() === ['STD', 'DLX', 'FAM', 'VIP'])
            ->assertSee('Pilih tanggal dulu')
            ->assertSee('Rp360.000')
            ->assertSee('Maks. 4 dewasa + 2 anak')->assertSee('Maks. 2 dewasa + 1 anak')->assertSee('Mulai dari')
            ->assertDontSee('Sisa ')->assertDontSee('data-isi-otomatis', false);
    }

    public function test_DK02_setelah_cek_tampil_sisa_per_jenis_kasur_dan_tipe_habis_paling_bawah(): void
    {
        $this->pesanan('VIP', '2026-10-06', '2026-10-09', 2);
        $this->pesanan('FAM', '2026-10-06', '2026-10-09', 4);
        $this->pesanan('DLX', '2026-10-07', '2026-10-08', 13);

        $lw = Livewire::test(DaftarKamar::class)
            ->call('cek', '2026-10-06', '2026-10-09', '2-0_2-0')
            ->assertSet('masuk', '2026-10-06')->assertSet('tamu', '2-0_2-0')
            ->assertViewHas('tipe', fn ($t) => $t->pluck('code')->all() === ['STD', 'DLX', 'FAM', 'VIP'] && $t->last()->habis)
            // Sisa per jenis kasur di kolom kanan kartu: FAM tinggal 1 (ditonjolkan), VIP habis dan tombolnya Penuh
            ->assertSee('Tinggal 1 kamar!')->assertSee('data-sisa-kasur="0"', false)->assertSee('data-tipe-penuh', false)
            ->assertSee('Paling hemat')->assertSee('Terbaik kami')
            ->assertDontSee('Semua kamar penuh');
        // Sisa tipe dibagi bersama antar jenis kasurnya: FAM tinggal 1 kamar untuk ketiga kasur
        $fam = collect($lw->viewData('tipe'))->firstWhere('code', 'FAM')->barisKasur;
        $this->assertSame([1, 1, 1], array_column($fam, 'maks'));
        $lw->call('aturKamar', 'FAM', 'king', 1)->call('aturKamar', 'FAM', 'twin_queen', 1)->assertSet('pilih', 'FAM.king:1');
        $this->assertSame([1, 0, 0], array_column(collect($lw->viewData('tipe'))->firstWhere('code', 'FAM')->barisKasur, 'maks'));
    }

    public function test_DK06_ketentuan_dari_pengaturan_dan_detail_menampilkan_deskripsi_keunggulan(): void
    {
        Livewire::test(DaftarKamar::class)
            ->assertSee('Kamar nyaman dan bersih untuk istirahat setelah perjalanan dinas atau liburan singkat. Pilihan tepat dengan harga terbaik.')
            ->assertSeeInOrder(['Standard Room', 'Sarapan 2 orang', 'Air panas', 'Kulkas mini', 'Family Room', 'Sarapan hingga 4 orang', '3 pilihan kasur', 'Kulkas mini'])
            ->assertSee('Harga per kamar per malam, termasuk pajak &amp; sarapan', false)->assertDontSee('data-pita-info', false)->assertDontSee('data-poin-kamar', false)
            ->assertSee('Uang muka 50% dibayar online')->assertSee('Check-in mulai 14.00 WITA, check-out paling lambat 12.00 WITA')
            ->assertSee('Pilih tanggal untuk cek ketersediaan')->assertDontSee('data-sisa-kamar', false);

        // Tipe tanpa entri keunggulan tidak menampilkan barisnya
        $this->assertSame([], RoomType::make(['code' => 'SUP'])->keunggulan());

        // Persentase uang muka dan jam mengikuti pengaturan
        DB::table('settings')->insert([
            ['setting_key' => 'dp_min_percent', 'value' => '30', 'type' => 'integer', 'description' => 'Uang muka minimum'],
            ['setting_key' => 'check_in_time', 'value' => '13:00', 'type' => 'time', 'description' => 'Jam check-in'],
            ['setting_key' => 'check_out_time', 'value' => '11:00', 'type' => 'time', 'description' => 'Jam check-out'],
        ]);
        Cache::forget('settings.all');
        Livewire::test(DaftarKamar::class)->assertSee('Uang muka 30% dibayar online')->assertSee('Check-in mulai 13.00 WITA, check-out paling lambat 11.00 WITA')
            ->assertDontSee('Uang muka 50%');

        // Halaman detail: deskripsi lengkap dan keunggulan
        Livewire::test(DetailKamar::class, ['kode' => 'fam'])
            ->assertSee('Kamar luas untuk keluarga atau rombongan kecil, dengan pilihan kasur yang fleksibel. Semua bisa beristirahat dengan nyaman di satu kamar.')
            ->assertSeeInOrder(['Family Room', 'Sarapan hingga 4 orang', '3 pilihan kasur', 'Kulkas mini'])
            ->assertSee('data-keunggulan', false);
    }

    public function test_DK03_semua_penuh_menampilkan_pesan_dan_whatsapp(): void
    {
        foreach (['STD' => 10, 'DLX' => 15, 'FAM' => 5, 'VIP' => 2] as $tipe => $n) {
            $this->pesanan($tipe, '2026-10-06', '2026-10-09', $n);
        }

        Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-06', 'keluar' => '2026-10-09'])
            ->assertViewHas('semuaPenuh', true)
            ->assertSee('Semua kamar penuh untuk 6–9 Okt 2026.')
            ->assertSee('Ubah tanggal')
            ->assertSee('Hubungi via WhatsApp');
    }

    public function test_DK04_tanggal_tidak_valid_menampilkan_pesan_tanpa_ketersediaan(): void
    {
        Livewire::test(DaftarKamar::class)
            ->call('cek', '2026-10-09', '2026-10-06', '')
            ->assertViewHas('ringkasan', fn ($r) => $r['aktif'] === false)
            ->assertSee('Tanggal check-out harus setelah check-in.')
            ->call('cek', '2026-10-01', '2026-10-03', '')
            ->assertSee('Tanggal check-in tidak boleh sebelum hari ini.')
            ->call('cek', '2026-10-06', '2026-11-20', '')
            ->assertSee('Maksimal 30 malam per pemesanan.');
    }

    public function test_DK05_pencarian_dari_beranda_membawa_jumlah_tamu(): void
    {
        // Tautan lama dengan susunan per kamar dijumlahkan
        $this->get('/kamar?masuk=2026-10-06&keluar=2026-10-08&tamu=2-0_1-1')
            ->assertOk()
            ->assertSee('2 kamar · 3 dewasa · 1 anak')->assertSee('2 malam');
        $this->get('/kamar?masuk=2026-10-06&keluar=2026-10-08&tamu=4-2')->assertOk()->assertSee('4 dewasa · 2 anak');
        $this->assertSame(['dewasa' => 2, 'anak' => 0], PencarianInap::total('rusak'));
        $this->assertSame(['dewasa' => 40, 'anak' => 0], PencarianInap::total('40-9'));
    }

    public function test_DK06_tautan_detail_membawa_tanggal_tamu_dan_pilihan(): void
    {
        Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-06', 'keluar' => '2026-10-09', 'tamu' => '2-0_1-1_2-0'])
            ->call('aturKamar', 'STD', 'double', 1)
            ->assertSeeHtml(e(route('kamar.tipe', ['kode' => 'dlx', 'masuk' => '2026-10-06', 'keluar' => '2026-10-09', 'tamu' => '2-0_1-1_2-0', 'pilih' => 'STD.double:1'])));
    }

    public function test_DT01_detail_pilih_kasur_mengisi_bar_ringkasan(): void
    {
        $d = Livewire::test(DetailKamar::class, ['kode' => 'dlx', 'masuk' => '2026-10-06', 'keluar' => '2026-10-09', 'tamu' => '2-0_2-0'])
            ->assertSee('Tersedia untuk 3 malam')->assertDontSee('Sisa 15 kamar')->assertDontSee('data-bar-pilihan', false)
            ->call('pilihKasur', 'DLX', 'twin_double')->call('pilihKasur', 'DLX', 'king')
            ->assertSee('Deluxe · Twin Double × 1')->assertSee('Deluxe · King Bed × 1')
            ->assertSee('Rp2.760.000')->assertSee('Lihat kamar lain')
            ->assertSee('Check-in mulai 14.00 WITA');
        $this->assertStringContainsString('kasur=twin_double%2Cking', $d->viewData('ringkasan')['urlLanjut']);
    }

    public function test_DT02_detail_penuh_menyarankan_tipe_lain_yang_tersedia(): void
    {
        $this->pesanan('VIP', '2026-10-06', '2026-10-09', 2);
        $this->pesanan('FAM', '2026-10-06', '2026-10-09', 5);

        Livewire::test(DetailKamar::class, ['kode' => 'VIP', 'masuk' => '2026-10-06', 'keluar' => '2026-10-09'])
            ->assertSee('Penuh untuk tanggal ini')->assertSee('Masih tersedia di tanggal yang sama:')
            ->assertDontSee('data-tombol-pilih', false)
            ->assertViewHas('lain', fn ($l) => $l->pluck('code')->all() === ['STD', 'DLX', 'FAM']);
    }

    public function test_TM01_isi_otomatis_memilih_kombinasi_termurah_yang_muat(): void
    {
        // 4 dewasa: satu Family Twin Queen (550.000) lebih murah dari dua Standard (720.000)
        $lw = Livewire::test(DaftarKamar::class)->call('cek', '2026-10-06', '2026-10-09', '4-0')->call('isiOtomatis')
            ->assertSet('pilih', 'FAM.twin_queen:1');
        $this->assertSame(1650000.0, $lw->viewData('ringkasan')['total']);
        $this->assertTrue($lw->viewData('ringkasan')['status']['lengkap']);
    }

    public function test_TM04_beberapa_tipe_dalam_satu_pemesanan(): void
    {
        // 4 dewasa 1 anak (2-0 dan 2-1): Standard muat 2 dewasa + 1 anak, jadi termurah 2 Standard, 360.000 × 2 × 3 malam
        $lw = Livewire::test(DaftarKamar::class)->call('cek', '2026-10-06', '2026-10-09', '2-0_2-1')->assertSet('tamu', '2-0_2-1')->call('isiOtomatis');
        $r = $lw->viewData('ringkasan');
        $this->assertSame(2160000.0, $r['total']);
        $this->assertSame(['STD'], collect($r['kamar'])->map(fn ($k) => $k['tipe']->code)->unique()->sort()->values()->all());
        $this->assertTrue($r['status']['lengkap']);
        $this->assertNotNull($r['urlLanjut']);
        $lw->assertSee('Rp2.160.000');
    }

    public function test_TM05_kamar_tidak_cukup_untuk_rombongan(): void
    {
        foreach (['STD' => 10, 'DLX' => 15, 'VIP' => 2] as $tipe => $n) {
            $this->pesanan($tipe, '2026-10-06', '2026-10-07', $n);
        }
        // Tinggal 5 kamar Family (paling banyak 15 dewasa)
        Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-06', 'keluar' => '2026-10-07', 'tamu' => '20-0'])
            ->call('isiOtomatis')->assertSet('pilih', '')
            ->assertDispatched('toast', teks: 'Kamar yang tersedia belum cukup untuk 20 dewasa. Coba tanggal lain atau ajukan pemesanan rombongan.')
            ->assertSee('Ajukan pemesanan instansi');
    }

    public function test_TM02_tamu_melebihi_kamar_terpilih_ditandai_di_rincian(): void
    {
        // 2 kamar × 2 dewasa: satu Standard belum cukup
        $d = Livewire::test(DetailKamar::class, ['kode' => 'std', 'masuk' => '2026-10-06', 'keluar' => '2026-10-09', 'tamu' => '2-0_2-0'])
            ->call('pilihKasur', 'STD', 'double')
            ->assertSee('Masih ada 2 dewasa tanpa kamar. Silakan tambah kamar.');
        $this->assertNull($d->viewData('ringkasan')['urlLanjut']);
        $d->call('pilihKasur', 'STD', 'twin_single');
        $this->assertNotNull($d->viewData('ringkasan')['urlLanjut']);
    }

    public function test_TM03_susunan_tamu_dirapikan(): void
    {
        $this->assertSame([['dewasa' => 2, 'anak' => 0]], PencarianInap::susunan('rusak'));
        $this->assertSame([['dewasa' => 1, 'anak' => 0], ['dewasa' => 10, 'anak' => 0]], PencarianInap::susunan('0-0_12-5'));
        $this->assertCount(PencarianInap::MAKS_KAMAR, PencarianInap::susunan(implode('_', array_fill(0, 15, '1-0'))));

        // Jumlah tamu bawaan (2 dewasa) tidak ditulis ke URL
        Livewire::test(DaftarKamar::class)->call('cek', '2026-10-06', '2026-10-09', '2-0')->assertSet('tamu', '');
    }

    public function test_DT03_kode_tidak_dikenal_atau_tanpa_kamar_aktif_404(): void
    {
        $this->get('/kamar/tidak-ada')->assertNotFound();

        DB::table('rooms')->where('room_type_id', $this->tipe('VIP')->id)->update(['is_active' => false]);
        $this->get('/kamar/vip')->assertNotFound();
        $this->get('/kamar')->assertOk()->assertDontSee('VIP Room');
    }
}
