<?php

namespace Tests\Feature;

use App\Livewire\Tamu\DaftarKamar;
use App\Livewire\Tamu\DetailKamar;
use App\Services\KetersediaanKamar;
use App\Support\PencarianInap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Halaman Kamar untuk tamu: daftar tipe, detail, dan cek ketersediaan.
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

    public function test_DK01_tanpa_tanggal_tampil_harga_mulai_dari_urut_termurah(): void
    {
        Livewire::test(DaftarKamar::class)
            ->assertViewHas('status', [])
            ->assertViewHas('tipe', fn ($t) => $t->pluck('code')->all() === ['STD', 'DLX', 'FAM', 'VIP'])
            ->assertSee('Mulai dari')
            ->assertSee('Rp360.000')
            ->assertSee('Maks. 4 orang')
            ->assertDontSee('Tersedia');
    }

    public function test_DK02_setelah_cek_tampil_tersedia_kurang_dan_penuh(): void
    {
        $this->pesanan('VIP', '2026-10-06', '2026-10-09', 2);
        $this->pesanan('FAM', '2026-10-06', '2026-10-09', 4);
        $this->pesanan('DLX', '2026-10-07', '2026-10-08', 13);

        Livewire::test(DaftarKamar::class)
            ->call('cek', '2026-10-06', '2026-10-09', '2-0_2-0')
            ->assertSet('masuk', '2026-10-06')
            ->assertViewHas('status', fn ($s) => $s[$this->tipe('STD')->id]['jenis'] === 'tersedia'
                && $s[$this->tipe('DLX')->id] === ['jenis' => 'tersedia', 'sisa' => 2, 'muat' => 2, 'dipesan' => 2, 'kamarDipesan' => [0, 1], 'total' => 2760000.0]
                && $s[$this->tipe('FAM')->id]['jenis'] === 'kurang' && $s[$this->tipe('FAM')->id]['dipesan'] === 1
                && $s[$this->tipe('VIP')->id]['jenis'] === 'penuh')
            ->assertSee('Rp2.760.000')
            ->assertSee('untuk 2 kamar · 3 malam')
            ->assertSee('Tersisa 2 kamar')
            ->assertSee('Hanya tersedia 1 kamar untuk tanggal ini')
            ->assertSee('Pesan 1 kamar')
            ->assertSee('Penuh untuk tanggal ini')
            ->assertDontSee('Semua kamar penuh');
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
            ->assertViewHas('status', [])
            ->assertSee('Tanggal check-out harus setelah check-in.')
            ->call('cek', '2026-10-01', '2026-10-03', '')
            ->assertSee('Tanggal check-in tidak boleh sebelum hari ini.')
            ->call('cek', '2026-10-06', '2026-11-20', '')
            ->assertSee('Maksimal 30 malam per pemesanan.');
    }

    public function test_DK05_pencarian_dari_beranda_membawa_susunan_tamu(): void
    {
        $this->get('/kamar?masuk=2026-10-06&keluar=2026-10-08&tamu=2-0_1-1')
            ->assertOk()
            ->assertSee('2 malam · 2 kamar · 3 dewasa, 1 anak')
            ->assertSee('Rp1.440.000'); // STD 360.000 × 2 malam × 2 kamar
    }

    public function test_DK06_tautan_pesan_membawa_tanggal_dan_susunan_tamu(): void
    {
        $this->pesanan('FAM', '2026-10-06', '2026-10-09', 4);

        // FAM tinggal 1 kamar: tautan pesan hanya membawa kamar pertama (2 dewasa = susunan bawaan)
        Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-06', 'keluar' => '2026-10-09', 'tamu' => '2-0_1-1_2-0'])
            ->assertSeeHtml(e(route('kamar.pesan', 'std').'?masuk=2026-10-06&keluar=2026-10-09&tamu=2-0_1-1_2-0'))
            ->assertSeeHtml(e(route('kamar.pesan', 'fam').'?masuk=2026-10-06&keluar=2026-10-09').'"')
            ->assertSeeHtml(e(route('kamar.tipe', 'dlx').'?masuk=2026-10-06&keluar=2026-10-09&tamu=2-0_1-1_2-0'));
    }

    public function test_DT01_detail_menampilkan_ringkasan_harga_dan_uang_muka(): void
    {
        Livewire::test(DetailKamar::class, ['kode' => 'dlx', 'masuk' => '2026-10-06', 'keluar' => '2026-10-09', 'tamu' => '2-0_2-0'])
            ->assertSee('Deluxe Room × 2 kamar × 3 malam')
            ->assertSee('Rp2.760.000')
            ->assertSee('Uang muka 50%')
            ->assertSee('Rp1.380.000')
            ->assertSee('Sisa dibayar saat check-in')
            ->assertSee('Pesan Sekarang')
            ->assertSee('Belum dikenakan biaya. Pembayaran di langkah berikutnya.')
            ->assertSee('Check-in mulai 14.00 WITA');
    }

    public function test_DT02_detail_penuh_menyarankan_tipe_lain_yang_tersedia(): void
    {
        $this->pesanan('VIP', '2026-10-06', '2026-10-09', 2);
        $this->pesanan('FAM', '2026-10-06', '2026-10-09', 5);

        Livewire::test(DetailKamar::class, ['kode' => 'VIP', 'masuk' => '2026-10-06', 'keluar' => '2026-10-09'])
            ->assertSee('Penuh untuk tanggal ini')
            ->assertDontSee('Pesan Sekarang')
            ->assertViewHas('saranLain', fn ($s) => $s->pluck('code')->all() === ['STD', 'DLX'])
            ->assertViewHas('lain', fn ($l) => $l->pluck('code')->all() === ['STD', 'DLX', 'FAM']);
    }

    public function test_TM01_satu_kamar_empat_orang_hanya_menampilkan_tipe_yang_muat(): void
    {
        // Hanya FAM berkapasitas 4; tipe lain disembunyikan (bisa ditampilkan lagi) dan FAM tampil paling atas
        Livewire::test(DaftarKamar::class)
            ->call('cek', '2026-10-06', '2026-10-09', '4-0')
            ->assertViewHas('status', fn ($s) => collect($s)->where('jenis', 'tidak-muat')->count() === 3
                && $s[$this->tipe('FAM')->id]['jenis'] === 'tersedia')
            ->assertViewHas('tipe', fn ($t) => $t->first()->code === 'FAM')
            ->assertViewHas('tersembunyi', 3)
            ->assertViewHas('kombinasi', null)
            ->assertSee('3 tipe kamar disembunyikan karena tidak muat untuk 4 orang dalam satu kamar.')
            ->assertSee('x-show="lihatSemua"', false)
            ->assertDontSeeHtml(e(route('kamar.pesan', 'std')));
    }

    public function test_TM04_beberapa_kamar_berbeda_isi_mendapat_rekomendasi_kombinasi(): void
    {
        // Kamar 1: 2 orang, kamar 2: 3 orang. STD hanya muat kamar 1; kombinasi termurah FAM + STD
        Livewire::test(DaftarKamar::class)
            ->call('cek', '2026-10-06', '2026-10-09', '2-0_2-1')
            ->assertSet('tamu', '2-0_2-1')
            ->assertViewHas('status', fn ($s) => $s[$this->tipe('STD')->id]['jenis'] === 'sebagian'
                && $s[$this->tipe('STD')->id]['kamarDipesan'] === [0]
                && $s[$this->tipe('FAM')->id]['jenis'] === 'tersedia')
            ->assertViewHas('kombinasi', fn ($k) => $k['lengkap'] && $k['total'] === 2730000.0 // (360.000 + 550.000) × 3 malam
                && collect($k['kamar'])->map(fn ($r) => $r['tipe']->code)->all() === ['STD', 'FAM'])
            ->assertSee('Rekomendasi untuk 2 kamar')
            ->assertSee('1 Standard Room + 1 Family Room')
            ->assertSee('Rp2.730.000')
            ->assertSee('Muat untuk 1 dari 2 kamar')
            ->assertSee('Kamar 2 (3 orang) butuh tipe berkapasitas lebih besar.')
            ->assertSeeHtml(e(route('kamar.pesan', 'std').'?masuk=2026-10-06&keluar=2026-10-09').'"')
            ->assertSeeHtml(e(route('kamar.pesan', 'fam').'?masuk=2026-10-06&keluar=2026-10-09&tamu=2-0_2-1'));
    }

    public function test_TM05_rombongan_tersebar_ke_beberapa_tipe_dan_kamar_kurang(): void
    {
        // 9 kamar berisi 2 orang: STD cukup (10 kamar, kamar 103 sedang diperbaiki jadi tidak dijual);
        // setelah STD tinggal 2 kamar, sisanya ke DLX (termurah berikutnya)
        $dua = implode('_', array_fill(0, 9, '2-0'));
        Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-06', 'keluar' => '2026-10-07', 'tamu' => $dua])
            ->assertViewHas('kombinasi', fn ($k) => $k === null)
            ->assertSee('Memesan 9 kamar untuk rombongan atau instansi?');

        $this->pesanan('STD', '2026-10-06', '2026-10-07', 7);
        Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-06', 'keluar' => '2026-10-07', 'tamu' => $dua])
            ->assertViewHas('kombinasi', fn ($k) => $k['lengkap'] && collect($k['kelompok'])->map(fn ($g) => $g['tipe']->code.':'.$g['jumlah'])->all() === ['STD:2', 'DLX:7'])
            ->assertSee('2 Standard Room + 7 Deluxe Room');

        // Empat orang per kamar untuk 7 kamar: FAM hanya 5 kamar
        $empat = implode('_', array_fill(0, 7, '4-0'));
        Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-06', 'keluar' => '2026-10-07', 'tamu' => $empat])
            ->assertViewHas('kombinasi', fn ($k) => ! $k['lengkap'] && $k['kurang'] === 2)
            ->assertSee('Kamar yang tersedia belum cukup untuk 7 kamar.')
            ->assertSee('Paling banyak 5 kamar bisa dipesan untuk susunan tamu ini.')
            ->assertSee('Ajukan pemesanan rombongan');
    }

    public function test_TM02_detail_tidak_muat_menyarankan_tipe_yang_muat(): void
    {
        Livewire::test(DetailKamar::class, ['kode' => 'std', 'masuk' => '2026-10-06', 'keluar' => '2026-10-09', 'tamu' => '4-0'])
            ->assertSee('Standard Room tidak muat untuk 4 orang dalam satu kamar.')
            ->assertDontSee('Pesan Sekarang')
            ->assertViewHas('saranLain', fn ($s) => $s->pluck('code')->all() === ['FAM']);
    }

    public function test_TM03_susunan_tamu_dirapikan(): void
    {
        $this->assertSame([['dewasa' => 2, 'anak' => 0]], PencarianInap::susunan('rusak'));
        $this->assertSame([['dewasa' => 1, 'anak' => 0], ['dewasa' => 10, 'anak' => 0]], PencarianInap::susunan('0-0_12-5'));
        $this->assertCount(PencarianInap::MAKS_KAMAR, PencarianInap::susunan(implode('_', array_fill(0, 15, '1-0'))));

        // Susunan bawaan tidak ditulis ke URL
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
