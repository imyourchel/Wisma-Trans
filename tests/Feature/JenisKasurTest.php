<?php

namespace Tests\Feature;

use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Livewire\Tamu\DaftarKamar;
use App\Livewire\Tamu\DetailKamar;
use App\Livewire\Tamu\PesanKamar;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Service;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\ReservasiService;
use App\Support\Pelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Pilihan jenis kasur saat memesan: sisa per jenis kasur, kartu di detail kamar, nomor kamar fisik yang menjamin jenis
 * kasur sejak reservasi dibuat, ubah tanggal yang mempertahankan jenis kasur, dan tampilan di sisi staf.
 * Standard: Twin Single 101/104/107/110, Double Bed 102/105/108, Queen Bed 103 (perbaikan)/106/109.
 */
class JenisKasurTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const CARI = ['masuk' => '2026-10-20', 'keluar' => '2026-10-22', 'tamu' => '2-0'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        Notification::fake();
        config(['services.midtrans.server_key' => null, 'services.midtrans.client_key' => null, 'services.fonnte.token' => null]);
    }

    private function tgl(string $t): CarbonImmutable
    {
        return CarbonImmutable::parse($t);
    }

    private function detail(array $cari = self::CARI)
    {
        return Livewire::test(DetailKamar::class, ['kode' => 'std'] + $cari);
    }

    /** Kamar fisik dipakai tamu langsung (walk-in) pada rentang itu. */
    private function pakai(array $nomor, string $masuk = '2026-10-20', string $keluar = '2026-10-22'): Reservation
    {
        $tamu = Pelanggan::dariIsian(['name' => 'Tamu Lain', 'phone' => '0812'.random_int(10000000, 99999999)], null, 'hp');

        return app(ReservasiService::class)->buatLangsung($tamu, $this->tgl($masuk), $this->tgl($keluar),
            array_map(fn ($n) => $this->kamar($n)->id, $nomor), $this->akun('fo'));
    }

    private function sisaKartu($detail): array
    {
        return collect($detail->viewData('kartuKasur'))->mapWithKeys(fn ($k) => [$k['kode'] => $k['sisa']])->all();
    }

    public function test_E1_detail_standard_menampilkan_tiga_kartu_jenis_kasur_dengan_sisa(): void
    {
        $d = $this->detail()
            ->assertSee('Pilih jenis kasur')
            ->assertSeeInOrder(['Twin Single', '2 kasur single', 'Double Bed', '1 kasur double', 'Queen Bed', '1 kasur queen'])
            ->assertSee('Maks. 2 dewasa + 1 anak')->assertSee('Termasuk sarapan 2 orang')
            ->assertSee('Tersedia')->assertDontSee('Tinggal 2 kamar!') // sisa tetap dihitung (Queen: 103 perbaikan), tidak ditampilkan
            ->assertSee('Pilih jenis kasur')->assertDontSee('Pesan Sekarang');
        // Kamar 103 (Queen) sedang perbaikan
        $this->assertSame(['twin_single' => 4, 'double' => 3, 'queen' => 2], $this->sisaKartu($d));

        // Sama dengan layanan ketersediaan
        $this->assertSame(['twin_single' => 4, 'double' => 3, 'queen' => 2],
            app(KetersediaanKamar::class)->sisaKasur($this->tgl('2026-10-20'), $this->tgl('2026-10-22'), [$this->tipe('STD')->id])[$this->tipe('STD')->id]);

        // Tanggal belum dipilih: kartu tetap tampil tanpa sisa, tombolnya mengarah ke pemilih tanggal
        $tanpa = $this->detail([])->assertSee('Twin Single')->assertSee('Pilih tanggal dulu')->assertDontSee('Sisa ');
        $this->assertSame(['twin_single' => null, 'double' => null, 'queen' => null], $this->sisaKartu($tanpa));

        // Daftar kamar: satu baris per jenis kasur
        $this->get(route('kamar'))->assertOk()->assertSeeInOrder(['Standard Room', 'Twin Single', 'Double Bed', 'Queen Bed']);
    }

    public function test_E2_jenis_kasur_yang_terisi_habis_yang_lain_tetap_bisa_dipilih(): void
    {
        $this->pakai(['101', '104', '107', '110']);

        $d = $this->detail()->assertSee('Habis untuk tanggal ini');
        $this->assertSame(['twin_single' => 0, 'double' => 3, 'queen' => 2], $this->sisaKartu($d));

        // Kasur yang habis tidak bisa dipilih; Double Bed bisa
        $d->call('pilihKasur', 'STD', 'twin_single')->assertSet('pilih', '')
            ->call('pilihKasur', 'STD', 'double')->assertSet('pilih', 'STD.double:1')
            ->assertSee('Standard · Double Bed × 1')->assertSee('Lanjutkan pemesanan →');
        $this->assertStringContainsString('kasur=double', $d->viewData('ringkasan')['urlLanjut']);
    }

    public function test_E3_reservasi_tanpa_nomor_kamar_mengurangi_sisa_semua_jenis_kasur(): void
    {
        // 7 kamar Standard dipesan tanpa nomor kamar (misalnya pengajuan instansi): sisa tipe 9 - 7 = 2
        $r = Reservation::create(['reservation_code' => 'RSV-0901', 'customer_id' => Pelanggan::dariIsian(['name' => 'Dinas Uji', 'phone' => '081311110000'], null, 'hp')->id,
            'service_billing_scheme' => 'satu_nota', 'booking_channel' => 'online', 'status' => 'confirmed']);
        for ($i = 0; $i < 7; $i++) {
            ReservationRoom::create(['reservation_id' => $r->id, 'room_type_id' => $this->tipe('STD')->id, 'check_in_date' => '2026-10-19',
                'check_out_date' => '2026-10-23', 'price_per_night' => 360000, 'status' => 'booked']);
        }

        $this->assertSame(['twin_single' => 2, 'double' => 2, 'queen' => 2], $this->sisaKartu($this->detail()->assertDontSee('Tinggal 2 kamar!')));
    }

    public function test_E4_pesan_double_bed_mengisi_nomor_kamar_berjenis_double(): void
    {
        $url = $this->detail()->call('pilihKasur', 'STD', 'double')->viewData('ringkasan')['urlLanjut'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('double', $q['kasur']);

        Livewire::withQueryParams($q)->test(PesanKamar::class, ['kode' => 'std'])
            ->assertSee('Standard Room · Double Bed × 1')
            ->set('nama', 'Rizky Pratama')->set('hp', '081299110022')->set('metode', 'bca')
            ->call('pesan')->assertHasNoErrors();

        $baris = Reservation::sole()->rooms()->with('room')->sole();
        $this->assertSame(['102', 'double'], [$baris->room->room_number, $baris->room->bed_type]);
        $r = Reservation::sole();
        $this->assertSame('Kamar 102 · Double Bed, 2 malam', $r->stayInvoice->items()->sole()->description);
        $this->assertStringContainsString('Kamar 102 · Standard Room · Double Bed', app(NotifikasiReservasi::class)->teksDibuat($r));

        // Kamar 102 sudah dipakai: sisa Double Bed berkurang
        $this->assertSame(2, $this->sisaKartu($this->detail())['double']);
    }

    public function test_E5_dua_pemesanan_untuk_kamar_terakhir_hanya_satu_berhasil(): void
    {
        $this->pakai(['102', '105']); // tinggal 108
        $layanan = app(ReservasiService::class);
        $pesan = fn (string $hp) => $layanan->buatOnline(Pelanggan::dariIsian(['name' => 'Tamu Uji', 'phone' => $hp], null, 'hp'),
            $this->tgl('2026-10-20'), $this->tgl('2026-10-22'), [$this->tipe('STD')->id => 1], null, [], [$this->tipe('STD')->id => ['double']]);

        // Tipe dikunci lockForUpdate di dalam transaksi, jadi pemesanan bersamaan berjalan bergiliran
        $pertama = $pesan('081200000001');
        $this->assertSame('108', $pertama->rooms()->sole()->room->room_number);
        try {
            $pesan('081200000002');
            $this->fail('Pemesanan kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('Maaf, Standard · Double Bed baru saja habis. Silakan pilih jenis kasur lain untuk Kamar 1.', $e->errors()['kamar'][0]);
        }
        $this->assertSame(1, Reservation::where('booking_channel', 'online')->count());

        // Halaman pemesan juga menolak sebelum menyimpan
        Livewire::withQueryParams(self::CARI + ['kasur' => 'double'])->test(PesanKamar::class, ['kode' => 'std'])
            ->assertSee('Maaf, Standard · Double Bed baru saja habis. Silakan pilih jenis kasur lain untuk Kamar 1.');
    }

    public function test_E6_ubah_tanggal_mempertahankan_jenis_kasur(): void
    {
        $layanan = app(ReservasiService::class);
        $r = $layanan->buatOnline(Pelanggan::dariIsian(['name' => 'Tamu Uji', 'phone' => '081200000003'], null, 'hp'),
            $this->tgl('2026-10-20'), $this->tgl('2026-10-22'), [$this->tipe('STD')->id => 1], null, [], [$this->tipe('STD')->id => ['double']]);
        $r->update(['status' => 'confirmed']);
        $this->assertSame('102', $r->rooms()->sole()->room->room_number);

        // Kamar 102 dipakai tamu lain pada tanggal baru: pindah ke kamar Double Bed lain
        $this->pakai(['102'], '2026-10-24', '2026-10-26');
        $layanan->ubahTanggal($r->fresh(), $this->tgl('2026-10-24'), $this->tgl('2026-10-26'), null);
        $baris = $r->rooms()->sole();
        $this->assertSame(['105', 'double', '2026-10-24'], [$baris->room->room_number, $baris->room->bed_type, $baris->check_in_date->toDateString()]);
        $this->assertStringContainsString('kamar 102 diganti 105 (jenis kasur sama)', $r->logs()->latest('id')->first()->note);

        // Semua Double Bed terisi pada tanggal lain: ditolak dengan pesan jelas, tanggal tidak berubah
        $this->pakai(['102', '105', '108'], '2026-10-28', '2026-10-30');
        try {
            $layanan->ubahTanggal($r->fresh(), $this->tgl('2026-10-28'), $this->tgl('2026-10-30'), null);
            $this->fail('Seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('Tidak ada kamar Standard Room Double Bed yang kosong pada tanggal baru. Pilih tanggal lain atau hubungi resepsionis.', $e->errors()['tanggal'][0]);
        }
        $this->assertSame('2026-10-24', $r->rooms()->sole()->check_in_date->toDateString());
    }

    public function test_E7_staf_melihat_jenis_kasur_dan_peringatan_bila_berbeda(): void
    {
        // Check-in: kamar berjenis kasur sama didahulukan; kamar lain diberi peringatan
        $r = app(ReservasiService::class)->buatOnline(Pelanggan::dariIsian(['name' => 'Tamu Uji', 'phone' => '081200000004'], null, 'hp'),
            $this->tgl('2026-10-06'), $this->tgl('2026-10-08'), [$this->tipe('STD')->id => 1], null, [], [$this->tipe('STD')->id => ['twin_single']]);
        $r->update(['status' => 'confirmed']);
        $baris = $r->rooms()->sole();
        $this->assertSame('101', $baris->room->room_number);
        Carbon::setTestNow('2026-10-06 14:10:00');

        Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)
            ->assertSee('Standard Room · Twin Single')
            ->call('open', $r->id)
            ->assertSee('Kamar untuk Standard Room · Twin Single')->assertSee('Kamar 104 · Twin Single')
            ->assertSee('data-peringatan-kasur="sama"', false)
            ->set('penempatan.'.$baris->id, (string) $this->kamar('102')->id)
            ->assertSee('data-peringatan-kasur="beda"', false)->assertSee('Jenis kasur berbeda dari pesanan tamu (Twin Single)')
            ->set('penempatan.'.$baris->id, (string) $this->kamar('101')->id)
            ->call('simpan')->assertHasNoErrors();
        $this->assertSame('checked_in', $baris->fresh()->status);

        // Reservasi Langsung: jenis kasur di tiap kamar dan saringan per jenis kasur
        Livewire::actingAs($this->akun('fo'))->test(ReservasiLangsung::class)
            ->assertSee('data-kasur="twin_single"', false)->assertSee('data-kasur="king"', false)
            ->set('saringKasur', 'queen')
            ->assertSee('data-kasur="queen"', false)->assertDontSee('data-kasur="king"', false)->assertDontSee('data-kasur="twin_single"', false);
    }

    /** Extra bed (EXB01) dan Sarapan tambahan (SRP01) seperti di basis data utama; extra_bed_max_qty bawaan 1. */
    private function siapkanExtraBed(): void
    {
        Service::create(['code' => Service::EXTRA_BED, 'name' => 'Extra Bed', 'description' => 'Kasur tambahan, maksimal 1 per kamar. Belum termasuk sarapan.',
            'category' => 'amenity', 'department' => 'housekeeping', 'unit' => 'malam', 'price' => 100000, 'is_active' => true]);
        Service::create(['code' => Service::SARAPAN_TAMBAHAN, 'name' => 'Sarapan Tambahan', 'category' => 'fnb', 'menu_group' => 'Paket',
            'department' => 'fnb', 'unit' => 'porsi', 'price' => 80000, 'is_active' => true]);
    }

    /** Pesan satu kamar tipe $kode berjenis kasur $kasur (null = bebas) untuk dewasa/anak, dengan $extra extra bed. */
    private function pesanSatu(string $kode, ?string $kasur, int $dewasa, int $anak = 0, int $extra = 0): Reservation
    {
        $id = $this->tipe($kode)->id;
        $tamu = Pelanggan::dariIsian(['name' => 'Tamu Uji', 'phone' => '0812'.random_int(10000000, 99999999)], null, 'hp');

        return app(ReservasiService::class)->buatOnline($tamu, $this->tgl('2026-10-20'), $this->tgl('2026-10-22'), [$id => 1], null,
            [$id => [['extra' => $extra]]], [$id => [$kasur]], [$id => [['dewasa' => $dewasa, 'anak' => $anak]]]);
    }

    private function galatPesan(callable $pesan): ?string
    {
        try {
            $pesan();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return null;
    }

    public function test_E9_kapasitas_per_tipe_dan_jenis_kasur_dari_satu_aturan(): void
    {
        // RoomType::ATURAN_KAPASITAS: dewasa = kapasitas kasur dibatasi tipe, anak dibatasi terpisah
        $fam = $this->tipe('FAM');
        $this->assertSame(['dewasa' => 2, 'anak' => 2], $fam->kapasitasKasur('king'));
        $this->assertSame(['dewasa' => 4, 'anak' => 2], $fam->kapasitasKasur('twin_queen'));
        $this->assertSame(['dewasa' => 3, 'anak' => 2], $fam->kapasitasKasur('triple_single'));
        $this->assertSame(['dewasa' => 2, 'anak' => 1], $this->tipe('DLX')->kapasitasKasur('twin_double'));
        $this->assertSame(['dewasa' => 2, 'anak' => 1], $this->tipe('STD')->kapasitasKasur('twin_single'));
        $this->assertSame(['dewasa' => 2, 'anak' => 1], $this->tipe('VIP')->kapasitasKasur('king'));

        Livewire::test(DetailKamar::class, ['kode' => 'fam'] + self::CARI)
            ->assertSeeInOrder(['King Bed', 'Maks. 2 dewasa + 2 anak', 'Termasuk sarapan 4 orang',
                'Twin Queen', 'Maks. 4 dewasa + 2 anak', 'Termasuk sarapan 4 orang', 'Triple Single', 'Maks. 3 dewasa + 2 anak', 'Termasuk sarapan 4 orang']);
        Livewire::test(DetailKamar::class, ['kode' => 'dlx'] + self::CARI)
            ->assertSeeInOrder(['Twin Double', 'Maks. 2 dewasa + 1 anak', 'Termasuk sarapan 2 orang'])->assertDontSee('<span data-kapasitas-kasur>Maks. 4 dewasa', false);

        // Daftar kamar: dewasa dan anak terbesar dari jenis kasurnya
        Livewire::test(DaftarKamar::class)->assertSeeInOrder(['Deluxe Room', 'Maks. 2 dewasa + 1 anak', 'Family Room', 'Maks. 4 dewasa + 2 anak']);
        $this->assertSame(['dewasa' => 4, 'anak' => 2], RoomType::batasPencarian());
    }

    public function test_E10_pembagian_tamu_dibatasi_kasur_dan_bisa_dengan_extra_bed(): void
    {
        $cari = ['masuk' => '2026-10-20', 'keluar' => '2026-10-22'];
        $king = fn (string $tamu) => Livewire::withQueryParams($cari + ['tamu' => $tamu])->test(DaftarKamar::class)->call('aturKamar', 'FAM', 'king', 1);

        // Tanpa layanan extra bed: King hanya sampai 2 dewasa (anak hingga 2), jadi tidak muat untuk 3 dewasa dan tidak bisa dipilih
        $this->assertSame('', $king('3-0')->get('pilih'));
        $this->assertSame(RoomType::TIDAK_MUAT, collect(Livewire::withQueryParams($cari + ['tamu' => '3-0'])->test(DaftarKamar::class)->viewData('tipe')
            ->firstWhere('code', 'FAM')->barisKasur)->firstWhere('kode', 'king')['cocok']);

        // Dengan extra bed: 3 dewasa dibagi otomatis ke King dengan 1 extra bed; 4 dewasa tetap tidak muat
        $this->siapkanExtraBed();
        $lw = $king('3-0')->assertSee('3 dewasa · perlu extra bed');
        $r = $lw->viewData('ringkasan');
        $this->assertTrue($r['status']['lengkap']);
        $this->assertSame(1, $r['kamar'][0]['extra']);
        $this->assertFalse($king('4-0')->viewData('ringkasan')['status']['lengkap']);

        // Halaman pemesan: King untuk 3 dewasa mewajibkan 1 extra bed, lalu tersimpan
        parse_str((string) parse_url($r['urlLanjut'], PHP_URL_QUERY), $q);
        $this->assertSame(['3-0', 'king'], [$q['tamu'], $q['kasur']]);
        Livewire::withQueryParams($q)->test(PesanKamar::class, ['kode' => 'fam'])
            ->assertSee('data-extra-wajib', false)->assertSee('Extra bed × 1')
            ->call('aturTambahan', 1, 'extra', 0)->assertSet('tambahan.1.extra', 1)
            ->set('nama', 'Rizky Pratama')->set('hp', '081299110022')->set('metode', 'bca')->call('pesan')->assertHasNoErrors();
        $baris = Reservation::sole()->rooms()->with('room', 'tambahan.items')->sole();
        $this->assertSame('king', $baris->room->bed_type);
        $this->assertSame(1, (int) $baris->tambahan->items->sole()->quantity);

        // King untuk 4 dewasa tetap tidak bisa walau dengan extra bed
        Livewire::withQueryParams(['tamu' => '4-0', 'kasur' => 'king'] + $q)->test(PesanKamar::class, ['kode' => 'fam'])
            ->assertSee('King Bed Family Room hanya muat 2 dewasa + 2 anak, kamar 1 berisi 4 dewasa. Silakan pilih jenis kasur lain.');

        // Anak melebihi batas anak tidak bisa ditutup extra bed: Standard 2 dewasa + 2 anak ditolak
        Livewire::withQueryParams(['tamu' => '2-2', 'kasur' => 'twin_single', 'pilihan' => 'STD'] + $q)->test(PesanKamar::class, ['kode' => 'std'])
            ->assertSee('Twin Single Standard Room hanya muat 2 dewasa + 1 anak, kamar 1 berisi 2 dewasa dan 2 anak. Silakan pilih jenis kasur lain.');
    }

    public function test_E11_server_menolak_tamu_melebihi_kapasitas_jenis_kasur(): void
    {
        $this->siapkanExtraBed();

        // Family King: 2 dewasa + 2 anak boleh; 3 dewasa ditolak, boleh dengan extra bed
        $this->assertNull($this->galatPesan(fn () => $this->pesanSatu('FAM', 'king', 2, 2)));
        $this->assertSame('King Bed hanya muat 2 dewasa + 2 anak, sedangkan kamar 1 untuk 3 dewasa. Tambahkan extra bed atau pilih jenis kasur lain.',
            $this->galatPesan(fn () => $this->pesanSatu('FAM', 'king', 3)));
        $this->assertSame('King Bed hanya muat 2 dewasa + 2 anak, sedangkan kamar 1 untuk 4 dewasa. Silakan pilih jenis kasur lain.',
            $this->galatPesan(fn () => $this->pesanSatu('FAM', 'king', 4)));
        $this->assertNull($this->galatPesan(fn () => $this->pesanSatu('FAM', 'king', 3, 0, 1)));

        // Family Triple Single 4 orang ditolak; Twin Queen 4 dewasa boleh
        $this->assertSame('Triple Single hanya muat 3 dewasa + 2 anak, sedangkan kamar 1 untuk 4 dewasa. Tambahkan extra bed atau pilih jenis kasur lain.',
            $this->galatPesan(fn () => $this->pesanSatu('FAM', 'triple_single', 4)));
        $this->assertNull($this->galatPesan(fn () => $this->pesanSatu('FAM', 'twin_queen', 4)));

        // Deluxe Twin Double tetap 2 dewasa
        $this->assertSame('Twin Double hanya muat 2 dewasa + 1 anak, sedangkan kamar 1 untuk 3 dewasa. Tambahkan extra bed atau pilih jenis kasur lain.',
            $this->galatPesan(fn () => $this->pesanSatu('DLX', 'twin_double', 3)));
        $this->assertSame(3, Reservation::count());
        $this->assertSame(['king', 'king', 'twin_queen'], Reservation::with('rooms.room')->orderBy('id')->get()->map(fn ($r) => $r->rooms->sole()->room->bed_type)->all());

        // Tanpa pilihan kasur (rekomendasi kombinasi): jenis kasur terkecil yang muat
        $this->assertSame('triple_single', $this->pesanSatu('FAM', null, 3)->rooms()->sole()->room->bed_type);
        $this->assertSame('Family Room tidak punya jenis kasur untuk 5 dewasa dalam satu kamar.', $this->galatPesan(fn () => $this->pesanSatu('FAM', null, 5)));
    }

    public function test_E12_staf_diberi_peringatan_bila_tamu_melebihi_kapasitas_kamar(): void
    {
        $this->siapkanExtraBed();
        $king = $this->kamar('210');

        // Reservasi Langsung: dewasa/anak per kamar hanya untuk peringatan
        $t = Livewire::actingAs($this->akun('fo'))->test(ReservasiLangsung::class)->set('kamar', [(string) $king->id])
            ->assertSee('maks 2 dewasa + 2 anak')
            ->set("tamuKamar.{$king->id}.dewasa", 2)->set("tamuKamar.{$king->id}.anak", 2)->assertDontSee('data-peringatan-kapasitas', false)
            ->set("tamuKamar.{$king->id}.dewasa", 3)->set("tamuKamar.{$king->id}.anak", 0)
            ->assertSee('Melebihi kapasitas kamar ini (maks 2 dewasa + 2 anak). Tambahkan 1 extra bed atau pilih kamar lain.')
            ->call('aturTambahan', $king->id, 'extra', 1)->assertDontSee('data-peringatan-kapasitas', false)
            // Anak ketiga tidak bisa ditutup extra bed
            ->set("tamuKamar.{$king->id}.dewasa", 2)->set("tamuKamar.{$king->id}.anak", 3)
            ->assertSee('Melebihi kapasitas kamar ini (maks 2 dewasa + 2 anak). Pilih kamar lain.');

        // Check-in: kamar tujuan yang menampung lebih sedikit dari kasur pesanan (Twin Queen 4 dewasa) diberi peringatan
        $r = $this->pesanSatu('FAM', 'twin_queen', 4);
        $r->update(['status' => 'confirmed', 'dp_due_at' => null]);
        $r->rooms()->update(['check_in_date' => '2026-10-06', 'check_out_date' => '2026-10-08']);
        $baris = $r->rooms()->sole();
        Carbon::setTestNow('2026-10-06 14:10:00');
        $kurang = Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)->call('open', $r->id)
            ->assertSee('Kamar ini menampung lebih sedikit tamu dari pesanan (maks 4 dewasa + 2 anak)')->viewData('kurangMuat')[$baris->id];
        $this->assertTrue($kurang[$king->id]);
        $this->assertFalse($kurang[$this->kamar('214')->id]);
    }

    public function test_E13_aturan_kapasitas_dewasa_dan_anak_terpisah(): void
    {
        // Semua pasangan tipe + jenis kasur yang punya kamar aktif
        $pasangan = RoomType::with(['rooms' => fn ($q) => $q->where('is_active', true)])->get()
            ->flatMap(fn ($t) => $t->rooms->pluck('bed_type')->unique()->map(fn ($b) => [$t, $b]));
        $muat = fn (int $d, int $a, int $extra = 0) => $pasangan->filter(fn ($p) => $p[0]->muatKasur($p[1], $d, $a, $extra))
            ->map(fn ($p) => $p[0]->code.'.'.$p[1])->sort()->values()->all();

        // 2 dewasa + 2 anak: hanya Family (King, Twin Queen, Triple Single)
        $this->assertSame(['FAM.king', 'FAM.triple_single', 'FAM.twin_queen'], $muat(2, 2));
        // 2 dewasa + 1 anak: semua tipe dan jenis kasur
        $this->assertSame($pasangan->count(), count($muat(2, 1)));
        $this->assertEqualsCanonicalizing(['STD', 'DLX', 'FAM', 'VIP'], collect($muat(2, 1))->map(fn ($k) => strtok($k, '.'))->unique()->values()->all());
        // 3 dewasa di Standard: perlu 1 extra bed; anak tidak bisa ditutup extra bed
        foreach ($pasangan->filter(fn ($p) => $p[0]->code === 'STD') as [$t, $b]) {
            $this->assertSame(1, $t->extraPerlu($b, 3, 0));
            $this->assertTrue($t->muatKasur($b, 3, 0, 1));
            $this->assertNull($t->extraPerlu($b, 2, 2));
            $this->assertFalse($t->muatKasur($b, 2, 2, 1));
        }
        // 4 dewasa + 2 anak: hanya Family Twin Queen
        $this->assertSame(['FAM.twin_queen'], $muat(4, 2));

        // Rekomendasi per kamar menyebut semua jenis kasur yang muat: 2 dewasa + 2 anak hanya Family, ketiga kasurnya
        $rek = Livewire::test(DaftarKamar::class, ['masuk' => '2026-10-20', 'keluar' => '2026-10-21', 'tamu' => '2-2_2-1'])->viewData('rekomendasi');
        $keluarga = collect($rek['kamar'])->firstWhere('tamu', '2 dewasa · 2 anak');
        $this->assertSame('Family', $keluarga['tipe']);
        $this->assertEqualsCanonicalizing(['King Bed', 'Twin Queen', 'Triple Single'], [$keluarga['kasur'], ...$keluarga['lain']]);
        $this->assertSame('Standard', collect($rek['kamar'])->firstWhere('tamu', '2 dewasa · 1 anak')['tipe']);
    }

    public function test_E14_kecocokan_tipe_dan_kasur_dengan_pencarian(): void
    {
        $this->siapkanExtraBed();
        $cari = ['masuk' => '2026-10-20', 'keluar' => '2026-10-21'];
        $daftar = fn (string $tamu) => Livewire::withQueryParams($cari + ['tamu' => $tamu])->test(DaftarKamar::class);
        $cocok = fn ($lw) => $lw->viewData('tipe')->mapWithKeys(fn ($t) => [$t->code => $t->cocok])->all();
        $kasur = fn ($lw, string $kode) => collect($lw->viewData('tipe')->firstWhere('code', $kode)->barisKasur)->mapWithKeys(fn ($k) => [$k['kode'] => $k['cocok']])->all();
        [$muat, $extra, $tidak] = [RoomType::MUAT, RoomType::MUAT_EXTRA, RoomType::TIDAK_MUAT];

        // 4 dewasa + 2 anak (1 kamar): Family pertama dan bisa dipilih; tipe lain tersembunyi dan tidak bisa dipilih
        $lw = $daftar('4-2');
        $this->assertSame(['FAM' => $muat, 'STD' => $tidak, 'DLX' => $tidak, 'VIP' => $tidak], $cocok($lw));
        $this->assertSame(['king' => $tidak, 'twin_queen' => $muat, 'triple_single' => $extra], $kasur($lw, 'FAM'));
        $lw->assertSeeInOrder(['data-tipe-kamar="FAM"', 'data-pilih-kamar', 'data-tipe-kamar="STD"'], false)
            ->assertSee('Tidak muat untuk 4 dewasa + 2 anak')->assertSee('Tampilkan 3 tipe lain')->assertSee('perlu extra bed')
            ->assertSee('Untuk 4 dewasa + 2 anak dalam satu kamar, pilih Family Room. Atau tambah jumlah kamar.');
        $this->assertSame(3, substr_count($lw->html(), 'x-show="lainTerbuka"'));
        $lw->call('aturKamar', 'STD', 'twin_single', 1)->assertSet('pilih', '')
            ->call('pilihKasur', 'VIP', 'king')->assertSet('pilih', '')->assertDispatched('toast', teks: 'VIP · King Bed tidak muat untuk tamu yang dicari. Pilih jenis kasur lain atau tambah jumlah kamar.')
            ->call('pilihKasur', 'FAM', 'king')->assertSet('pilih', '')
            ->call('pilihKasur', 'FAM', 'twin_queen')->assertSet('pilih', 'FAM.twin_queen:1');

        // 2 dewasa + 2 anak: hanya Family, ketiga kasurnya muat
        $lw = $daftar('2-2');
        $this->assertSame(['FAM' => $muat, 'STD' => $tidak, 'DLX' => $tidak, 'VIP' => $tidak], $cocok($lw));
        $this->assertSame(['king' => $muat, 'twin_queen' => $muat, 'triple_single' => $muat], $kasur($lw, 'FAM'));

        // 2 dewasa + 1 anak: semua tipe muat, tanpa saran dan tanpa tipe tersembunyi
        $lw = $daftar('2-1');
        $this->assertSame(['STD' => $muat, 'DLX' => $muat, 'FAM' => $muat, 'VIP' => $muat], $cocok($lw));
        $lw->assertDontSee('data-saran-tipe', false)->assertDontSee('data-tampilkan-tipe-lain', false);

        // 3 dewasa: Standard/Deluxe/VIP perlu extra bed; Family Triple Single dan Twin Queen muat, King perlu extra bed
        $lw = $daftar('3-0');
        $this->assertSame(['FAM' => $muat, 'STD' => $extra, 'DLX' => $extra, 'VIP' => $extra], $cocok($lw));
        $this->assertSame(['king' => $extra, 'twin_queen' => $muat, 'triple_single' => $muat], $kasur($lw, 'FAM'));
        $this->assertSame([$extra], array_values(array_unique($kasur($lw, 'STD'))));

        // 2 kamar × 2 dewasa: semua tipe muat
        $this->assertSame(['STD' => $muat, 'DLX' => $muat, 'FAM' => $muat, 'VIP' => $muat], $cocok($daftar('2-0_2-0')));

        // Halaman detail: kasur yang tidak muat diberi tanda dan tombolnya nonaktif
        Livewire::withQueryParams($cari + ['tamu' => '4-2'])->test(DetailKamar::class, ['kode' => 'fam'])
            ->assertSee('Tidak muat untuk 4 dewasa + 2 anak')->assertSee('Tersedia · perlu extra bed')
            // Extra bed tidak termasuk sarapan
            ->assertSee('data-extra-tanpa-sarapan', false)->assertSee('Extra bed belum termasuk sarapan');
    }

    public function test_E8_kartu_jenis_kasur_memakai_foto_dari_folder_kamar_bila_ada(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('kamar/kasur/twin_single.png', 'isi');
        Storage::disk('public')->put('kamar/ruangan/double.png', 'isi');

        $kartu = collect($this->detail()->assertSee('data-foto-kasur', false)->assertSee('alt="Contoh Twin Single"', false)
            ->viewData('kartuKasur'))->keyBy('kode');

        $this->assertStringEndsWith('storage/kamar/kasur/twin_single.png', $kartu['twin_single']['foto']);
        // Belum ada foto kasurnya: kartu tetap memakai ikon
        $this->assertNull($kartu['double']['foto']);
        $this->assertStringEndsWith('storage/kamar/ruangan/double.png', Room::fotoKasur('double', 'ruangan'));
        $this->assertNull(Room::fotoKasur('../rahasia'));
    }
}
