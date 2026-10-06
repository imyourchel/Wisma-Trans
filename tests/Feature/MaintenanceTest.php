<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceSchedule;
use App\Models\Room;
use App\Services\RoomStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Modul 7 Maintenance: lapor kerusakan, penanganan Manager, jadwal rutin, dan riwayat. Waktu acuan 6 Okt 2026 10.00 WITA. */
class MaintenanceTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    /** @var array<string, int> label unit => id */
    private array $unit = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->siapkanDataContoh();
        Storage::fake('public');

        foreach ([
            ['AC Kamar 105', 1, 5, 'Lantai 1'], ['AC Kamar 204', 1, 20, 'Lantai 2'], ['AC Kamar 103', 1, 3, 'Lantai 1'],
            ['AC Kamar 201', 1, 17, 'Lantai 2'], ['Kloset Kamar 110', 2, 10, 'Lantai 1'],
            ['AC Aula 1', 1, null, 'Aula'], ['AC Aula 2', 1, null, 'Aula'], ['Router Lt.2', 4, null, 'Lantai 2'],
        ] as [$label, $fasilitas, $kamar, $area]) {
            $this->unit[$label] = DB::table('facility_units')->insertGetId([
                'facility_id' => $fasilitas, 'room_id' => $kamar, 'area_label' => $area, 'unit_label' => $label, 'is_active' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kamar(string $nomor): Room
    {
        return Room::where('room_number', $nomor)->firstOrFail();
    }

    /** Laporan contoh langsung di basis data (seperti data dari file SQL). */
    private function laporan(string $kode, string $status, array $isi = []): MaintenanceRequest
    {
        return MaintenanceRequest::create($isi + [
            'request_code' => $kode, 'maintenance_type' => 'corrective', 'damage_description' => 'Kerusakan '.$kode,
            'priority' => 'medium', 'reported_by' => 6, 'reported_at' => now()->subDay(), 'status' => $status,
        ]);
    }

    private function lapor(string $akun, array $isi)
    {
        return $this->actingAs($this->akun($akun))->postJson(route('staf.maintenance.lapor.store'), $isi + [
            'lokasi' => 'kamar', 'deskripsi' => 'AC tidak dingin dan berbunyi keras.', 'priority' => 'medium',
        ]);
    }

    public function test_MNT00_room_status_service_sumber_maintenance_melewati_cek_peran_tetapi_tidak_aturan_perpindahan(): void
    {
        $layanan = app(RoomStatusService::class);
        $kamar = $this->kamar('105');

        // F&B biasanya tidak boleh menutup kamar; dari proses maintenance boleh, dan log mencatat pengguna itu
        $layanan->change($kamar, RoomStatus::Maintenance, 'MNT-0099: uji', $this->akun('fnb'), 'maintenance');
        $this->assertSame(RoomStatus::Maintenance, $kamar->fresh()->status);
        $this->assertSame(4, $kamar->statusLogs()->latest('id')->first()->changed_by);

        // Tanpa sumber, F&B tetap ditolak
        $this->expectException(ValidationException::class);
        $layanan->change($this->kamar('112'), RoomStatus::Maintenance, 'uji', $this->akun('fnb'));
    }

    public function test_MNT00b_sumber_maintenance_tetap_menolak_perpindahan_terlarang_dan_catatan_kosong(): void
    {
        $layanan = app(RoomStatusService::class);
        try {
            $layanan->change($this->kamar('202'), RoomStatus::Maintenance, 'MNT-0099', $this->akun('manager'), 'maintenance');
            $this->fail('cleaning → maintenance seharusnya ditolak');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $layanan->change($this->kamar('112'), RoomStatus::Maintenance, null, $this->akun('manager'), 'maintenance');
    }

    public function test_MNT01_hk_lapor_kamar_kosong_tidak_bisa_dipakai_kamar_ditutup(): void
    {
        $kamar = $this->kamar('105');
        $logAwal = $kamar->statusLogs()->count();

        $this->lapor('hk', [
            'room_id' => $kamar->id, 'unit' => (string) $this->unit['AC Kamar 105'], 'tidak_bisa_dipakai' => '1', 'priority' => 'high',
            'foto' => [UploadedFile::fake()->image('ac.jpg', 400, 300)],
        ])->assertOk()->assertJsonPath('pesan', 'Laporan MNT-0001 terkirim ke Manager.');

        $l = MaintenanceRequest::sole();
        $this->assertSame(['MNT-0001', 'corrective', 'reported', 'high', $this->unit['AC Kamar 105'], null, 6],
            [$l->request_code, $l->maintenance_type, $l->status, $l->priority, $l->facility_unit_id, $l->room_id, $l->reported_by]);
        Storage::disk('public')->assertExists($l->photos[0]);
        $this->assertSame(RoomStatus::Maintenance, $kamar->fresh()->status);
        $this->assertSame($logAwal + 1, $kamar->statusLogs()->count());
        $this->assertStringStartsWith('MNT-0001: AC tidak dingin', $kamar->statusLogs()->latest('id')->first()->note);
    }

    public function test_MNT02_fo_lapor_kamar_terisi_status_tetap_dengan_peringatan(): void
    {
        $kamar = $this->kamar('204');
        $this->lapor('fo', ['room_id' => $kamar->id, 'unit' => (string) $this->unit['AC Kamar 204'], 'tidak_bisa_dipakai' => '1'])->assertOk();

        $this->assertSame(1, MaintenanceRequest::count());
        $this->assertSame(RoomStatus::OccupiedClean, $kamar->fresh()->status);
        $this->assertSame('Kamar sedang terisi. Status kamar tidak diubah; koordinasikan pindah kamar dengan Front Office.', session('peringatan'));
    }

    public function test_MNT03_lapor_tanpa_deskripsi_ditolak_dan_lainnya_di_kamar_mengisi_room_id(): void
    {
        $kamar = $this->kamar('213');
        $this->lapor('fnb', ['room_id' => $kamar->id, 'unit' => 'lainnya', 'deskripsi' => ''])->assertStatus(422)->assertJsonValidationErrors('deskripsi');
        // Unit dari kamar lain ditolak
        $this->lapor('fnb', ['room_id' => $kamar->id, 'unit' => (string) $this->unit['AC Kamar 105']])->assertStatus(422)->assertJsonValidationErrors('unit');

        $this->lapor('fnb', ['room_id' => $kamar->id, 'unit' => 'lainnya', 'deskripsi' => 'Lampu kamar mandi mati.'])->assertOk();
        $l = MaintenanceRequest::sole();
        $this->assertSame([null, $kamar->id], [$l->facility_unit_id, $l->room_id]);
        $this->assertSame(RoomStatus::Available, $kamar->fresh()->status); // tidak dicentang, kamar tidak ditutup
    }

    public function test_MNT04_MNT05_hak_akses(): void
    {
        $this->actingAs($this->akun('admin'))->get(route('staf.maintenance.lapor'))->assertForbidden();
        $this->actingAs($this->akun('admin'))->postJson(route('staf.maintenance.lapor.store'), [])->assertForbidden();
        $this->actingAs($this->akun('hk'))->get(route('staf.maintenance.index'))->assertForbidden();
        $this->actingAs($this->akun('fo'))->get(route('staf.maintenance.jadwal'))->assertForbidden();
        $this->actingAs($this->akun('fnb'))->get(route('staf.maintenance.riwayat'))->assertForbidden();
        $l = $this->laporan('MNT-0001', 'reported');
        $this->actingAs($this->akun('hk'))->postJson(route('staf.maintenance.kerjakan', $l), ['pengerja' => 'vendor', 'vendor_name' => 'X'])->assertForbidden();

        foreach (['fo', 'fnb', 'hk', 'manager'] as $a) {
            $this->actingAs($this->akun($a))->get(route('staf.maintenance.lapor'))->assertOk();
            $this->actingAs($this->akun($a))->get(route('staf.maintenance.saya'))->assertOk();
        }
        foreach (['staf.maintenance.index', 'staf.maintenance.jadwal', 'staf.maintenance.riwayat'] as $r) {
            $this->actingAs($this->akun('manager'))->get(route($r))->assertOk();
        }
    }

    public function test_MNT06_manager_kerjakan_dengan_vendor(): void
    {
        $l = $this->laporan('MNT-0001', 'reported', ['facility_unit_id' => $this->unit['AC Kamar 201']]);
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $l), ['pengerja' => 'vendor', 'vendor_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('vendor_name');
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $l), ['pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik'])->assertOk();

        $l->refresh();
        $this->assertSame(['in_progress', 'CV Sejuk Teknik', null], [$l->status, $l->vendor_name, $l->handled_by]);
        $this->assertTrue($l->started_at->equalTo(now()));

        // Staf internal
        $l2 = $this->laporan('MNT-0002', 'reported');
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $l2), ['pengerja' => 'staf', 'handled_by' => 6])->assertOk();
        $this->assertSame([6, null], [$l2->fresh()->handled_by, $l2->fresh()->vendor_name]);
    }

    public function test_MNT07_selesai_perlu_dibersihkan(): void
    {
        $kamar = $this->kamar('103'); // maintenance karena MNT-0003 (data contoh)
        $l = $this->laporan('MNT-0003', 'in_progress', ['facility_unit_id' => $this->unit['AC Kamar 103'], 'vendor_name' => 'CV Sejuk Teknik', 'started_at' => now()->subDay()]);

        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l), ['resolution_note' => 'Freon diisi ulang.'])
            ->assertStatus(422)->assertJsonValidationErrors('status_kamar');
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l), [
            'resolution_note' => 'Freon diisi ulang.', 'cost' => '350.000', 'status_kamar' => 'vacant_dirty',
        ])->assertOk();

        $l->refresh();
        $this->assertSame(['completed', '350000.00', 'Freon diisi ulang.'], [$l->status, $l->cost, $l->resolution_note]);
        $kamar->refresh();
        $this->assertSame(RoomStatus::VacantDirty, $kamar->status);
        $this->assertNotNull($kamar->dirty_since);
        $this->assertSame(2, $kamar->statusLogs()->latest('id')->first()->changed_by);
    }

    public function test_MNT08_selesai_siap_dipakai(): void
    {
        $kamar = $this->kamar('103');
        $l = $this->laporan('MNT-0003', 'in_progress', ['facility_unit_id' => $this->unit['AC Kamar 103'], 'handled_by' => 6, 'started_at' => now()->subDay()]);
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l), ['resolution_note' => 'Sudah normal.', 'status_kamar' => 'available'])->assertOk();

        $kamar->refresh();
        $this->assertSame(RoomStatus::Available, $kamar->status);
        $this->assertNull($kamar->dirty_since);
    }

    public function test_MNT09_MNT12_perpindahan_status_yang_tidak_boleh(): void
    {
        $baru = $this->laporan('MNT-0001', 'reported');
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $baru), ['resolution_note' => 'Selesai.'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('reported', $baru->fresh()->status);

        $selesai = $this->laporan('MNT-0002', 'completed', ['completed_at' => now()->subDay(), 'resolution_note' => 'Beres']);
        foreach ([
            ['staf.maintenance.kerjakan', ['pengerja' => 'vendor', 'vendor_name' => 'X']],
            ['staf.maintenance.selesaikan', ['resolution_note' => 'Lagi']],
            ['staf.maintenance.batalkan', ['alasan' => 'Salah']],
        ] as [$r, $isi]) {
            $this->actingAs($this->akun('manager'))->postJson(route($r, $selesai), $isi)->assertStatus(422)->assertJsonValidationErrors('status');
        }
        $this->assertSame(['completed', 'Beres'], [$selesai->fresh()->status, $selesai->fresh()->resolution_note]);
    }

    public function test_MNT10_MNT11_batalkan(): void
    {
        $kamar = $this->kamar('105');
        $this->lapor('hk', ['room_id' => $kamar->id, 'unit' => (string) $this->unit['AC Kamar 105'], 'tidak_bisa_dipakai' => '1'])->assertOk();
        $l = MaintenanceRequest::sole();
        $this->assertSame(RoomStatus::Maintenance, $kamar->fresh()->status);

        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.batalkan', $l), ['alasan' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('alasan');
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.batalkan', $l), ['alasan' => 'Laporan ganda.'])->assertOk();

        $this->assertSame(['cancelled', 'Laporan ganda.'], [$l->fresh()->status, $l->fresh()->resolution_note]);
        $this->assertSame(RoomStatus::Available, $kamar->fresh()->status);

        // Kamar yang ditutup karena laporan lain tidak ikut dibuka
        $lain = $this->laporan('MNT-0002', 'reported', ['facility_unit_id' => $this->unit['AC Kamar 103']]);
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.batalkan', $lain), ['alasan' => 'Salah kamar.'])->assertOk();
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('103')->fresh()->status);
    }

    public function test_MNT13_biaya_negatif_ditolak(): void
    {
        $l = $this->laporan('MNT-0001', 'in_progress', ['facility_unit_id' => $this->unit['AC Aula 1'], 'vendor_name' => 'CV Sejuk', 'started_at' => now()]);
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l), ['resolution_note' => 'Beres.', 'cost' => '-50000'])
            ->assertStatus(422)->assertJsonValidationErrors('cost');
        $this->assertSame('in_progress', $l->fresh()->status);
    }

    public function test_MNT14_catat_pelaksanaan_jadwal(): void
    {
        $jadwal = MaintenanceSchedule::create(['facility_id' => 1, 'area_label' => 'Lantai 2', 'interval_months' => 6, 'next_due_date' => '2026-09-20', 'vendor_name' => 'CV Sejuk Teknik']);
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.jadwal.laksanakan', $jadwal), [
            'tanggal' => '2026-10-07', 'pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik',
        ])->assertStatus(422)->assertJsonValidationErrors('tanggal');

        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.jadwal.laksanakan', $jadwal), [
            'tanggal' => '2026-10-06', 'pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik', 'cost' => '1.200.000', 'catatan' => '12 unit dicuci.',
        ])->assertOk()->assertJsonPath('pesan', 'Perawatan dicatat. Perawatan berikutnya: 6 Apr 2027.');

        $l = MaintenanceRequest::sole();
        $this->assertSame(['preventive', 'completed', $jadwal->id, 'Perawatan rutin AC Lantai 2', '1200000.00'],
            [$l->maintenance_type, $l->status, $l->maintenance_schedule_id, $l->damage_description, $l->cost]);
        $jadwal->refresh();
        $this->assertSame(['2026-10-06', '2027-04-06'], [$jadwal->last_maintenance_date->toDateString(), $jadwal->next_due_date->toDateString()]);
    }

    public function test_MNT15_jadwal_kembar_ditolak_dan_jumlah_unit(): void
    {
        $isi = ['facility_id' => 1, 'area_label' => 'Aula', 'interval_months' => 6, 'next_due_date' => '2026-10-01'];
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.jadwal.store'), $isi)->assertOk();
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.jadwal.store'), $isi)->assertStatus(422)->assertJsonValidationErrors('facility_id');
        // Fasilitas yang tidak dirawat dan interval di luar 1–24 ditolak
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.jadwal.store'), ['facility_id' => 3, 'area_label' => 'Aula', 'interval_months' => 30, 'next_due_date' => '2026-12-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['facility_id', 'interval_months']);

        $this->assertSame(2, MaintenanceSchedule::sole()->jumlahUnit());
        // Ubah jadwal tanpa mengganti cakupan tetap boleh; nonaktifkan tidak menghapus
        $j = MaintenanceSchedule::sole();
        $this->actingAs($this->akun('manager'))->putJson(route('staf.maintenance.jadwal.update', $j), ['interval_months' => 3] + $isi)->assertOk();
        $this->actingAs($this->akun('manager'))->patch(route('staf.maintenance.jadwal.toggle', $j))->assertRedirect();
        $this->assertSame([3, false], [$j->fresh()->interval_months, $j->fresh()->is_active]);
    }

    public function test_MNT16_penanda_jadwal(): void
    {
        $buat = fn ($tgl) => new MaintenanceSchedule(['next_due_date' => $tgl]);
        $this->assertSame('lewat', $buat('2026-09-20')->penanda());
        $this->assertSame(16, $buat('2026-09-20')->hariLewat());
        $this->assertSame('lewat', $buat('2026-10-05')->penanda());
        $this->assertSame('segera', $buat('2026-10-06')->penanda());
        $this->assertSame('segera', $buat('2026-10-13')->penanda());
        $this->assertSame('normal', $buat('2026-10-14')->penanda());

        MaintenanceSchedule::create(['facility_id' => 1, 'area_label' => 'Lantai 2', 'interval_months' => 6, 'next_due_date' => '2026-09-20']);
        MaintenanceSchedule::create(['facility_id' => 1, 'area_label' => 'Aula', 'interval_months' => 6, 'next_due_date' => '2026-10-01']);
        MaintenanceSchedule::create(['facility_id' => 2, 'area_label' => 'Lantai 1', 'interval_months' => 6, 'next_due_date' => '2026-10-06']);
        MaintenanceSchedule::create(['facility_id' => 5, 'area_label' => 'Lantai 1', 'interval_months' => 6, 'next_due_date' => '2026-10-09']);
        MaintenanceSchedule::create(['facility_id' => 4, 'area_label' => 'Lantai 2', 'interval_months' => 12, 'next_due_date' => '2027-01-15']);
        // Istilah perawatan: "terlambat" dan "perawatan berikutnya"; "jatuh tempo" hanya untuk tagihan
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.jadwal'))->assertOk()
            ->assertSeeInOrder(['Terlambat dirawat', 'Perlu dirawat minggu ini', 'Perawatan berikutnya',
                'Lantai 2', 'Terlambat 16 hari', 'Aula', 'Terlambat 5 hari', 'Kloset', 'Hari ini', 'Water Heater', '3 hari lagi', 'WiFi'])
            ->assertSee('Catat perawatan')
            ->assertDontSee('jatuh tempo');
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertSee('Perawatan terlambat')->assertDontSee('Jadwal lewat jatuh tempo');
    }

    public function test_MNT17_kode_berurutan(): void
    {
        foreach (['MNT-0001', 'MNT-0002', 'MNT-0003', 'MNT-0004', 'MNT-0005'] as $k) {
            $this->laporan($k, 'completed');
        }
        $this->lapor('hk', ['lokasi' => 'area', 'area_label' => 'Aula', 'unit' => (string) $this->unit['AC Aula 2']])
            ->assertOk()->assertJsonPath('pesan', 'Laporan MNT-0006 terkirim ke Manager.');
        $this->assertSame('MNT-0006', MaintenanceRequest::latest('id')->first()->request_code);
    }

    public function test_MNT18_total_biaya_riwayat_sesuai_saringan(): void
    {
        $this->laporan('MNT-0001', 'completed', ['facility_unit_id' => $this->unit['AC Kamar 201'], 'cost' => 350000, 'completed_at' => '2026-09-10 10:00:00']);
        $this->laporan('MNT-0002', 'completed', ['facility_unit_id' => $this->unit['Kloset Kamar 110'], 'cost' => 125000, 'completed_at' => '2026-10-02 10:00:00']);
        $this->laporan('MNT-0003', 'completed', ['room_id' => $this->kamar('201')->id, 'cost' => 50000, 'completed_at' => '2026-10-03 10:00:00']);
        $this->laporan('MNT-0004', 'cancelled', ['facility_unit_id' => $this->unit['AC Kamar 201'], 'cost' => 999000]);
        $this->laporan('MNT-0005', 'in_progress', ['facility_unit_id' => $this->unit['AC Kamar 201']]);

        $m = $this->actingAs($this->akun('manager'));
        $m->get(route('staf.maintenance.riwayat'))->assertOk()->assertViewHas('totalBiaya', 525000.0)->assertViewHas('jumlah', 3);
        $m->get(route('staf.maintenance.riwayat', ['kamar' => $this->kamar('201')->id]))->assertViewHas('totalBiaya', 400000.0)->assertSee('Riwayat Kamar 201');
        $m->get(route('staf.maintenance.riwayat', ['unit' => $this->unit['Kloset Kamar 110']]))->assertViewHas('totalBiaya', 125000.0);
        $m->get(route('staf.maintenance.riwayat', ['dari' => '2026-10-01', 'sampai' => '2026-10-31']))->assertViewHas('totalBiaya', 175000.0);
        $m->get(route('staf.maintenance.riwayat', ['area' => 'Lantai 2']))->assertViewHas('totalBiaya', 350000.0);
        $m->get(route('staf.maintenance.riwayat', ['jenis' => 'preventive']))->assertViewHas('totalBiaya', 0.0);
    }
}
