<?php

namespace Tests\Feature;

use App\Livewire\Tamu\DaftarKamar;
use App\Livewire\Tamu\DetailKamar;
use App\Livewire\Tamu\PesanKamar;
use App\Models\Reservation;
use App\Models\ReservationLog;
use App\Models\Service;
use App\Services\Reservasi\ReservasiService;
use App\Support\Pelanggan;
use App\Support\PencarianInap;
use App\Support\PilihanKamar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Satu pemesanan berisi beberapa tipe dan jenis kasur: stepper per jenis kasur di /kamar, bar ringkasan, rincian pembagian
 * tamu, tautan LANJUTKAN, halaman pemesan, dan reservasi dengan kamar fisik sesuai jenis kasur.
 * Family: King 210/213, Twin Queen 211/214, Triple Single 212. VIP: King 215/216.
 */
class PesanBanyakKamarTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const CARI = ['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-2_2-0'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        Notification::fake();
        config(['services.midtrans.server_key' => null, 'services.midtrans.client_key' => null, 'services.fonnte.token' => null]);
        Service::create(['code' => 'EXB01', 'name' => 'Extra Bed', 'description' => 'Kasur tambahan, maksimal 1 per kamar. Belum termasuk sarapan.',
            'category' => 'amenity', 'department' => 'housekeeping', 'unit' => 'malam', 'price' => 100000, 'is_active' => true]);
    }

    private function daftar(array $query = self::CARI)
    {
        return Livewire::withQueryParams($query)->test(DaftarKamar::class);
    }

    private function baris($lw, string $kode): array
    {
        return collect($lw->viewData('tipe'))->firstWhere('code', $kode)->barisKasur;
    }

    public function test_1_baris_per_jenis_kasur_dengan_sisa_dan_batas_stepper(): void
    {
        $lw = $this->daftar();
        $fam = collect($this->baris($lw, 'FAM'))->keyBy('kode');
        $this->assertSame(['king', 'twin_queen', 'triple_single'], $fam->keys()->all());
        $this->assertSame([2, 2, 1], $fam->pluck('sisa')->values()->all());
        $this->assertSame([2, 2, 1], $fam->pluck('maks')->values()->all());
        $this->assertSame('2 dewasa + 2 anak', $fam['king']['kapasitas']);
        $this->assertSame('4 dewasa + 2 anak', $fam['twin_queen']['kapasitas']);

        // Jenis kasur dipilih di halaman detail; kartu /kamar hanya menautkan ke sana
        $lw->assertSee('Ketersediaan kamar')->assertDontSee('data-stepper', false)->assertSee('data-lihat-detail', false)->assertSee('Pilih kamar')
            ->assertSee('Tinggal 2 kamar!')->assertSee('Tinggal 1 kamar!')->assertDontSee('Sisa 2')->assertDontSee('Sisa 3')->assertDontSee('Sisa 4')->assertSee('Lihat detail &amp; foto', false)->assertSee('4 dewasa · 2 anak');

        // Stepper tidak melewati sisa kasur
        $lw->call('aturKamar', 'FAM', 'triple_single', 5)->assertSet('pilih', 'FAM.triple_single:1');
        $this->assertSame(1, collect($this->baris($lw, 'FAM'))->firstWhere('kode', 'triple_single')['maks']);

        // Tanpa tanggal: stepper nonaktif dan tombol Pilih tanggal dulu
        $tanpa = $this->daftar(['tamu' => '4-2']);
        $tanpa->assertSee('Pilih tanggal dulu')->call('aturKamar', 'FAM', 'king', 1)->assertSet('pilih', '');
        $this->assertSame(0, collect($this->baris($tanpa, 'FAM'))->firstWhere('kode', 'king')['maks']);
    }

    public function test_2_pilih_dua_tipe_menampilkan_bar_dengan_total(): void
    {
        $lw = $this->daftar()->call('aturKamar', 'FAM', 'king', 1)->call('aturKamar', 'VIP', 'king', 1)
            ->assertSet('pilih', 'FAM.king:1,VIP.king:1');
        $r = $lw->viewData('ringkasan');
        $this->assertSame([2, 1200000.0], [$r['jumlah'], $r['total']]);
        $lw->assertSee('2 kamar · 1 malam · 26–27 Okt')->assertSee('Rp1.200.000')
            ->assertSee('Family · King Bed × 1')->assertSee('VIP · King Bed × 1')->assertSee('Lanjutkan pemesanan →');
    }

    public function test_3_rincian_membagi_tamu_otomatis_dan_membatasi_kapasitas(): void
    {
        $lw = $this->daftar()->call('aturKamar', 'FAM', 'king', 1)->call('aturKamar', 'VIP', 'king', 1);
        $r = $lw->viewData('ringkasan');
        $this->assertSame([['dewasa' => 2, 'anak' => 2], ['dewasa' => 2, 'anak' => 0]], array_column($r['kamar'], 'bagi'));
        $this->assertTrue($r['status']['lengkap']);
        $lw->assertSee('Semua tamu (4 dewasa · 2 anak) sudah mendapat kamar.')->assertSee('Maks. 2 dewasa + 2 anak');

        // Pembagian hanya ditampilkan sebagai teks, tanpa dropdown
        $this->assertSame(['2 dewasa · 2 anak', '2 dewasa'], array_column($r['kamar'], 'teksBagi'));
        $lw->assertDontSee('aturBagi', false)->assertSee('Tamu dibagi otomatis sesuai kapasitas kamar');

        // Hanya satu kamar Family King untuk 4 dewasa 2 anak: tamu tersisa
        $satu = $this->daftar()->call('aturKamar', 'FAM', 'king', 1);
        $this->assertSame('Masih ada 2 dewasa tanpa kamar. Silakan tambah kamar.', $satu->viewData('ringkasan')['status']['pesan']);
        $this->assertNull($satu->viewData('ringkasan')['urlLanjut']);
    }

    public function test_4_lanjutkan_membawa_tamu_pilihan_dan_kasur_ke_halaman_pemesan(): void
    {
        $r = $this->daftar()->call('aturKamar', 'FAM', 'king', 1)->call('aturKamar', 'VIP', 'king', 1)->viewData('ringkasan');
        parse_str(parse_url($r['urlLanjut'], PHP_URL_QUERY), $q);
        $this->assertStringContainsString('/kamar/fam/pesan', $r['urlLanjut']);
        $this->assertSame(['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-2_2-0', 'pilihan' => 'FAM,VIP', 'kasur' => 'king,king'], $q);

        $pesan = Livewire::withQueryParams($q)->test(PesanKamar::class, ['kode' => 'fam']);
        $this->assertNull($pesan->viewData('galat'));
        $this->assertSame(1200000.0, $pesan->viewData('total'));
        $this->assertSame(600000.0, (float) $pesan->viewData('uangMuka'));
        $pesan->assertSee('Langkah 2 dari 3 · Data pemesan dan pembayaran')->assertSee('Family Room · King Bed × 1')->assertSee('VIP Room / Suite · King Bed × 1')
            ->assertSee('Bayar Rp600.000');
    }

    public function test_5_simpan_membuat_satu_reservasi_dengan_kamar_sesuai_kasur(): void
    {
        $q = ['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-2_2-0', 'pilihan' => 'FAM,VIP', 'kasur' => 'king,king'];
        Livewire::withQueryParams($q)->test(PesanKamar::class, ['kode' => 'fam'])
            ->set('nama', 'Rina Wulandari')->set('hp', '081234567001')->set('metode', 'bca')
            ->call('pesan')->assertHasNoErrors();

        $r = Reservation::with('rooms.room', 'rooms.roomType')->latest('id')->first();
        $this->assertSame(1, Reservation::count());
        $this->assertSame([['FAM', 'king'], ['VIP', 'king']], $r->rooms->map(fn ($b) => [$b->roomType->code, $b->room->bed_type])->all());
        $this->assertSame('Pembagian tamu: Kamar 1 Family Room · King Bed: 2 dewasa 2 anak; Kamar 2 VIP Room / Suite · King Bed: 2 dewasa',
            ReservationLog::where('reservation_id', $r->id)->where('status', 'pending_payment')->value('note'));
        $this->assertSame(['2 dewasa · 2 anak', '2 dewasa'], $r->pembagianTamu());
    }

    public function test_6_tautan_lama_dengan_susunan_per_kamar_tetap_terbuka(): void
    {
        $this->get(route('kamar', ['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-0_4-0']))->assertOk()->assertSee('6 dewasa');
        $this->assertSame(['dewasa' => 6, 'anak' => 0], PencarianInap::total('2-0_4-0'));
        $this->get(route('kamar.tipe', ['kode' => 'fam', 'masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-0_4-0']))->assertOk();
    }

    public function test_9_susunan_per_kamar_dari_pencarian_jadi_pembagian_awal_dan_isi_otomatis(): void
    {
        // Pencarian 2 kamar: kamar 1 dua dewasa, kamar 2 dua dewasa dua anak
        $lw = $this->daftar(['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-0_2-2'])
            ->assertSee('2 kamar · 4 dewasa · 2 anak')->assertSee('Tambah kamar')->assertDontSee('Jumlah kamar diatur di setiap pilihan kasur');

        // Isi otomatis per kamar: Standard untuk 2 dewasa, Family King untuk 2 dewasa 2 anak
        $lw->call('isiOtomatis');
        $r = $lw->viewData('ringkasan');
        $this->assertSame(['FAM.king', 'STD.twin_single'], collect($r['kamar'])->map(fn ($k) => $k['tipe']->code.'.'.$k['kasur'])->sort()->values()->all());
        $this->assertSame(910000.0, $r['total']);

        // Pembagian awal mengikuti susunan: kelompok terbesar ke kamar terbesar
        $bagi = collect($r['kamar'])->mapWithKeys(fn ($k) => [$k['tipe']->code => $k['nilai']])->all();
        $this->assertSame(['FAM' => '2-2', 'STD' => '2-0'], ['FAM' => $bagi['FAM'], 'STD' => $bagi['STD']]);
        $this->assertTrue($r['status']['lengkap']);

        // Jumlah kamar dipilih berbeda dari pencarian: dibagi otomatis; Twin Queen muat 4 dewasa + 2 anak, King hanya 2 dewasa
        $satu = $this->daftar(['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-0_2-2']);
        $this->assertSame('Semua tamu (4 dewasa · 2 anak) sudah mendapat kamar.', $satu->call('aturKamar', 'FAM', 'twin_queen', 1)->viewData('ringkasan')['status']['pesan']);
        $this->assertSame('Masih ada 2 dewasa tanpa kamar. Silakan tambah kamar.', $this->daftar(['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '2-0_2-2'])
            ->call('aturKamar', 'FAM', 'king', 1)->viewData('ringkasan')['status']['pesan']);
    }

    public function test_8_kasur_habis_menyebut_nomor_kamar_di_halaman_pemesan_dan_server(): void
    {
        // Family Triple Single hanya satu kamar (212): kamar ke-2 tidak kebagian
        $q = ['masuk' => '2026-10-26', 'keluar' => '2026-10-27', 'tamu' => '3-0_3-0', 'pilihan' => 'FAM,FAM', 'kasur' => 'triple_single,triple_single'];
        $this->assertSame('Maaf, Family · Triple Single baru saja habis. Silakan pilih jenis kasur lain untuk Kamar 2.',
            Livewire::withQueryParams($q)->test(PesanKamar::class, ['kode' => 'fam'])->viewData('galat'));

        // Server (dikunci saat simpan): pesan yang sama dari ReservasiService
        $pemesan = Pelanggan::dariIsian(['name' => 'Rina Wulandari', 'phone' => '081234567002'], null, 'hp');
        try {
            app(ReservasiService::class)->buatOnline($pemesan, CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-27'),
                [3 => 2], null, [], [3 => ['triple_single', 'triple_single']], [3 => [['dewasa' => 3, 'anak' => 0], ['dewasa' => 3, 'anak' => 0]]]);
            $this->fail('Seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertSame('Maaf, Family · Triple Single baru saja habis. Silakan pilih jenis kasur lain untuk Kamar 2.', $e->errors()['kamar'][0]);
        }
        $this->assertSame(0, Reservation::count());
    }

    public function test_7_isi_otomatis_termurah_dan_pilih_di_halaman_detail(): void
    {
        // 2 dewasa 2 anak + 2 dewasa: Family King (2+2) dan Standard (2 dewasa) termurah
        $lw = $this->daftar()->call('isiOtomatis');
        $this->assertSame(910000.0, $lw->viewData('ringkasan')['total']);

        // 4 dewasa 2 anak dalam satu kamar: hanya Family Twin Queen yang muat
        $satu = $this->daftar(['tamu' => '4-2'] + self::CARI)->call('isiOtomatis');
        $this->assertSame('FAM.twin_queen:1', $satu->get('pilih'));
        $this->assertSame(550000.0, $satu->viewData('ringkasan')['total']);
        $this->assertTrue($lw->viewData('ringkasan')['status']['lengkap']);

        // Detail: tombol Pilih menambah 1 tanpa pindah halaman
        Livewire::withQueryParams(self::CARI)->test(DetailKamar::class, ['kode' => 'fam'])
            ->call('pilihKasur', 'FAM', 'king')->assertSet('pilih', 'FAM.king:1')->assertDispatched('toast', teks: 'Family · King Bed ditambahkan')
            ->call('pilihKasur', 'FAM', 'king')->assertSet('pilih', 'FAM.king:2')
            ->call('pilihKasur', 'FAM', 'king')->assertSet('pilih', 'FAM.king:2')
            ->assertSee('Lihat kamar lain')->assertSee('Lanjutkan pemesanan →');
        $this->assertSame([['kode' => 'FAM', 'kasur' => 'king', 'jumlah' => 2]], PilihanKamar::baca('fam.king:1,FAM.king:1,XX.bukan:3'));
    }

    public function test_10_detail_stepper_kasur_dan_panel_tanpa_ringkasan(): void
    {
        $lw = Livewire::withQueryParams(self::CARI)->test(DetailKamar::class, ['kode' => 'fam'])
            // Belum dipilih: tombol Pilih; panel kanan berlabel Tamu dengan total saja
            ->assertSee('data-tombol-pilih', false)->assertDontSee('data-stepper', false)
            ->assertSee('Tamu')->assertSee('4 dewasa · 2 anak')->assertDontSee('Kamar dan tamu')
            ->call('pilihKasur', 'FAM', 'triple_single')->assertSet('pilih', 'FAM.triple_single:1')
            // Sisa Triple Single 1: + nonaktif dengan keterangan, Pilih berganti stepper
            ->assertSee('data-stepper', false)->assertSee('title="Tinggal 1 kamar"', false)
            ->assertDontSee('Lanjutkan pemesanan dari bar')->assertDontSee('data-ringkasan-kasur', false);

        $lw->call('kurangiKasur', 'FAM', 'triple_single')->assertSet('pilih', '')
            ->assertDispatched('toast', teks: 'Family · Triple Single dikurangi')
            ->assertDontSee('data-stepper', false);
        // Kurangi saat belum dipilih: tidak ada perubahan
        $lw->call('kurangiKasur', 'FAM', 'king')->assertSet('pilih', '');
    }
}
