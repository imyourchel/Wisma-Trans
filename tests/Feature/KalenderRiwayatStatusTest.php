<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\Kamar\RiwayatStatus;
use App\Livewire\Staf\Kamar\RoomStatusBoard;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Services\Kamar\KetersediaanKamar;
use App\Support\MenuStaf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Kalender Ketersediaan di Papan Status dan halaman Riwayat Status kamar. */
class KalenderRiwayatStatusTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Reservasi terkonfirmasi 8–10 Okt; tanpa nomor kamar bila $kamar null. */
    private function pesan(string $kode, string $nama, ?string $kamar, string $tipe = 'DLX', string $masuk = '2026-10-08', string $keluar = '2026-10-10'): int
    {
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => $nama, 'phone' => '08'.random_int(1000000000, 9999999999)]);
        $rsv = DB::table('reservations')->insertGetId(['reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'walk_in', 'status' => 'confirmed']);
        DB::table('reservation_rooms')->insert([
            'reservation_id' => $rsv, 'room_type_id' => $this->tipe($tipe)->id, 'room_id' => $kamar ? $this->kamar($kamar)->id : null,
            'check_in_date' => $masuk, 'check_out_date' => $keluar, 'price_per_night' => 460000, 'status' => 'booked',
        ]);

        return $rsv;
    }

    private function kalender(string $akun)
    {
        return Livewire::actingAs($this->akun($akun))->withQueryParams(['tampilan' => 'kalender'])->test(RoomStatusBoard::class);
    }

    private function log(string $kamar, string $status, string $waktu, ?int $oleh = null, ?string $catatan = null): int
    {
        return DB::table('room_status_logs')->insertGetId(['room_id' => $this->kamar($kamar)->id, 'status' => $status,
            'changed_by' => $oleh, 'changed_at' => $waktu, 'note' => $catatan]);
    }

    /* ---------------- 1. Kalender ---------------- */

    public function test_KK01_sisa_kamar_sama_dengan_ketersediaan_dan_pesanan_tanpa_kamar_mengurangi(): void
    {
        $dlx = $this->tipe('DLX')->id;
        $sebelum = app(KetersediaanKamar::class)->sisa(Carbon::parse('2026-10-08'), Carbon::parse('2026-10-09'))[$dlx];
        $this->pesan('RSV-T1', 'Dian Permata', null);

        $k = $this->kalender('fo')->viewData('kalender');
        $sisa = app(KetersediaanKamar::class)->sisa(Carbon::parse('2026-10-08'), Carbon::parse('2026-10-09'));
        $this->assertSame($sisa[$dlx], $k['sisa']['2026-10-08'][$dlx]);
        $this->assertSame($sebelum - 1, $k['sisa']['2026-10-08'][$dlx]);
        $this->assertSame([15, 14], [(int) $k['jumlahKamar'][$dlx], $k['sisa']['2026-10-08'][$dlx]]);

        $this->kalender('fo')->assertSee('Sisa kamar')->assertSee('14/15')->assertSee('Belum dapat kamar · Deluxe Room')
            ->assertSee('kamar Perbaikan tidak dihitung tersedia')
            // Kamar 103 sedang Perbaikan: arsir di kolom hari ini
            ->assertSeeHtml('data-perbaikan="103"');
    }

    public function test_KK02_sel_kosong_untuk_fo_membuka_reservasi_langsung_terisi(): void
    {
        $kamar = $this->kamar('106');
        $url = route('staf.reservasi-langsung', ['kamar' => $kamar->id, 'masuk' => '2026-10-08']);
        $this->kalender('fo')->assertSeeHtml(e($url));
        // Manager tidak membuat reservasi; tanggal lewat tidak bertaut
        $this->kalender('manager')->assertDontSeeHtml('data-sel-kosong');
        $this->kalender('fo')->set('start', '2026-10-01')
            ->assertDontSeeHtml(e(route('staf.reservasi-langsung', ['kamar' => $kamar->id, 'masuk' => '2026-10-02'])));

        Livewire::actingAs($this->akun('fo'))->withQueryParams(['kamar' => $kamar->id, 'masuk' => '2026-10-08'])->test(ReservasiLangsung::class)
            ->assertSet('masuk', '2026-10-08')->assertSet('keluar', '2026-10-09')->assertSet('kamar', [(string) $kamar->id])->assertSet('peringatanKamar', '');
    }

    public function test_KK03_reservasi_langsung_kamar_terpakai_dan_tanggal_lewat(): void
    {
        $this->pesan('RSV-T2', 'Hasan Basri', '106', 'STD');
        Livewire::actingAs($this->akun('fo'))->withQueryParams(['kamar' => $this->kamar('106')->id, 'masuk' => '2026-10-08'])->test(ReservasiLangsung::class)
            ->assertSet('masuk', '2026-10-08')->assertSet('kamar', [])
            ->assertSet('peringatanKamar', 'Kamar 106 tidak tersedia pada 8 Okt, pilih kamar lain.')
            ->assertSee('Kamar 106 tidak tersedia pada 8 Okt, pilih kamar lain.');

        Livewire::actingAs($this->akun('fo'))->withQueryParams(['kamar' => $this->kamar('107')->id, 'masuk' => '2026-10-01'])->test(ReservasiLangsung::class)
            ->assertSet('masuk', '2026-10-06')->assertSet('keluar', '2026-10-07')->assertSet('kamar', [(string) $this->kamar('107')->id]);
    }

    public function test_KK04_housekeeping_tidak_melihat_nama_tamu_manager_membuka_reservasi(): void
    {
        $id = $this->pesan('RSV-T3', 'Hasan Basri', '107', 'STD');
        $this->kalender('hk')->assertDontSee('Hasan Basri')->assertSee('RSV-T3')
            ->assertDontSeeHtml(e(route('staf.reservasi', ['buka' => $id])))->assertDontSeeHtml('data-sel-kosong');
        $this->kalender('manager')->assertSee('Hasan Basri')->assertSeeHtml(e(route('staf.reservasi', ['buka' => $id])));

        // ?buka= membuka detail; id yang tidak ada diabaikan; menutup detail menghapus parameternya
        Livewire::actingAs($this->akun('manager'))->withQueryParams(['buka' => (string) $id])->test(DaftarReservasi::class)
            ->assertSet('selectedId', $id)->assertSee('RSV-T3')->call('close')->assertSet('buka', '');
        Livewire::actingAs($this->akun('manager'))->withQueryParams(['buka' => '99999'])->test(DaftarReservasi::class)
            ->assertOk()->assertSet('selectedId', null)->assertSet('buka', '');
        $this->actingAs($this->akun('manager'))->get(route('staf.reservasi', ['buka' => 'abc']))->assertOk();
    }

    /* ---------------- 2. Menu ---------------- */

    public function test_KK05_satu_menu_papan_dan_kalender_judul_sesuai_tampilan(): void
    {
        $aktif = fn (string $html, string $label) => (bool) preg_match('/aria-current="page"\s*>'.preg_quote($label, '/').'</', $html);
        $menu = fn (string $peran) => collect(MenuStaf::untuk($this->akun($peran)));

        // Papan dan kalender satu menu di grup Kamar; grup Operasional dan menu Kalender Ketersediaan tidak ada lagi
        foreach (['fo', 'manager', 'hk'] as $peran) {
            $this->assertFalse($menu($peran)->contains('label', 'Operasional'));
            $this->assertSame(['Papan Status Kamar'], $menu($peran)->flatMap(fn ($g) => $g['anak'])->where('url', route('staf.papan-status'))->pluck('label')->all());
            $this->assertFalse($menu($peran)->flatMap(fn ($g) => $g['anak'])->contains('label', 'Kalender Ketersediaan'));
        }

        $kal = $this->actingAs($this->akun('fo'))->get(route('staf.papan-status', ['tampilan' => 'kalender']))->assertOk()
            ->assertSee('Kalender Ketersediaan · Panel Staf Wisma Trans', false)->assertSeeInOrder(['aria-label="Jejak halaman"', 'Kamar', 'Kalender Ketersediaan</h1>'], false)->getContent();
        $this->assertTrue($aktif($kal, 'Papan Status Kamar'));

        $papan = $this->actingAs($this->akun('fo'))->get(route('staf.papan-status'))->assertOk()
            ->assertSeeInOrder(['aria-label="Jejak halaman"', 'Kamar', 'Papan Status Kamar</h1>'], false)->getContent();
        $this->assertTrue($aktif($papan, 'Papan Status Kamar'));
        // Tombol Papan/Kalender berupa tautan, supaya sidebar dan judul ikut berganti
        $this->assertStringContainsString('href="'.e(route('staf.papan-status', ['tampilan' => 'kalender'])).'"', $papan);
    }

    public function test_KK06_warna_status_satu_sumber_dan_kontras_cukup(): void
    {
        $luminans = function (string $hex): float {
            $c = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
            $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $kontras = fn (string $a, string $b) => (max($luminans($a), $luminans($b)) + 0.05) / (min($luminans($a), $luminans($b)) + 0.05);

        $this->assertSame(['utama' => '#2E9E55', 'latar' => '#E6F5EB', 'teks' => '#1F6B3A', 'badge' => '#C8EBD3'], RoomStatus::Available->warna());
        $this->assertSame('#6A7587', RoomStatus::Maintenance->color());
        foreach (RoomStatus::cases() as $s) {
            $w = $s->warna();
            $this->assertGreaterThanOrEqual(4.5, $kontras($w['teks'], $w['latar']), $s->value.': teks di atas latar');
            $this->assertGreaterThanOrEqual(4.5, $kontras($w['teks'], $w['badge']), $s->value.': label di atas badge');
            $this->assertGreaterThanOrEqual(3, $kontras($w['utama'], $w['latar']), $s->value.': angka besar di atas latar');
        }

        // Papan: kartu kamar dan ringkasan memakai variabel warna status; ringkasan aktif saat disaring
        Livewire::actingAs($this->akun('fo'))->test(RoomStatusBoard::class)
            ->assertSee('--st: #2E9E55; --st-bg: #E6F5EB;', false)->assertSee('wt-st-kartu', false)->assertSee('wt-st-badge', false)
            ->call('filterBy', 'available')->assertSeeHtml('wt-st-ringkasan flex items-center gap-3 rounded-lg px-4 py-3 text-left shadow-wt-xs transition-shadow hover:shadow-wt-md is-aktif')
            ->call('filterBy', 'available')->assertSet('filter', '');
    }

    /* ---------------- 3. Riwayat Status ---------------- */

    public function test_KK06_status_sebelumnya_lama_dan_log_sistem(): void
    {
        $a = $this->log('110', 'available', '2026-10-06 06:00:00', 6, 'Selesai dibersihkan');
        $b = $this->log('110', 'occupied_clean', '2026-10-06 08:15:00', null, 'Check-in RSV-0012');
        // Dua log dengan waktu sama tetap berurutan menurut id
        $c = $this->log('110', 'occupied_dirty', '2026-10-06 08:15:00', 3, 'Tamu minta dibersihkan');

        $layar = Livewire::actingAs($this->akun('manager'))->test(RiwayatStatus::class)
            ->assertSet('dari', '2026-09-30')->assertSet('sampai', '2026-10-06');
        $sebelum = $layar->viewData('sebelum');
        $this->assertSame([null, 'available', 'occupied_clean'], [$sebelum[$a]->status_lalu, $sebelum[$b]->status_lalu, $sebelum[$c]->status_lalu]);
        $layar->assertSeeInOrder(['Tamu minta dibersihkan', 'Check-in RSV-0012', 'Selesai dibersihkan'])
            ->assertSee('2 jam 15 menit')->assertSee('kurang dari 1 menit')->assertSee('Sistem')->assertSee('Rina Wulandari');

        // Lini masa kamar: total waktu per status sampai sekarang (10.00)
        $layar->call('lini', $this->kamar('110')->id)->assertSee('Lini masa kamar 110')->assertSee('1 jam 45 menit');
    }

    public function test_KK07_penyaring_dan_hak_akses(): void
    {
        $this->log('110', 'available', '2026-10-06 06:00:00', 6, 'Selesai dibersihkan');
        $this->log('110', 'occupied_clean', '2026-10-06 08:15:00', null, 'Check-in RSV-0077');
        $this->log('109', 'vacant_dirty', '2026-10-04 09:00:00', 3, 'Check-out RSV-0009');
        $this->log('109', 'maintenance', '2026-09-20 09:00:00', 3, 'Lama sekali MNT-0001');

        $ids = fn ($l) => collect($l->viewData('logs')->items())->pluck('note')->all();
        $layar = Livewire::actingAs($this->akun('fo'))->test(RiwayatStatus::class);
        $this->assertNotContains('Lama sekali MNT-0001', $ids($layar));
        $this->assertSame(['Check-in RSV-0077'], $ids($layar->set('cari', 'RSV-0077')));
        $this->assertSame(['Check-in RSV-0077'], $ids($layar->set('cari', '')->set('staf', 'sistem')));
        $this->assertSame(['Check-out RSV-0009'], $ids($layar->set('staf', '')->set('kamar', (string) $this->kamar('109')->id)));
        $this->assertSame(['Selesai dibersihkan'], $ids($layar->set('kamar', '')->set('status', 'available')));
        $this->assertSame(['Lama sekali MNT-0001'], $ids($layar->set('status', '')->set('dari', '2026-09-01')->set('sampai', '2026-09-30')));

        foreach (['hk', 'fnb'] as $akun) {
            $this->actingAs($this->akun($akun))->get(route('staf.kamar.riwayat-status'))->assertForbidden();
        }
        $this->actingAs($this->akun('manager'))->get(route('staf.kamar.riwayat-status'))->assertOk();
    }

    public function test_KK08_jumlah_query_tidak_bertambah_sebanding_baris(): void
    {
        $hitung = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->akun('manager'))->test(RiwayatStatus::class);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->log('110', 'available', '2026-10-06 06:00:00', 6);
        $this->log('110', 'occupied_clean', '2026-10-06 07:00:00', 3);
        $sedikit = $hitung();

        foreach (['101', '102', '104', '106', '107', '108', '109', '111', '112', '113'] as $i => $kamar) {
            foreach ([1, 2, 3] as $j) {
                $this->log($kamar, $j % 2 ? 'vacant_dirty' : 'cleaning', '2026-10-0'.(3 + $j).' 0'.$i.':00:00', 6);
            }
        }
        $this->assertSame($sedikit, $hitung());
    }

    public function test_paginasi_riwayat_status_tidak_mengarah_ke_staf_ganda_dan_membawa_penyaring(): void
    {
        // 30 log tambahan supaya ada halaman 2 (25 baris per halaman)
        for ($i = 0; $i < 30; $i++) {
            DB::table('room_status_logs')->insert(['room_id' => 1, 'status' => $i % 2 ? 'available' : 'vacant_dirty', 'changed_by' => 3,
                'changed_at' => Carbon::parse('2026-10-05 08:00')->addMinutes($i * 10)->toDateTimeString(), 'note' => 'Uji paginasi '.$i]);
        }
        $l = Livewire::actingAs($this->akun('manager'))->withQueryParams(['dari' => '2026-09-30', 'sampai' => '2026-10-06'])
            ->test(RiwayatStatus::class);
        $html = $l->html();
        $this->assertStringNotContainsString('staf/staf', $html);
        $this->assertStringContainsString("gotoPage(2, 'page')", $html);

        $l->call('gotoPage', 2, 'page')->assertSet('dari', '2026-09-30')->assertSet('sampai', '2026-10-06')
            ->assertSee('Menampilkan 26–');
    }
}
