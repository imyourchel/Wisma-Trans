<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\Kamar\RoomStatusBoard;
use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use App\Services\Kamar\RoomStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Perbaikan Modul 2 dan 7: kamar yang sudah dipesan, beberapa laporan di satu kamar, laporan ganda, dan area unit fasilitas.
 * Waktu acuan 6 Okt 2026 10.00 WITA. Hak akses Admin atas status kamar diuji di HakAksesKamarTest.
 */
class PerbaikanKamarMaintenanceTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->siapkanDataContoh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Reservasi satu kamar dengan nomor kamar sudah ditetapkan. */
    private function pesan(string $kode, string $kamar, string $masuk, string $keluar, string $status = 'booked'): void
    {
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => 'Citra Lestari', 'phone' => '08'.random_int(1000000000, 9999999999)]);
        $rsv = DB::table('reservations')->insertGetId([
            'reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'walk_in',
            'status' => $status === 'cancelled' ? 'cancelled' : 'confirmed',
        ]);
        DB::table('reservation_rooms')->insert([
            'reservation_id' => $rsv, 'room_type_id' => $this->kamar($kamar)->room_type_id, 'room_id' => $this->kamar($kamar)->id,
            'check_in_date' => $masuk, 'check_out_date' => $keluar, 'price_per_night' => 460000, 'status' => $status,
        ]);
    }

    private function unit(string $label, int $fasilitas, string $kamar): FacilityUnit
    {
        $k = $this->kamar($kamar);

        return FacilityUnit::create(['facility_id' => $fasilitas, 'room_id' => $k->id, 'area_label' => 'Lantai '.$k->floor, 'unit_label' => $label, 'is_active' => true]);
    }

    private function lapor(string $kamar, FacilityUnit|string $unit, bool $tutup = false, array $tambahan = [])
    {
        return $this->actingAs($this->akun('hk'))->postJson(route('staf.maintenance.lapor.store'), $tambahan + [
            'lokasi' => 'kamar', 'room_id' => $this->kamar($kamar)->id, 'unit' => $unit instanceof FacilityUnit ? (string) $unit->id : $unit,
            'deskripsi' => 'Rusak', 'priority' => 'high', 'tidak_bisa_dipakai' => $tutup ? '1' : '0',
        ]);
    }

    private function kerjakan(MaintenanceRequest $l): void
    {
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $l), ['pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik'])->assertOk();
    }

    private function selesaikan(MaintenanceRequest $l, ?string $statusKamar = null)
    {
        return $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l),
            array_filter(['resolution_note' => 'Sudah diperbaiki.', 'status_kamar' => $statusKamar]));
    }

    private function batalkan(MaintenanceRequest $l)
    {
        return $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.batalkan', $l), ['alasan' => 'Salah lapor']);
    }

    private function laporan(string $kode): MaintenanceRequest
    {
        return MaintenanceRequest::where('request_code', $kode)->firstOrFail();
    }

    /* ---------- 2. Dua laporan di satu kamar ---------- */

    public function test_P2_kamar_tetap_perbaikan_sampai_laporan_terakhir_selesai(): void
    {
        $ac = $this->unit('AC-105', 1, '105');
        $kloset = $this->unit('KL-105', 2, '105');
        $this->lapor('105', $ac, tutup: true)->assertOk();
        // Kamar sudah Perbaikan; centang "tidak bisa dipakai" tetap tercatat di laporan kedua
        $this->lapor('105', $kloset, tutup: true)->assertOk();
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('105')->status);
        [$satu, $dua] = [$this->laporan('MNT-0001'), $this->laporan('MNT-0002')];
        $this->assertSame([true, true], [$satu->blocks_room, $dua->blocks_room]);
        $this->kerjakan($satu);
        $this->kerjakan($dua);

        // Laci Penanganan: pilihan status kamar diganti keterangan
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.index', ['tab' => 'in_progress']))->assertOk()
            ->assertSee('Kamar 105 tetap Perbaikan karena masih ada laporan MNT-0002 yang belum selesai.');

        // Laporan pertama selesai: status kamar tidak wajib dipilih dan kamar tetap Perbaikan
        $this->selesaikan($satu)->assertOk()
            ->assertJsonPath('peringatan', 'Kamar 105 tetap Perbaikan karena masih ada laporan MNT-0002 yang belum selesai.');
        $this->assertSame('completed', $satu->fresh()->status);
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('105')->status);

        // Laporan terakhir: status kamar wajib dipilih, lalu kamar berubah sesuai pilihan
        $this->selesaikan($dua)->assertStatus(422)->assertJsonValidationErrors('status_kamar');
        $this->selesaikan($dua, 'vacant_dirty')->assertOk()->assertJsonPath('peringatan', null);
        $this->assertSame(RoomStatus::VacantDirty, $this->kamar('105')->status);
    }

    public function test_P2b_batal_tidak_membuka_kamar_selama_masih_ada_laporan_lain(): void
    {
        $ac = $this->unit('AC-105', 1, '105');
        $this->lapor('105', $ac, tutup: true)->assertOk();          // MNT-0001 menutup kamar
        $this->lapor('105', 'lainnya', tutup: true)->assertOk();     // MNT-0002, kloset, juga tidak bisa dipakai

        $this->batalkan($this->laporan('MNT-0001'))->assertOk()
            ->assertJsonPath('peringatan', 'Kamar 105 tetap Perbaikan karena masih ada laporan MNT-0002 yang belum selesai.');
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('105')->status);

        // Laporan terakhir dibatalkan: kamar dibuka lagi karena ditutup oleh laporan kamar ini
        $this->batalkan($this->laporan('MNT-0002'))->assertOk();
        $this->assertSame(RoomStatus::Available, $this->kamar('105')->status);
    }

    public function test_P2c_laporan_kecil_tidak_menahan_kamar(): void
    {
        $ac = $this->unit('AC-105', 1, '105');
        $tv = $this->unit('TV-105', 3, '105');
        $this->lapor('105', $ac, tutup: true)->assertOk();     // MNT-0001: AC, kamar tidak bisa dipakai
        $this->lapor('105', $tv)->assertOk();                  // MNT-0002: remote TV hilang, tanpa centang
        [$ac1, $tv1] = [$this->laporan('MNT-0001'), $this->laporan('MNT-0002')];
        $this->assertSame([true, false], [$ac1->blocks_room, $tv1->blocks_room]);
        $this->kerjakan($ac1);

        // Laci Penanganan: laporan TV hanya sebagai informasi, pilihan status kamar tetap tampil
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.index', ['tab' => 'in_progress']))->assertOk()
            ->assertSee('Laporan MNT-0002 di kamar ini juga belum selesai, tetapi tidak membuat kamar tidak bisa dipakai, jadi tidak menahan kamar.')
            ->assertDontSee('tetap Perbaikan karena');

        // AC selesai: status kamar wajib dipilih dan kamar berubah; laporan TV tetap terbuka
        $this->selesaikan($ac1)->assertStatus(422)->assertJsonValidationErrors('status_kamar');
        $this->selesaikan($ac1, 'available')->assertOk()->assertJsonPath('peringatan', null);
        $this->assertSame(RoomStatus::Available, $this->kamar('105')->status);
        $this->assertSame('reported', $tv1->fresh()->status);
    }

    public function test_P2d_sql_data_lama_menandai_laporan_yang_menutup_kamar(): void
    {
        // Seperti data contoh: kamar 103 ditutup dengan catatan "AC tidak dingin, lihat MNT-0003"
        $buat = fn (string $kode, string $kamar) => MaintenanceRequest::create([
            'request_code' => $kode, 'maintenance_type' => 'corrective', 'room_id' => $this->kamar($kamar)->id,
            'damage_description' => 'Lama', 'priority' => 'medium', 'reported_at' => now(), 'status' => 'reported',
        ]);
        $buat('MNT-0003', '103');
        $buat('MNT-0004', '103');   // tidak pernah menutup kamar
        $buat('MNT-0005', '105');   // kodenya tidak ada di log kamar 105
        DB::table('room_status_logs')->insert(['room_id' => $this->kamar('101')->id, 'status' => 'maintenance', 'changed_at' => now(), 'note' => 'Salah kamar, MNT-0005']);

        $sql = file_get_contents(database_path('sql/2026-10-07-maintenance-blocks-room.sql'));
        preg_match('/UPDATE maintenance_requests.+?;/s', $sql, $update);
        DB::statement($update[0]);

        $this->assertSame(['MNT-0003'], MaintenanceRequest::where('blocks_room', true)->pluck('request_code')->all());
    }

    /* ---------- Kamar terisi dengan kerusakan penahan ---------- */

    public function test_P8_kamar_terisi_dengan_kerusakan_penahan_tidak_menjadi_siap_setelah_dibersihkan(): void
    {
        $ac = $this->unit('AC-204', 1, '204');
        $status = app(RoomStatusService::class);

        // Tamu masih menginap: status kamar tidak diubah, tetapi laporan menahan kamar
        $this->lapor('204', $ac, tutup: true, tambahan: ['deskripsi' => 'AC tidak dingin'])->assertOk()
            ->assertJsonPath('peringatan', 'Kamar sedang terisi. Status kamar tidak diubah. Front Office sudah diberi tahu lewat WhatsApp dan email untuk memindahkan tamu.');
        $this->assertSame(RoomStatus::OccupiedClean, $this->kamar('204')->status);
        $this->assertTrue($this->laporan('MNT-0001')->blocks_room);

        // Papan Status: FO melihat tanda sebelum check-out
        Livewire::actingAs($this->akun('fo'))->test(RoomStatusBoard::class)
            ->assertSee('Ada laporan kerusakan')
            ->call('open', $this->kamar('204')->id)
            ->assertSee('Setelah tamu keluar dan kamar dibersihkan, kamar langsung ditandai Perbaikan, bukan Siap.');

        // Check-out, lalu HK membersihkan
        $status->change($this->kamar('204'), RoomStatus::VacantDirty, 'Check-out', $this->akun('fo'));
        $status->change($this->kamar('204'), RoomStatus::Cleaning, null, $this->akun('hk'));

        // Selesai bersih: kamar menjadi Perbaikan, bukan Siap
        Livewire::actingAs($this->akun('hk'))->test(RoomStatusBoard::class)
            ->call('open', $this->kamar('204')->id)
            ->set('status', 'available')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', teks: 'Kamar 204 langsung ditandai Perbaikan karena masih ada laporan MNT-0001 (AC tidak dingin) yang belum selesai.', peringatan: true);
        $kamar = $this->kamar('204');
        $this->assertSame(RoomStatus::Maintenance, $kamar->status);
        $this->assertNull($kamar->dirty_since);
        $log = $kamar->statusLogs()->latest('id')->first();
        $this->assertSame(['MNT-0001: ditahan karena kerusakan belum selesai', $this->akun('hk')->id], [$log->note, $log->changed_by]);

        // FO tidak bisa membuka kamar selama laporan belum selesai; pesan panjang tampil utuh di bawah pilihan, tidak terpotong
        Livewire::actingAs($this->akun('fo'))->test(RoomStatusBoard::class)
            ->call('open', $kamar->id)
            ->set('status', 'available')
            ->call('save')
            ->assertHasErrors('status')
            ->assertSeeHtml('<span class="mt-1.5 block text-xs font-medium leading-normal text-status-dirty" data-galat>Kamar 204 belum bisa dibuka karena masih ada laporan MNT-0001 (AC tidak dingin) yang belum selesai. Tutup laporannya dari Penanganan Maintenance.</span>');
        try {
            $status->change($kamar, RoomStatus::Available, null, $this->akun('fo'));
            $this->fail('Kamar seharusnya tidak bisa dibuka.');
        } catch (ValidationException $e) {
            $this->assertSame('Kamar 204 belum bisa dibuka karena masih ada laporan MNT-0001 (AC tidak dingin) yang belum selesai. Tutup laporannya dari Penanganan Maintenance.', $e->errors()['status'][0]);
        }

        // Laporan diselesaikan Manager: kamar baru bisa Siap
        $this->kerjakan($this->laporan('MNT-0001'));
        $this->selesaikan($this->laporan('MNT-0001'), 'available')->assertOk();
        $this->assertSame(RoomStatus::Available, $this->kamar('204')->status);
    }

    public function test_P8b_laporan_penahan_dibatalkan_kamar_dibuka_lagi_dan_laporan_kecil_tidak_menahan(): void
    {
        $status = app(RoomStatusService::class);
        $this->lapor('204', 'lainnya', tutup: true)->assertOk();     // MNT-0001: penahan
        $this->lapor('205', 'lainnya')->assertOk();                  // MNT-0002: remote hilang, tidak menahan
        foreach (['204', '205'] as $nomor) {
            $status->change($this->kamar($nomor), RoomStatus::VacantDirty, 'Check-out', $this->akun('fo'));
            $status->change($this->kamar($nomor), RoomStatus::Cleaning, null, $this->akun('hk'));
        }

        $this->assertNotNull($status->change($this->kamar('204'), RoomStatus::Available, null, $this->akun('hk')));
        $this->assertNull($status->change($this->kamar('205'), RoomStatus::Available, null, $this->akun('hk')));
        $this->assertSame([RoomStatus::Maintenance, RoomStatus::Available], [$this->kamar('204')->status, $this->kamar('205')->status]);

        // Kamar ditahan oleh kode laporan di catatan log, jadi pembatalan laporan membuka kamar
        $this->batalkan($this->laporan('MNT-0001'))->assertOk();
        $this->assertSame(RoomStatus::Available, $this->kamar('204')->status);
    }

    public function test_P8c_peran_tetap_diperiksa_dan_hk_tidak_bisa_memilih_perbaikan_dari_sedang_dibersihkan(): void
    {
        $this->lapor('202', 'lainnya', tutup: true)->assertOk();     // kamar 202 sedang dibersihkan: tidak ditutup
        $this->assertSame(RoomStatus::Cleaning, $this->kamar('202')->status);

        // Tabel perpindahan tidak berubah: Perbaikan dari Sedang dibersihkan hanya lewat penahanan saat selesai bersih
        $this->assertFalse(RoomStatus::Cleaning->canTransitionTo(RoomStatus::Maintenance));
        $this->assertNotContains(RoomStatus::Maintenance, RoomStatus::Cleaning->transitionsFor(['housekeeping', 'front_office']));
        // FO tidak berhak menyelesaikan pembersihan, jadi tidak ikut memicu penahanan
        try {
            app(RoomStatusService::class)->change($this->kamar('202'), RoomStatus::Available, null, $this->akun('fo'));
            $this->fail('FO seharusnya tidak bisa menyelesaikan pembersihan.');
        } catch (ValidationException) {
            $this->assertSame(RoomStatus::Cleaning, $this->kamar('202')->status);
        }
    }

    public function test_P9_status_awal_laporan_bernama_belum_dikerjakan(): void
    {
        $this->lapor('105', 'lainnya')->assertOk();

        $this->actingAs($this->akun('hk'))->get(route('staf.maintenance.saya'))->assertOk()
            ->assertSee('Belum dikerjakan')->assertDontSee('Baru dilaporkan');
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.index'))->assertOk()
            ->assertSee('Belum dikerjakan')->assertDontSee('Baru dilaporkan');
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertOk()->assertSee('Kerusakan belum dikerjakan');
    }

    /* ---------- 3 dan 4. Kamar yang sudah dipesan ---------- */

    public function test_P3_kamar_yang_sudah_dipesan_tidak_bisa_dinonaktifkan(): void
    {
        $this->pesan('RSV-0008', '201', '2026-10-06', '2026-10-08');
        $this->pesan('RSV-0009', '202', '2026-10-01', '2026-10-05', 'checked_out');   // sudah lewat
        $this->pesan('RSV-0010', '210', '2026-10-10', '2026-10-12', 'cancelled');     // dibatalkan

        $this->actingAs($this->akun('admin'))->patch(route('staf.kamar.toggle', $this->kamar('201')))
            ->assertSessionHas('error', 'Kamar 201 sudah dipesan untuk 6–8 Okt 2026 (RSV-0008). Pindahkan reservasinya dulu.');
        $this->assertTrue($this->kamar('201')->is_active);

        foreach (['202', '210'] as $nomor) {
            $this->actingAs($this->akun('admin'))->patch(route('staf.kamar.toggle', $this->kamar($nomor)))->assertSessionHas('status');
            $this->assertFalse($this->kamar($nomor)->is_active, $nomor);
        }
    }

    public function test_P3b_rentang_tanggal_beda_bulan(): void
    {
        $this->pesan('RSV-0011', '211', '2026-10-30', '2026-11-02');

        $this->actingAs($this->akun('admin'))->patch(route('staf.kamar.toggle', $this->kamar('211')))
            ->assertSessionHas('error', 'Kamar 211 sudah dipesan untuk 30 Okt – 2 Nov 2026 (RSV-0011). Pindahkan reservasinya dulu.');
    }

    public function test_P4_tipe_kamar_yang_sudah_dipesan_tidak_bisa_diubah(): void
    {
        $this->pesan('RSV-0008', '201', '2026-10-06', '2026-10-08');
        $kamar = $this->kamar('201');
        $isi = ['room_number' => '201', 'room_type_id' => $kamar->room_type_id, 'floor' => 2, 'bed_type' => 'double'];

        $this->actingAs($this->akun('admin'))->putJson(route('staf.kamar.update', $kamar), ['room_type_id' => $this->tipe('STD')->id] + $isi)
            ->assertStatus(422)
            ->assertJsonPath('errors.room_type_id.0', 'Tipe tidak bisa diubah: kamar 201 sudah dipesan untuk 6–8 Okt 2026 (RSV-0008).');
        $this->assertSame($this->tipe('DLX')->id, $kamar->fresh()->room_type_id);

        // Nomor, lantai, dan jenis kasur tetap boleh diubah
        $this->actingAs($this->akun('admin'))->putJson(route('staf.kamar.update', $kamar), ['room_number' => '201A', 'bed_type' => 'twin_single'] + $isi)->assertOk();
        $this->assertSame(['201A', 'twin_single'], [$kamar->fresh()->room_number, $kamar->fresh()->bed_type]);

        // Tamu yang sedang menginap juga mengunci tipe; kamar tanpa pemesanan bebas diubah
        $this->pesan('RSV-0012', '204', '2026-10-05', '2026-10-07', 'checked_in');
        $this->actingAs($this->akun('admin'))->putJson(route('staf.kamar.update', $this->kamar('204')),
            ['room_number' => '204', 'room_type_id' => $this->tipe('STD')->id, 'floor' => 2, 'bed_type' => 'double'])->assertStatus(422);
        $this->actingAs($this->akun('admin'))->putJson(route('staf.kamar.update', $this->kamar('209')),
            ['room_number' => '209', 'room_type_id' => $this->tipe('STD')->id, 'floor' => 2, 'bed_type' => 'double'])->assertOk();
    }

    /* ---------- 5. Lapor kamar yang ditunggu tamu ---------- */

    public function test_P5_kamar_ditunggu_tamu_hari_ini_atau_besok_tetap_ditutup_dengan_peringatan(): void
    {
        $this->pesan('RSV-0008', '201', '2026-10-06', '2026-10-08');
        $this->pesan('RSV-0013', '112', '2026-10-07', '2026-10-09');
        $this->pesan('RSV-0014', '105', '2026-10-08', '2026-10-10');   // lusa: tanpa peringatan

        $this->lapor('201', 'lainnya', tutup: true)->assertOk()
            ->assertJsonPath('peringatan', 'Kamar 201 dijadwalkan untuk tamu RSV-0008 hari ini. Front Office sudah diberi tahu lewat WhatsApp dan email untuk memindahkan tamu.');
        $this->lapor('112', 'lainnya', tutup: true)->assertOk()
            ->assertJsonPath('peringatan', 'Kamar 112 dijadwalkan untuk tamu RSV-0013 besok. Front Office sudah diberi tahu lewat WhatsApp dan email untuk memindahkan tamu.');
        $this->lapor('105', 'lainnya', tutup: true)->assertOk()->assertJsonPath('peringatan', null);

        foreach (['201', '112', '105'] as $nomor) {
            $this->assertSame(RoomStatus::Maintenance, $this->kamar($nomor)->status, $nomor);
        }
    }

    /* ---------- 6. Laporan ganda pada unit ---------- */

    public function test_P6_unit_dengan_laporan_terbuka_perlu_konfirmasi(): void
    {
        $ac = $this->unit('AC-105', 1, '105');
        $this->lapor('105', $ac)->assertOk();

        // Halaman lapor membawa daftar laporan terbuka per unit untuk peringatan langsung
        $this->actingAs($this->akun('hk'))->get(route('staf.maintenance.lapor'))->assertOk()->assertSee('MNT-0001')->assertSee('Tetap kirim, kerusakannya berbeda');

        $this->lapor('105', $ac)->assertStatus(422)
            ->assertJsonPath('errors.tetap_kirim.0', "AC-105 sudah punya laporan yang belum selesai:\n• MNT-0001 · \"Rusak\" · Rahmat, 6 Okt 10.00 · Belum dikerjakan\n"
                .'Kalau kerusakan yang Anda temukan sama, tidak perlu dikirim. Kalau berbeda, centang "Tetap kirim".');
        $this->assertSame(1, MaintenanceRequest::count());

        $this->lapor('105', $ac, tambahan: ['tetap_kirim' => '1'])->assertOk();
        $this->assertSame(2, MaintenanceRequest::count());

        // Setelah semua laporan unit itu ditutup, tidak perlu konfirmasi lagi
        $this->batalkan($this->laporan('MNT-0001'));
        $this->batalkan($this->laporan('MNT-0002'));
        $this->lapor('105', $ac)->assertOk();
    }

    /* ---------- 7. Unit fasilitas ---------- */

    public function test_P7_area_unit_di_kamar_mengikuti_lantai_kamar(): void
    {
        $this->actingAs($this->akun('admin'))->postJson(route('staf.unit-fasilitas.store'), [
            'facility_id' => 1, 'unit_label' => 'AC-201', 'area_label' => 'Kamar 201', 'room_id' => $this->kamar('201')->id,
        ])->assertOk();
        $unit = FacilityUnit::where('unit_label', 'AC-201')->sole();
        $this->assertSame('Lantai 2', $unit->area_label);

        // Pindah ke kamar lantai 1: area ikut berubah; unit area umum tetap memakai isian
        $this->actingAs($this->akun('admin'))->putJson(route('staf.unit-fasilitas.update', $unit), [
            'facility_id' => 1, 'unit_label' => 'AC-201', 'area_label' => 'Lantai 2', 'room_id' => $this->kamar('105')->id,
        ])->assertOk();
        $this->assertSame('Lantai 1', $unit->fresh()->area_label);

        $this->actingAs($this->akun('admin'))->postJson(route('staf.unit-fasilitas.store'), ['facility_id' => 1, 'unit_label' => 'AC-AULA-3', 'area_label' => 'Aula'])->assertOk();
        $this->assertSame('Aula', FacilityUnit::where('unit_label', 'AC-AULA-3')->sole()->area_label);
        $this->actingAs($this->akun('admin'))->postJson(route('staf.unit-fasilitas.store'), ['facility_id' => 1, 'unit_label' => 'AC-X'])
            ->assertStatus(422)->assertJsonValidationErrors('area_label');
    }

    public function test_P7b_unit_dengan_laporan_terbuka_tidak_bisa_dinonaktifkan(): void
    {
        $ac = $this->unit('AC-105', 1, '105');
        $this->lapor('105', $ac)->assertOk();

        $this->actingAs($this->akun('admin'))->patch(route('staf.unit-fasilitas.toggle', $ac))
            ->assertSessionHas('error', 'Unit AC-105 masih punya laporan kerusakan yang belum selesai (MNT-0001). Selesaikan atau batalkan laporannya dulu.');
        $this->assertTrue($ac->fresh()->is_active);

        $this->batalkan($this->laporan('MNT-0001'));
        $this->actingAs($this->akun('admin'))->patch(route('staf.unit-fasilitas.toggle', $ac))->assertSessionHas('status');
        $this->assertFalse($ac->fresh()->is_active);
    }
}
