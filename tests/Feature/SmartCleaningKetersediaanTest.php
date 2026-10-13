<?php

namespace Tests\Feature;

use App\Livewire\Staf\Housekeeping\KetersediaanStaf;
use App\Models\CleaningPriorityItem;
use App\Models\HousekeepingTaskAssignee;
use App\Models\StaffAvailability;
use App\Services\SmartCleaning\NilaiKriteria;
use App\Services\SmartCleaning\PembagianTugasService;
use App\Services\SmartCleaning\PrioritasPembersihanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Smart Cleaning: Ketersediaan Staf (kolom opsional, batas beban kosong = NULL, staf tidak hadir, tanggal lewat, sudah dibagi) dan
 * kriteria C1 setelah lewat jam check-in. Skor SAW tidak berubah.
 */
class SmartCleaningKetersediaanTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- Bantuan ---------------- */

    /** Layar Ketersediaan Staf hari ini; hanya Rahmat dan Rina yang hadir kecuali diubah. */
    private function layar()
    {
        $l = Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class);
        foreach ([8, 9, 13] as $id) {
            $l->set("baris.{$id}.hadir", false);
        }

        return $l;
    }

    private function bagi(): void
    {
        app(PrioritasPembersihanService::class)->hitungBatch($this->akun('manager'));
        app(PembagianTugasService::class)->simpan($this->akun('manager'), today());
    }

    private function beban(int $staf): float
    {
        return (float) HousekeepingTaskAssignee::where('user_id', $staf)->sum('workload_share');
    }

    private function pesan(string $kode, string $kamar, string $status, string $statusReservasi = 'confirmed'): void
    {
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => 'Tamu '.$kode, 'phone' => '0813'.random_int(10000000, 99999999)]);
        $rsv = DB::table('reservations')->insertGetId(['reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'walk_in', 'status' => $statusReservasi]);
        DB::table('reservation_rooms')->insert(['reservation_id' => $rsv, 'room_type_id' => $this->kamar($kamar)->room_type_id, 'room_id' => $this->kamar($kamar)->id,
            'check_in_date' => '2026-10-06', 'check_out_date' => '2026-10-08', 'price_per_night' => 460000, 'status' => $status]);
    }

    private function c1(string $kamar): float
    {
        return collect(app(NilaiKriteria::class)->hitung(now()))->first(fn ($m) => $m['room']->room_number === $kamar)['nilai']['C1'];
    }

    /* ---------------- Ketersediaan Staf ---------------- */

    public function test_KS01_batas_beban_kosong_tersimpan_null_dan_greedy_memakai_batas_otomatis(): void
    {
        $this->layar()->set('baris.'.self::RAHMAT.'.batas', '')->call('simpan')->assertHasNoErrors();

        $this->assertNull(StaffAvailability::where('user_id', self::RAHMAT)->value('max_workload'));
        $staf = app(PembagianTugasService::class)->staf(today())->firstWhere('id', self::RAHMAT);
        $this->assertSame([4.0, 'murni', 'HK murni'], [$staf['batas'], $staf['jenisBatas'], $staf['sumberBatas']]);

        // Teks bantuan, placeholder, dan Greedy membaca angka yang sama dari settings
        DB::table('settings')->where('setting_key', 'hk_max_workload')->update(['value' => '5']);
        Cache::forget('settings.all');
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->assertSee('Kosongkan bila tidak ada perubahan. Batas otomatis: HK murni 5 poin, multi-role 2 poin.')
            ->assertSee('placeholder="5 (otomatis)"', false)->assertSee('Poin per kamar: kosong kotor 1 poin, terisi kotor 0,5 poin.');
        $this->assertSame(5.0, app(PembagianTugasService::class)->staf(today())->firstWhere('id', self::RAHMAT)['batas']);
    }

    public function test_KS02_batas_beban_2_dipakai_greedy_dan_staf_tidak_melebihinya(): void
    {
        // 105, 112, 201 kosong kotor (1 poin) dan 203 terisi kotor (0,5 poin) = 3,5 poin
        $this->layar()->set('baris.'.self::RAHMAT.'.batas', '2')->call('simpan')->assertHasNoErrors();
        $this->assertSame('2.00', StaffAvailability::where('user_id', self::RAHMAT)->value('max_workload'));

        $this->bagi();
        $this->assertGreaterThan(0.0, $this->beban(self::RAHMAT));
        $this->assertLessThanOrEqual(2.0, $this->beban(self::RAHMAT));
        $this->assertEqualsWithDelta(3.5, $this->beban(self::RAHMAT) + $this->beban(self::RINA), 1e-9);

        // Pembagian Tugas menampilkan batas yang dipakai tiap staf beserta sumber dan persentasenya
        $this->actingAs($this->akun('manager'))->get(route('staf.housekeeping.pembagian'))->assertOk()
            ->assertSee('/ 2 poin')->assertSee('manual')->assertSee('/ 4 poin')->assertSee('HK murni')->assertSee('data-persen', false);
    }

    public function test_KS03_staf_tidak_hadir_tidak_mendapat_tugas_walau_batas_sempat_terisi(): void
    {
        $this->layar()
            ->set('baris.'.self::RINA.'.batas', '3')->set('baris.'.self::RINA.'.catatan', 'Izin keluarga')
            ->set('baris.'.self::RINA.'.hadir', false)
            ->call('simpan')->assertHasNoErrors();

        $k = StaffAvailability::where('user_id', self::RINA)->first();
        $this->assertSame([false, null, 'Izin keluarga'], [$k->is_available, $k->max_workload, $k->note]);

        $this->bagi();
        $this->assertSame(0, HousekeepingTaskAssignee::where('user_id', self::RINA)->count());
        $this->assertGreaterThan(0, HousekeepingTaskAssignee::where('user_id', self::RAHMAT)->count());
    }

    public function test_KS04_batas_beban_di_luar_rentang_ditolak(): void
    {
        $kunci = 'baris.'.self::RAHMAT.'.batas';
        foreach (['0,3' => 'Batas beban 0,5 sampai 20 poin.', '25' => 'Batas beban 0,5 sampai 20 poin.',
            '2,3' => 'Isi kelipatan 0,5, misalnya 2 atau 2,5.', 'dua' => 'Isi angka, misalnya 2 atau 2,5.'] as $isi => $pesan) {
            $l = $this->layar()->set($kunci, $isi)->call('simpan')->assertHasErrors([$kunci]);
            $this->assertSame($pesan, $l->errors()->first($kunci), "Isian {$isi}");
        }
        $this->assertSame(0, StaffAvailability::count());
        $this->layar()->set($kunci, '20')->call('simpan')->assertHasNoErrors();
        $this->layar()->set($kunci, '0,5')->call('simpan')->assertHasNoErrors();
    }

    /* ---------------- Tanggal ---------------- */

    public function test_KS05_tanggal_bawaan_hari_ini_dengan_tombol_hari_ini_dan_besok(): void
    {
        // Zona waktu aplikasi WITA: pukul 23.30 WITA tetap tanggal 6, walau UTC sudah lewat tengah hari
        $this->assertSame('Asia/Makassar', config('app.timezone'));
        Carbon::setTestNow(Carbon::parse('2026-10-06 23:30:00', 'Asia/Makassar'));

        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->assertSet('tanggal', '2026-10-06')
            ->assertSeeHtml("wire:click=\"\$set('tanggal', '2026-10-06')\"")->assertSeeHtml("wire:click=\"\$set('tanggal', '2026-10-07')\"")
            ->assertSee('Hari ini')->assertSee('Besok')->assertSee('Simpan ketersediaan')->assertDontSee('Tanggal sudah lewat')
            ->set('tanggal', '2026-10-07')->assertSet('tanggal', '2026-10-07')
            // Lebih dari 7 hari ke depan kembali ke hari ini
            ->set('tanggal', '2026-10-20')->assertSet('tanggal', '2026-10-06');
    }

    public function test_KS06_tanggal_lewat_hanya_dilihat(): void
    {
        DB::table('staff_availabilities')->insert(['user_id' => self::RINA, 'work_date' => '2026-10-05', 'is_available' => 0, 'note' => 'Sakit']);

        $l = Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->set('tanggal', '2026-10-05')->assertSet('tanggal', '2026-10-05')
            ->assertSet('baris.'.self::RINA.'.hadir', false)->assertSet('baris.'.self::RINA.'.catatan', 'Sakit')
            ->assertSee('Tanggal sudah lewat')->assertDontSee('Simpan ketersediaan')->assertDontSee('kembalikan ke otomatis')
            ->assertSeeHtml('aria-label="Catatan Rahmat Hidayat" disabled');

        // Simpan dari luar tombol pun ditolak: data tanggal lewat tidak berubah
        $l->set('baris.'.self::RINA.'.catatan', 'Diubah')->set('baris.'.self::RAHMAT.'.batas', '3')->call('simpan')->assertDispatched('toast');
        $this->assertSame(['Sakit'], StaffAvailability::whereDate('work_date', '2026-10-05')->pluck('note')->all());
    }

    public function test_KS07_peringatan_bila_tugas_tanggal_itu_sudah_dibagi(): void
    {
        $pesan = 'Tugas tanggal ini sudah dibagi. Perubahan baru berlaku bila pembagian tugas diulang.';
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)->assertDontSee($pesan);

        $this->bagi();
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)->assertSee($pesan)
            // Tanggal lain (besok) belum dibagi
            ->set('tanggal', '2026-10-07')->assertDontSee($pesan);
    }

    /* ---------------- Kriteria C1 ---------------- */

    public function test_C101_pukul_10_kedatangan_pukul_14_bernilai_240_menit(): void
    {
        $this->pesan('RSV-0101', '201', 'booked');
        $this->assertSame(240.0, $this->c1('201'));
        $this->assertSame((float) NilaiKriteria::C1_MAKS, $this->c1('105'));
    }

    public function test_C102_pukul_15_tamu_belum_check_in_kamar_tetap_peringkat_pertama(): void
    {
        $this->pesan('RSV-0102', '201', 'booked');
        Carbon::setTestNow('2026-10-06 15:00:00');

        $this->assertSame(1.0, $this->c1('201'));
        $batch = app(PrioritasPembersihanService::class)->hitungBatch($this->akun('manager'));
        $pertama = CleaningPriorityItem::where('cleaning_priority_batch_id', $batch->id)->orderBy('priority_rank')->first();
        $this->assertSame('201', $pertama->room->room_number);
        $this->assertSame(1, (int) $pertama->priority_rank);
    }

    public function test_C103_pukul_15_tamu_sudah_check_in_batal_atau_tidak_datang_tidak_dihitung(): void
    {
        Carbon::setTestNow('2026-10-06 15:00:00');
        $this->pesan('RSV-0103', '201', 'checked_in', 'checked_in');
        $this->assertSame(1440.0, $this->c1('201'));

        $this->pesan('RSV-0104', '105', 'cancelled', 'cancelled');
        $this->pesan('RSV-0105', '112', 'booked', 'no_show');
        $this->assertSame([1440.0, 1440.0], [$this->c1('105'), $this->c1('112')]);
    }

    public function test_C104_tiga_kamar_tanpa_kedatangan_sama_dengan_batch_5_oktober(): void
    {
        // Hanya tiga kamar kosong kotor, masing-masing kotor 202, 90, dan 42 menit pada pukul 10.00
        DB::table('rooms')->whereNotIn('room_number', ['105', '112', '201'])->where('status', '!=', 'maintenance')->update(['status' => 'available', 'dirty_since' => null]);
        DB::table('rooms')->where('room_number', '105')->update(['status' => 'vacant_dirty', 'dirty_since' => '2026-10-06 06:38:00']);
        DB::table('rooms')->where('room_number', '112')->update(['status' => 'vacant_dirty', 'dirty_since' => '2026-10-06 08:30:00']);
        DB::table('rooms')->where('room_number', '201')->update(['status' => 'vacant_dirty', 'dirty_since' => '2026-10-06 09:18:00']);

        $batch = app(PrioritasPembersihanService::class)->hitungBatch($this->akun('manager'));
        $skor = CleaningPriorityItem::with('room')->where('cleaning_priority_batch_id', $batch->id)->orderBy('priority_rank')->get()
            ->mapWithKeys(fn ($i) => [$i->room->room_number => (string) $i->preference_score])->all();
        $this->assertSame(['105' => '1.00000000', '112' => '0.93225552', '201' => '0.90322218'], $skor);
    }
}
