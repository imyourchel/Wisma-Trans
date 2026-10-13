<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\Housekeeping\KetersediaanStaf;
use App\Livewire\Staf\Housekeeping\PembagianTugas;
use App\Livewire\Staf\Housekeeping\PrioritasPembersihan;
use App\Livewire\Staf\Housekeeping\TugasSaya;
use App\Livewire\Staf\Kamar\RoomStatusBoard;
use App\Models\CleaningPriorityBatch;
use App\Models\CleaningPriorityScore;
use App\Models\HousekeepingTask;
use App\Models\ReservationRoom;
use App\Models\RoomStatusLog;
use App\Models\User;
use App\Services\Kamar\RoomStatusService;
use App\Services\Reservasi\ReservasiService;
use App\Services\SmartCleaning\AhpService;
use App\Services\SmartCleaning\NilaiKriteria;
use App\Services\SmartCleaning\PembagianTugasService;
use App\Services\SmartCleaning\PrioritasPembersihanService;
use App\Services\SmartCleaning\TugasHousekeepingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Modul 6 Smart Cleaning Priority (AHP-SAW) dan Workload Balancing (Greedy).
 * Data contoh 6 Okt 2026 10.00 WITA: 105 kosong kotor sejak 07.15, 112 sejak 09.40, 201 sejak 09.15,
 * 203 terisi kotor sejak 09.30, 202 sedang dibersihkan, 103 Perbaikan.
 */
class SmartCleaningTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const RAHMAT = 6;

    private const RINA = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->siapkanKriteriaAhp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->withoutVite();
        // Tamu RSV-0008 datang hari ini ke kamar 201; tamu RSV-0012 masih menginap di 203
        $this->pesan('RSV-0008', '201', 2, '2026-10-06', '2026-10-08', 'booked');
        $this->pesan('RSV-0012', '203', 2, '2026-10-05', '2026-10-08', 'checked_in');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pesan(string $kode, ?string $kamar, int $tipe, string $masuk, string $keluar, string $status, int $baris = 1): void
    {
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => 'Tamu '.$kode, 'phone' => '08'.random_int(1000000000, 9999999999)]);
        $rsv = DB::table('reservations')->insertGetId([
            'reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'walk_in',
            'status' => $status === 'checked_in' ? 'checked_in' : 'confirmed',
        ]);
        for ($i = 0; $i < $baris; $i++) {
            DB::table('reservation_rooms')->insert([
                'reservation_id' => $rsv, 'room_type_id' => $tipe, 'room_id' => $kamar ? $this->kamar($kamar)->id : null,
                'check_in_date' => $masuk, 'check_out_date' => $keluar, 'price_per_night' => 460000, 'status' => $status,
            ]);
        }
    }

    /** Hanya Rahmat dan Rina yang hadir hari ini. */
    private function hanyaRahmatDanRina(): void
    {
        foreach ([8, 9, 13] as $id) {
            DB::table('staff_availabilities')->insert(['user_id' => $id, 'work_date' => '2026-10-06', 'is_available' => 0]);
        }
    }

    private function hitung(): CleaningPriorityBatch
    {
        return app(PrioritasPembersihanService::class)->hitungBatch($this->akun('manager'));
    }

    private function bagi(): void
    {
        $this->hitung();
        app(PembagianTugasService::class)->simpan($this->akun('manager'), today());
    }

    private function tugas(string $kamar): HousekeepingTask
    {
        return HousekeepingTask::with('assignees')->where('room_id', $this->kamar($kamar)->id)->latest('id')->firstOrFail();
    }

    private function nilai(): array
    {
        return collect(app(NilaiKriteria::class)->hitung(now()))->mapWithKeys(fn ($m) => [$m['room']->room_number => $m])->all();
    }

    private function galat(callable $f, string $pesan): void
    {
        try {
            $f();
            $this->fail("Seharusnya ditolak: {$pesan}");
        } catch (ValidationException $e) {
            $this->assertSame($pesan, collect($e->errors())->flatten()->first());
        }
    }

    /* ---------- AHP dan kriteria ---------- */

    public function test_ahp_menghasilkan_bobot_dan_cr_data_awal(): void
    {
        $ahp = app(AhpService::class);
        $sesi = $ahp->sesiAktif();
        $hasil = $ahp->hitungDariPerbandingan((int) $sesi->id);

        foreach ($ahp->bobot((int) $sesi->id) as $id => $bobot) {
            $this->assertEqualsWithDelta($bobot, $hasil['bobot'][$id], 1e-6);
        }
        $this->assertEqualsWithDelta(0.647947, $hasil['bobot'][1], 1e-6);
        $this->assertEqualsWithDelta(0.229871, $hasil['bobot'][2], 1e-6);
        $this->assertEqualsWithDelta(0.122182, $hasil['bobot'][3], 1e-6);
        $this->assertEqualsWithDelta(3.003697, $hasil['lambda_max'], 1e-6);
        $this->assertEqualsWithDelta(0.003187, $hasil['cr'], 1e-6);
        $this->assertSame(0.58, $hasil['ri']);

        $this->actingAs($this->akun('manager'))->get(route('staf.housekeeping.bobot'))->assertOk()
            ->assertSee('Durasi kamar sudah kotor')->assertSee('Konsisten (CR < 0,1)')->assertSee('1/3')
            ->assertSee('Bobot dari penelitian terdahulu')->assertSee('0,647947');
    }

    public function test_nama_kriteria_sesuai_naskah(): void
    {
        $this->assertSame([
            'C1' => ['Waktu menuju check-in tamu berikutnya', 'cost'],
            'C2' => ['Status kamar', 'benefit'],
            'C3' => ['Durasi kamar sudah kotor', 'benefit'],
        ], DB::table('cleaning_criteria')->orderBy('code')->get()->mapWithKeys(fn ($k) => [$k->code => [$k->name, $k->attribute_type]])->all());

        $sql = file_get_contents(database_path('sql/2026-10-11-modul-6.sql'));
        $this->assertStringContainsString("name = 'Durasi kamar sudah kotor'", $sql);
        $this->assertStringContainsString('DROP INDEX uq_hktasks_date_room', $sql);
    }

    /* ---------- C1, C2, C3 ---------- */

    public function test_c1_kamar_ditetapkan_untuk_tamu_hari_ini_sebelum_dan_sesudah_jam_check_in(): void
    {
        $n = $this->nilai();
        $this->assertSame(240.0, $n['201']['nilai']['C1']);
        $this->assertSame('Tamu berikutnya RSV-0008 check-in 14.00', $n['201']['keterangan']['C1']);
        $this->assertSame(1440.0, $n['112']['nilai']['C1']);
        $this->assertSame('Tidak ada tamu berikutnya hari ini', $n['112']['keterangan']['C1']);

        // Terisi kotor: tamu masih menginap
        $this->assertSame([1440.0, 2.0], [$n['203']['nilai']['C1'], $n['203']['nilai']['C2']]);
        $this->assertSame('Tamu masih menginap', $n['203']['keterangan']['C1']);
        $this->assertSame(3.0, $n['201']['nilai']['C2']);

        // Jam check-in sudah lewat: tamu menunggu, nilai 1
        Carbon::setTestNow('2026-10-06 15:00:00');
        $this->assertSame(1.0, $this->nilai()['201']['nilai']['C1']);
    }

    public function test_c1_tipe_yang_kekurangan_kamar_siap(): void
    {
        // Standard: 8 kamar Siap (101, 102, 104, 106–110); 9 tamu datang hari ini tanpa nomor kamar
        $this->pesan('RSV-0020', null, 1, '2026-10-06', '2026-10-07', 'booked', 9);

        $n = $this->nilai()['105'];
        $this->assertSame(240.0, $n['nilai']['C1']);
        $this->assertSame('Tipe Standard Room kurang 1 kamar untuk tamu hari ini', $n['keterangan']['C1']);
        // Tipe lain tidak terpengaruh
        $this->assertSame(1440.0, $this->nilai()['112']['nilai']['C1']);
    }

    public function test_c3_dari_dirty_since_dan_direset_setelah_dibersihkan(): void
    {
        $n = $this->nilai()['105'];
        $this->assertSame(165.0, $n['nilai']['C3']);
        $this->assertSame('2 jam 45 menit', $n['keterangan']['C3']);

        $this->hanyaRahmatDanRina();
        $this->bagi();
        $t = $this->tugas('105');
        $hk = User::find($t->assignees->first()->user_id);
        app(TugasHousekeepingService::class)->mulai($t, $hk);
        app(TugasHousekeepingService::class)->selesai($t, $hk);
        $this->assertNull($this->kamar('105')->dirty_since);

        Carbon::setTestNow('2026-10-06 11:00:00');
        app(RoomStatusService::class)->change($this->kamar('105'), RoomStatus::VacantDirty, 'Tamu minta ganti seprai', $this->akun('fo'));
        Carbon::setTestNow('2026-10-06 11:30:00');
        $this->assertSame(30.0, $this->nilai()['105']['nilai']['C3']);
    }

    /* ---------- Batch prioritas ---------- */

    public function test_hitung_batch_menyimpan_batch_item_dan_skor(): void
    {
        $batch = $this->hitung();

        $this->assertSame('4 kamar; 1 kamar sedang dibersihkan tidak ikut', $batch->note);
        $this->assertSame([2, 1], [$batch->generated_by, $batch->ahp_session_id]);
        $items = $batch->items()->with('room')->get();
        $this->assertSame(['201', '105', '112', '203'], $items->pluck('room.room_number')->all());
        $this->assertSame([1, 2, 3, 4], $items->pluck('priority_rank')->all());
        $this->assertSame(12, CleaningPriorityScore::count());
        $this->assertNotContains('202', $items->pluck('room.room_number'));
        $this->assertNotContains('103', $items->pluck('room.room_number'));

        // Bobot tersimpan (6 desimal): V 201 = 0,647947 + 0,229871 + 0,122182 · 45/165
        $this->assertSame('0.91114036', $items[0]->preference_score);
        $skor = $items[0]->scores->keyBy('criteria_id');
        $this->assertSame(['240.0000', '1.00000000', '0.64794700'], [$skor[1]->raw_value, $skor[1]->normalized_value, $skor[1]->weighted_value]);
        $this->assertSame(['45.0000', '0.27272727'], [$skor[3]->raw_value, $skor[3]->normalized_value]);

        // Dihitung lagi: batch terbaru yang berlaku
        Carbon::setTestNow('2026-10-06 10:30:00');
        $baru = $this->hitung();
        $this->assertSame($baru->id, CleaningPriorityBatch::terbaru(today())->id);

        // Tanpa kandidat atau tanpa bobot
        DB::table('rooms')->whereIn('status', ['vacant_dirty', 'occupied_dirty'])->update(['status' => 'available', 'dirty_since' => null]);
        $this->galat(fn () => $this->hitung(), 'Tidak ada kamar yang perlu dibersihkan saat ini.');
        DB::table('ahp_sessions')->update(['is_active' => 0]);
        $this->galat(fn () => $this->hitung(), 'Bobot kriteria belum tersedia.');
    }

    public function test_halaman_prioritas_menampilkan_peringkat_dan_perhitungan(): void
    {
        Livewire::actingAs($this->akun('manager'))->test(PrioritasPembersihan::class)
            ->assertSee('Belum ada perhitungan hari ini.')
            ->call('hitung')->assertHasNoErrors()
            ->assertSeeInOrder(['201', '105', '112', '203'])
            ->assertSee('Tamu berikutnya RSV-0008 check-in 14.00')->assertSee('Waktu menuju check-in tamu berikutnya')
            ->assertSee('0,9111')->assertSee('Belum terbagi')->assertSee('oleh Lina Hartono')
            ->set('lihatPerhitungan', true)->assertSee('Matriks keputusan (nilai mentah)')->assertSee('cost: min ÷ x')->assertSee('0,91114036');

        // Kamar kotor baru setelah perhitungan
        app(RoomStatusService::class)->change($this->kamar('204'), RoomStatus::OccupiedDirty, null, $this->akun('fo'));
        Livewire::actingAs($this->akun('manager'))->test(PrioritasPembersihan::class)
            ->assertSee('Ada 1 kamar kotor sejak perhitungan terakhir (204). Hitung ulang?');
    }

    /* ---------- Pembagian tugas ---------- */

    public function test_simpan_pembagian_dan_bagi_ulang_hanya_tugas_assigned(): void
    {
        $this->hanyaRahmatDanRina();
        $this->hitung();

        $usulan = app(PembagianTugasService::class)->usulan(today());
        $this->assertSame(['201', '112'], array_column($usulan['penugasan'][self::RAHMAT], 'nomor'));
        $this->assertSame(['105', '203'], array_column($usulan['penugasan'][self::RINA], 'nomor'));
        $this->assertSame(0, HousekeepingTask::count());

        Livewire::actingAs($this->akun('manager'))->test(PembagianTugas::class)
            ->call('buatUsulan')->assertSee('Usulan')->call('simpan')->assertHasNoErrors();
        $this->assertSame(4, HousekeepingTask::count());
        $t201 = $this->tugas('201');
        $this->assertSame(['assigned', 'greedy', 2, '1.00', self::RAHMAT, '1.00'],
            [$t201->status, $t201->assignment_method, $t201->assigned_by, $t201->workload_point, $t201->assignees->sole()->user_id, $t201->assignees->sole()->workload_share]);
        $this->assertSame('0.50', $this->tugas('203')->workload_point);

        // Rahmat mulai 201; Rina berhalangan, tugasnya yang belum dimulai dibagi ulang ke Rahmat
        app(TugasHousekeepingService::class)->mulai($t201, User::find(self::RAHMAT));
        DB::table('staff_availabilities')->insert(['user_id' => self::RINA, 'work_date' => '2026-10-06', 'is_available' => 0]);
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->assertSee('Rina masih punya 2 tugas hari ini. Bagi ulang tugas?');

        $idSebelum = HousekeepingTask::orderBy('id')->pluck('id')->all();
        $hasil = app(PembagianTugasService::class)->bagiUlang($this->akun('manager'), today());
        $this->assertSame(3, $hasil['dibagi']);
        $this->assertSame($idSebelum, HousekeepingTask::orderBy('id')->pluck('id')->all());
        foreach (['105', '112', '203'] as $kamar) {
            $this->assertSame([self::RAHMAT], $this->tugas($kamar)->assignees->pluck('user_id')->all(), $kamar);
        }
        $this->assertSame(['in_progress', [self::RAHMAT]], [$this->tugas('201')->status, $this->tugas('201')->assignees->pluck('user_id')->all()]);
        $this->assertEqualsWithDelta(3.5, app(PembagianTugasService::class)->staf(today())->firstWhere('id', self::RAHMAT)['beban'], 1e-9);
    }

    public function test_pindahkan_dan_verifikasi(): void
    {
        $this->hanyaRahmatDanRina();
        DB::table('staff_availabilities')->insert(['user_id' => self::RAHMAT, 'work_date' => '2026-10-06', 'is_available' => 1, 'max_workload' => 2]);
        $this->bagi();

        $peringatan = app(PembagianTugasService::class)->pindahkan($this->tugas('105'), User::find(self::RAHMAT), $this->akun('manager'));
        $this->assertSame('Beban Rahmat Hidayat menjadi 3 dari batas 2.', $peringatan);
        $t = $this->tugas('105');
        $this->assertSame(['manual', 'Dipindah dari Rina Wulandari ke Rahmat Hidayat oleh Lina Hartono'], [$t->assignment_method, $t->note]);

        $rahmat = User::find(self::RAHMAT);
        app(TugasHousekeepingService::class)->mulai($t, $rahmat);
        $this->galat(fn () => app(PembagianTugasService::class)->pindahkan($t, User::find(self::RINA), $this->akun('manager')),
            'Hanya tugas yang belum dimulai yang bisa dipindahkan.');
        app(TugasHousekeepingService::class)->selesai($t, $rahmat);
        app(PembagianTugasService::class)->verifikasi($t, $this->akun('manager'));
        $this->assertSame(['verified', 2], [$t->fresh()->status, $t->fresh()->verified_by]);
    }

    /* ---------- Pelaksanaan oleh Housekeeping ---------- */

    public function test_hk_mulai_dan_selesai_lewat_tugas_saya(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();

        Livewire::actingAs($this->akun('hk'))->test(TugasSaya::class)
            ->assertSee('0 dari 2 kamar selesai · beban 2 dari 4')->assertSee('Tamu berikutnya RSV-0008 check-in 14.00')
            ->call('mulai', $this->tugas('201')->id)->assertHasNoErrors()
            ->call('bukaSelesai', $this->tugas('201')->id)->set('catatan', 'Seprai diganti')->call('selesai')->assertHasNoErrors()
            ->assertSee('1 dari 2 kamar selesai');

        $this->assertSame(RoomStatus::Available, $this->kamar('201')->status);
        $this->assertSame(['completed', 'Seprai diganti'], [$this->tugas('201')->status, $this->tugas('201')->note]);
        $log = RoomStatusLog::where('room_id', $this->kamar('201')->id)->orderBy('id')->get()->slice(-2)->values();
        $this->assertSame([['cleaning', self::RAHMAT], ['available', self::RAHMAT]],
            $log->map(fn ($l) => [$l->status->value, $l->changed_by])->all());

        // Tamu masih menginap: selesai menjadi Terisi
        $rina = User::find(self::RINA);
        app(TugasHousekeepingService::class)->mulai($this->tugas('203'), $rina);
        app(TugasHousekeepingService::class)->selesai($this->tugas('203'), $rina);
        $this->assertSame(RoomStatus::OccupiedClean, $this->kamar('203')->status);
    }

    public function test_selesai_pada_kamar_dengan_laporan_kerusakan_penahan_menjadi_perbaikan(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();
        app(TugasHousekeepingService::class)->mulai($this->tugas('201'), User::find(self::RAHMAT));

        // Saat dibersihkan, Rahmat menemukan AC rusak
        $this->actingAs($this->akun('hk'))->postJson(route('staf.maintenance.lapor.store'), [
            'lokasi' => 'kamar', 'room_id' => $this->kamar('201')->id, 'unit' => 'lainnya',
            'deskripsi' => 'AC tidak menyala', 'priority' => 'high', 'tidak_bisa_dipakai' => '1',
        ])->assertOk();
        $this->assertSame(RoomStatus::Cleaning, $this->kamar('201')->status);

        Livewire::actingAs($this->akun('hk'))->test(TugasSaya::class)
            ->call('bukaSelesai', $this->tugas('201')->id)->call('selesai')
            ->assertDispatched('toast', peringatan: true);
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('201')->status);
        $this->assertSame('completed', $this->tugas('201')->status);
    }

    public function test_hk_tidak_bisa_memulai_dua_tugas_atau_tugas_orang_lain(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();
        $layanan = app(TugasHousekeepingService::class);
        $rahmat = User::find(self::RAHMAT);

        $layanan->mulai($this->tugas('201'), $rahmat);
        $this->galat(fn () => $layanan->mulai($this->tugas('112'), $rahmat), 'Selesaikan dulu kamar 201 sebelum memulai kamar lain.');
        $this->galat(fn () => $layanan->mulai($this->tugas('105'), $rahmat), 'Tugas ini bukan untuk Anda.');
        $this->galat(fn () => $layanan->mulai($this->tugas('105'), $this->akun('manager')), 'Tugas ini bukan untuk Anda.');

        // Lewat halaman, tugas orang lain tidak ditemukan
        Livewire::actingAs($this->akun('hk'))->test(TugasSaya::class)->call('mulai', $this->tugas('105')->id)->assertNotFound();
        $this->assertSame('assigned', $this->tugas('105')->status);
    }

    public function test_sinkron_papan_status(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();

        // HK menandai Sedang dibersihkan dari papan
        Livewire::actingAs($this->akun('hk'))->test(RoomStatusBoard::class)
            ->call('open', $this->kamar('112')->id)->set('status', 'cleaning')->call('save')->assertHasNoErrors();
        $this->assertSame('in_progress', $this->tugas('112')->status);
        Livewire::actingAs($this->akun('hk'))->test(RoomStatusBoard::class)
            ->call('open', $this->kamar('112')->id)->set('status', 'available')->call('save')->assertHasNoErrors();
        $this->assertSame('completed', $this->tugas('112')->status);

        // FO menandai Perbaikan: tugas yang belum dimulai dibatalkan
        Livewire::actingAs($this->akun('fo'))->test(RoomStatusBoard::class)
            ->call('open', $this->kamar('105')->id)->set('status', 'maintenance')->set('note', 'Keran bocor')->call('save')->assertHasNoErrors();
        $this->assertSame(['cancelled', 'Dibatalkan: kamar masuk Perbaikan'], [$this->tugas('105')->status, $this->tugas('105')->note]);
    }

    public function test_tamu_keluar_dari_kamar_terisi_kotor_poin_tugas_menjadi_kosong_kotor(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();
        $t = $this->tugas('203');
        $penerima = $t->assignees->first()->user_id;
        $this->assertSame(['assigned', '0.50', '0.50'], [$t->status, $t->workload_point, $t->assignees->first()->workload_share]);
        $bebanAwal = app(PembagianTugasService::class)->staf(today())->firstWhere('id', $penerima)['beban'];

        // Tamu RSV-0012 pindah dari 203 ke 209: kamar 203 menjadi kosong kotor
        $baris = ReservationRoom::where('room_id', $this->kamar('203')->id)->where('status', 'checked_in')->firstOrFail();
        app(ReservasiService::class)->pindahKamar($baris, $this->kamar('209'), null, $this->akun('fo'));

        $this->assertSame(RoomStatus::VacantDirty, $this->kamar('203')->status);
        $t = $this->tugas('203');
        $this->assertSame(['assigned', '1.00', '1.00', $penerima], [$t->status, $t->workload_point, $t->assignees->first()->workload_share, $t->assignees->first()->user_id]);
        $this->assertSame($bebanAwal + 0.5, app(PembagianTugasService::class)->staf(today())->firstWhere('id', $penerima)['beban']);
    }

    public function test_sinkron_kosong_kotor_tidak_mengubah_tugas_yang_sudah_dimulai_atau_kamar_lain(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();
        $layanan = app(TugasHousekeepingService::class);
        $layanan->mulai($this->tugas('203'), User::find($this->tugas('203')->assignees->first()->user_id));

        // Tugas in_progress tidak disentuh; tugas 0,5 untuk kamar lain juga tidak
        $layanan->sinkron($this->kamar('203'), RoomStatus::VacantDirty, $this->akun('fo'));
        $this->assertSame(['in_progress', '0.50'], [$this->tugas('203')->status, $this->tugas('203')->workload_point]);
        $layanan->sinkron($this->kamar('105'), RoomStatus::VacantDirty, $this->akun('fo'));
        $this->assertSame(['assigned', '1.00'], [$this->tugas('105')->status, $this->tugas('105')->workload_point]);
    }

    public function test_tugas_kemarin_yang_belum_dimulai_dibatalkan_otomatis(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();
        $rahmat = User::find(self::RAHMAT);
        app(TugasHousekeepingService::class)->mulai($this->tugas('201'), $rahmat);
        $this->assertSame(4, HousekeepingTask::whereDate('task_date', '2026-10-06')->count());

        // Terjadwal harian pukul 00.05
        $jadwal = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'housekeeping:batalkan-tugas-lama'));
        $this->assertSame('5 0 * * *', $jadwal?->expression);

        // Sebelum pergantian hari tidak ada yang dibatalkan
        $this->artisan('housekeeping:batalkan-tugas-lama')->expectsOutput('0 tugas Housekeeping hari sebelumnya dibatalkan.')->assertSuccessful();

        Carbon::setTestNow('2026-10-07 00:05:00');
        $this->artisan('housekeeping:batalkan-tugas-lama')->expectsOutput('3 tugas Housekeeping hari sebelumnya dibatalkan.')->assertSuccessful();

        foreach (['105', '112', '203'] as $kamar) {
            $this->assertSame(['cancelled', 'Dibatalkan: tidak dikerjakan pada tanggalnya'], [$this->tugas($kamar)->status, $this->tugas($kamar)->note]);
        }
        $this->assertSame('in_progress', $this->tugas('201')->status);

        // Kamar yang masih kotor ikut perhitungan prioritas berikutnya dan bisa dibagi lagi hari ini
        Carbon::setTestNow('2026-10-07 08:00:00');
        $batch = $this->hitung();
        $this->assertEqualsCanonicalizing(['105', '112', '203'], $batch->items()->with('room')->get()->pluck('room.room_number')->all());
        app(PembagianTugasService::class)->simpan($this->akun('manager'), today());
        $this->assertSame(3, HousekeepingTask::whereDate('task_date', '2026-10-07')->where('status', 'assigned')->count());

        // Tugas yang sudah dimulai kemarin tampil untuk Manager
        Livewire::actingAs($this->akun('manager'))->test(PembagianTugas::class)
            ->assertSee('Belum selesai kemarin (1)')->assertSeeInOrder(['Belum selesai kemarin', '201', 'Rahmat Hidayat', '6 Okt']);

        // Rahmat masih melihatnya di Tugas Saya dan bisa menyelesaikannya
        Livewire::actingAs($this->akun('hk'))->test(TugasSaya::class)
            ->assertSee('Belum selesai sejak 6 Okt')
            ->call('bukaSelesai', $this->tugas('201')->id)->call('selesai')->assertHasNoErrors();
        $this->assertSame(['completed', RoomStatus::Available], [$this->tugas('201')->status, $this->kamar('201')->status]);
        Livewire::actingAs($this->akun('manager'))->test(PembagianTugas::class)->assertDontSee('Belum selesai kemarin');
    }

    public function test_tugas_kemarin_yang_sudah_dimulai_ikut_selesai_lewat_papan_status(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();
        app(TugasHousekeepingService::class)->mulai($this->tugas('201'), User::find(self::RAHMAT));

        Carbon::setTestNow('2026-10-07 09:00:00');
        Livewire::actingAs($this->akun('hk'))->test(RoomStatusBoard::class)
            ->call('open', $this->kamar('201')->id)->set('status', 'available')->call('save')->assertHasNoErrors();
        $this->assertSame('completed', $this->tugas('201')->status);
    }

    public function test_kamar_bisa_mendapat_tugas_kedua_di_hari_yang_sama(): void
    {
        $this->hanyaRahmatDanRina();
        $this->bagi();
        $rahmat = User::find(self::RAHMAT);
        app(TugasHousekeepingService::class)->mulai($this->tugas('201'), $rahmat);
        app(TugasHousekeepingService::class)->selesai($this->tugas('201'), $rahmat);

        Carbon::setTestNow('2026-10-06 13:00:00');
        app(RoomStatusService::class)->change($this->kamar('201'), RoomStatus::VacantDirty, 'Perlu dibersihkan ulang', $this->akun('fo'));
        $this->bagi();

        $this->assertSame(2, HousekeepingTask::where('room_id', $this->kamar('201')->id)->whereDate('task_date', '2026-10-06')->count());
        $this->assertSame('assigned', $this->tugas('201')->status);
    }

    /* ---------- Hak akses dan dasbor ---------- */

    public function test_hak_akses_halaman_modul_6(): void
    {
        $manager = ['staf.housekeeping.prioritas', 'staf.housekeeping.bobot', 'staf.housekeeping.ketersediaan', 'staf.housekeeping.pembagian'];
        foreach (['admin', 'fo'] as $akun) {
            foreach ([...$manager, 'staf.housekeeping.tugas-saya'] as $r) {
                $this->actingAs($this->akun($akun))->get(route($r))->assertForbidden();
            }
        }
        foreach ($manager as $r) {
            $this->actingAs($this->akun('hk'))->get(route($r))->assertForbidden();
            $this->actingAs($this->akun('manager'))->get(route($r))->assertOk();
        }
        $this->actingAs($this->akun('hk'))->get(route('staf.housekeeping.tugas-saya'))->assertOk();
        $this->actingAs($this->akun('manager'))->get(route('staf.housekeeping.tugas-saya'))->assertForbidden();

        // Menu Housekeeping sudah berupa tautan, bukan halaman "segera"
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))
            ->assertSee(route('staf.housekeeping.prioritas'))->assertDontSee(route('staf.segera', 'prioritas-pembersihan'));
    }

    public function test_kartu_dasbor_housekeeping(): void
    {
        $this->hanyaRahmatDanRina();
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))
            ->assertSeeInOrder(['Kamar kotor belum dibersihkan', '4', 'Tugas belum dimulai', '0', 'Belum terbagi', '4']);

        $this->bagi();
        app(TugasHousekeepingService::class)->mulai($this->tugas('201'), User::find(self::RAHMAT));
        app(TugasHousekeepingService::class)->selesai($this->tugas('201'), User::find(self::RAHMAT));
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))
            ->assertSeeInOrder(['Kamar kotor belum dibersihkan', '3', 'Tugas belum dimulai', '3', 'Belum terbagi', '0']);
        $this->actingAs($this->akun('hk'))->get(route('staf.dashboard'))->assertSeeInOrder(['Tugas saya hari ini', '1 / 2']);
    }

    public function test_ketersediaan_staf_disimpan_per_tanggal(): void
    {
        // Batas staf yang tidak hadir dikosongkan saat disimpan, jadi 0,3 pada Rina tidak lagi ditolak
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->set('baris.'.self::RINA.'.hadir', false)
            ->set('baris.'.self::RINA.'.batas', '0,3')
            ->set('baris.'.self::RAHMAT.'.batas', '0,3')
            ->call('simpan')->assertHasErrors(['baris.'.self::RAHMAT.'.batas'])
            ->set('baris.'.self::RAHMAT.'.batas', '3,5')
            ->call('simpan')->assertHasNoErrors();

        $staf = app(PembagianTugasService::class)->staf(today())->keyBy('id');
        // Tugas rangkap dibaca dari peran: Rahmat HK murni
        $this->assertSame([true, 3.5, null], [$staf[self::RAHMAT]['hadir'], $staf[self::RAHMAT]['batas'], $staf[self::RAHMAT]['rangkap']]);
        $this->assertSame([false, 4.0], [$staf[self::RINA]['hadir'], $staf[self::RINA]['batas']]);
        $this->assertSame(1, DB::table('staff_availabilities')->where('user_id', self::RINA)->count());
    }
}
