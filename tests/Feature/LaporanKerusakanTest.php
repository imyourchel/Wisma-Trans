<?php

namespace Tests\Feature;

use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Laporan Kerusakan (dulu Laporan Saya): tab "Belum selesai" untuk semua kerusakan terbuka dan tab "Laporan saya".
 * Hanya dibaca; biaya, nama vendor, dan catatan penyelesaian tidak tampil. Waktu acuan 6 Okt 2026 10.00 WITA.
 */
class LaporanKerusakanTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const RAHASIA = ['450.000', '450000', '75.000', 'CV Sejuk Teknik', 'CV Gas Jaya', 'kapasitor diganti', 'Engsel diganti oleh teknisi', 'Laporan ganda dari FO'];

    /** @var array<string, MaintenanceRequest> */
    private array $l = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        $ac = FacilityUnit::create(['facility_id' => 1, 'room_id' => $this->kamar('201')->id, 'area_label' => 'Lantai 2', 'unit_label' => 'AC-201', 'is_active' => true]);

        // Terbuka: A (FO, kamar 105, sedang, menahan kamar), B (HK, AC-201, tinggi, dikerjakan Rina), C (F&B, dapur, tinggi, vendor)
        $this->buat('A', 3, ['room_id' => $this->kamar('105')->id, 'damage_description' => 'Keran wastafel patah', 'priority' => 'medium',
            'reported_at' => '2026-10-05 08:00:00', 'blocks_room' => true]);
        $this->buat('B', 6, ['facility_unit_id' => $ac->id, 'damage_description' => 'AC tidak dingin', 'priority' => 'high',
            'reported_at' => '2026-10-06 09:12:00', 'status' => 'in_progress', 'handled_by' => 7, 'started_at' => '2026-10-06 09:30:00']);
        $this->buat('C', 4, ['damage_description' => 'Area Dapur: Kompor bocor gas', 'priority' => 'high',
            'reported_at' => '2026-10-06 08:00:00', 'status' => 'in_progress', 'vendor_name' => 'CV Gas Jaya']);
        // Sudah ditutup: D (HK, jalur cepat), E (FO, dibatalkan), F (HK, selesai biasa)
        $this->buat('D', 6, ['room_id' => $this->kamar('112')->id, 'damage_description' => 'AC berisik', 'priority' => 'high', 'reported_at' => '2026-10-04 10:00:00',
            'status' => 'completed', 'handled_by' => 2, 'vendor_name' => 'CV Sejuk Teknik', 'cost' => 450000, 'resolution_note' => 'Jalur cepat — kapasitor diganti']);
        $this->buat('E', 3, ['room_id' => $this->kamar('110')->id, 'damage_description' => 'Lampu kedip', 'priority' => 'low', 'reported_at' => '2026-10-03 10:00:00',
            'status' => 'cancelled', 'resolution_note' => 'Laporan ganda dari FO']);
        $this->buat('F', 6, ['room_id' => $this->kamar('109')->id, 'damage_description' => 'Engsel lemari lepas', 'priority' => 'low', 'reported_at' => '2026-10-02 10:00:00',
            'status' => 'completed', 'handled_by' => 7, 'cost' => 75000, 'resolution_note' => 'Engsel diganti oleh teknisi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buat(string $kunci, int $pelapor, array $isi): void
    {
        $this->l[$kunci] = MaintenanceRequest::create($isi + [
            'request_code' => 'MNT-000'.(count($this->l) + 1), 'maintenance_type' => 'corrective', 'reported_by' => $pelapor, 'status' => 'reported',
        ]);
    }

    private function halaman(string $akun, array $query = [])
    {
        return $this->actingAs($this->akun($akun))->get(route('staf.maintenance.saya', $query))->assertOk();
    }

    private function kode($respon): array
    {
        return $respon->viewData('laporan')->getCollection()->pluck('request_code')->all();
    }

    public function test_1_semua_staf_operasional_melihat_kerusakan_terbuka_dari_staf_lain(): void
    {
        foreach (['fo', 'hk', 'fnb', 'manager'] as $akun) {
            $r = $this->halaman($akun)->assertSee('Belum selesai (3)')->assertSee('Laporan Kerusakan');
            $this->assertEqualsCanonicalizing(['MNT-0001', 'MNT-0002', 'MNT-0003'], $this->kode($r));
        }
        $this->actingAs($this->akun('admin'))->get(route('staf.maintenance.saya'))->assertForbidden();

        // Isi baris: lokasi, lencana, penanda kamar tidak bisa dipakai, pelapor dan waktu, pengerja
        $this->halaman('fo')
            ->assertSee('Kamar 105 · lainnya')->assertSee('AC-201')->assertSee('Area umum: Dapur')->assertSee('Kompor bocor gas')
            ->assertSee('Belum dikerjakan')->assertSee('Sedang dikerjakan · Rina')->assertSee('Sedang dikerjakan · Vendor')
            ->assertSee('Tinggi')->assertSee('Kamar tidak bisa dipakai')
            ->assertSee('Yusuf · 5 Okt 08.00')->assertSee('Rahmat · 6 Okt 09.12')
            ->assertSeeInOrder(['MNT-0001', 'Laporan saya']);
        $this->halaman('hk')->assertSeeInOrder(['MNT-0002', 'Laporan saya']);
        // Menu sidebar berganti nama
        $this->halaman('hk')->assertSee('Laporan Kerusakan')->assertDontSee('Laporan Saya');
    }

    public function test_2_laporan_tertutup_hanya_tampil_di_laporan_saya_pemiliknya(): void
    {
        $this->assertEqualsCanonicalizing(['MNT-0001', 'MNT-0002', 'MNT-0003'], $this->kode($this->halaman('fo')));
        $this->assertSame(['MNT-0001', 'MNT-0005'], $this->kode($this->halaman('fo', ['tab' => 'saya'])));
        $this->assertSame(['MNT-0002', 'MNT-0004', 'MNT-0006'], $this->kode($this->halaman('hk', ['tab' => 'saya'])));
        $this->halaman('fo', ['tab' => 'saya'])->assertSee('Laporan saya (2)')->assertSee('Belum selesai (3)');

        // Detail laporan tertutup milik orang lain tidak bisa dibuka
        $this->assertNull($this->halaman('fo', ['lihat' => $this->l['D']->id])->viewData('detail'));
        $this->assertSame($this->l['E']->id, $this->halaman('fo', ['tab' => 'saya', 'lihat' => $this->l['E']->id])->viewData('detail')->id);
        $this->assertSame($this->l['B']->id, $this->halaman('fo', ['lihat' => $this->l['B']->id])->viewData('detail')->id);
    }

    public function test_3_biaya_vendor_dan_catatan_penyelesaian_tidak_tampil(): void
    {
        $halaman = [
            ['fo', []], ['fo', ['tab' => 'saya']], ['hk', ['tab' => 'saya']], ['manager', []],
            ['hk', ['tab' => 'saya', 'lihat' => $this->l['D']->id]], ['hk', ['tab' => 'saya', 'lihat' => $this->l['F']->id]],
            ['fo', ['tab' => 'saya', 'lihat' => $this->l['E']->id]], ['fnb', ['lihat' => $this->l['C']->id]],
        ];
        foreach ($halaman as [$akun, $query]) {
            $r = $this->halaman($akun, $query);
            foreach (self::RAHASIA as $teks) {
                $r->assertDontSee($teks);
            }
        }
        // Laporan jalur cepat tetap berlencana Jalur cepat; pengerja vendor hanya "Vendor"
        $this->halaman('hk', ['tab' => 'saya'])->assertSeeInOrder(['MNT-0004', 'Jalur cepat']);
        $this->halaman('fnb', ['lihat' => $this->l['C']->id])->assertSeeInOrder(['Pengerja', 'Vendor']);
    }

    public function test_4_tidak_ada_tombol_penanganan(): void
    {
        foreach (['fo' => [], 'manager' => ['lihat' => $this->l['B']->id], 'hk' => ['tab' => 'saya', 'lihat' => $this->l['D']->id]] as $akun => $query) {
            $r = $this->halaman($akun, $query);
            foreach ($this->l as $laporan) {
                foreach (['kerjakan', 'selesaikan', 'batalkan', 'jalur-cepat', 'lengkapi'] as $aksi) {
                    $r->assertDontSee(route('staf.maintenance.'.$aksi, $laporan), false);
                }
            }
            $r->assertDontSee('>Kerjakan<', false)->assertDontSee('Lengkapi')->assertDontSee('Selesaikan')->assertDontSee('kata sandi Manager');
        }
        // Manager mendapat tautan ke laporan yang sama di Penanganan; staf lain tidak
        $this->halaman('manager', ['lihat' => $this->l['B']->id])->assertSee('Buka di Penanganan')
            ->assertSee(e(route('staf.maintenance.index', ['tab' => 'in_progress', 'cari' => 'MNT-0002'])), false);
        $this->halaman('fo', ['lihat' => $this->l['B']->id])->assertSee('AC tidak dingin')->assertDontSee('Buka di Penanganan');
    }

    public function test_5_penyaring_lokasi_cari_dan_kamar_tidak_bisa_dipakai(): void
    {
        $this->assertSame(['MNT-0002', 'MNT-0001'], $this->kode($this->halaman('hk', ['lokasi' => 'kamar'])));
        $this->assertSame(['MNT-0003'], $this->kode($this->halaman('hk', ['lokasi' => 'area'])));
        $this->assertSame(['MNT-0001'], $this->kode($this->halaman('hk', ['cari' => '105'])));
        $this->assertSame(['MNT-0002'], $this->kode($this->halaman('hk', ['cari' => '201'])));
        $this->assertSame(['MNT-0003'], $this->kode($this->halaman('hk', ['cari' => 'MNT-0003'])));
        $this->assertSame([], $this->kode($this->halaman('hk', ['cari' => '112'])));
        $this->assertSame(['MNT-0001'], $this->kode($this->halaman('hk', ['menahan' => '1'])));
        $this->halaman('hk', ['cari' => '999'])->assertSee('Tidak ada kerusakan yang belum selesai.');
    }

    public function test_6_urutan_tinggi_dulu_lalu_paling_lama_dilaporkan(): void
    {
        $r = $this->halaman('fnb');
        $this->assertSame(['MNT-0003', 'MNT-0002', 'MNT-0001'], $this->kode($r));
        $r->assertSeeInOrder(['MNT-0003', 'MNT-0002', 'MNT-0001']);
        // Laporan saya: terbaru dulu
        $this->assertSame(['MNT-0002', 'MNT-0004', 'MNT-0006'], $this->kode($this->halaman('hk', ['tab' => 'saya'])));
    }

    public function test_7_tautan_dari_form_lapor_membuka_laporan_saya(): void
    {
        $tujuan = route('staf.maintenance.saya', ['tab' => 'saya']);
        $this->actingAs($this->akun('hk'))->get(route('staf.maintenance.lapor'))->assertOk()->assertSee('href="'.$tujuan.'"', false);
        $this->assertSame('saya', $this->actingAs($this->akun('hk'))->get($tujuan)->assertOk()->viewData('tab'));

        // Setelah mengirim laporan, staf juga diarahkan ke tab Laporan saya
        $this->actingAs($this->akun('hk'))->postJson(route('staf.maintenance.lapor.store'), [
            'lokasi' => 'kamar', 'room_id' => $this->kamar('203')->id, 'unit' => 'lainnya', 'deskripsi' => 'Gorden lepas', 'priority' => 'low', 'tidak_bisa_dipakai' => '0',
        ])->assertOk()->assertJsonPath('redirect', $tujuan);
        $this->assertContains('MNT-0007', $this->kode($this->halaman('hk', ['tab' => 'saya'])));
    }
}
