<?php

namespace Tests\Feature;

use App\Http\Controllers\Staf\Admin\PengaturanController;
use App\Livewire\Staf\Housekeeping\KetersediaanStaf;
use App\Models\Setting;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\SmartCleaning\BatasBebanStaf;
use App\Services\SmartCleaning\PembagianTugasService;
use App\Services\SmartCleaning\PrioritasPembersihanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Batas beban otomatis Housekeeping: isian manual, multi-role (HK + F&B/FO), atau HK murni dari settings; tugas rangkap
 * dibaca dari peran (user_roles). Greedy berdasarkan rasio (beban + poin) / batas di balik saklar housekeeping.greedy_rasio.
 */
class SmartCleaningBatasBebanTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    // Data contoh: Rahmat dan Rina HK murni, Dewi dan Anto F&B + HK, Sari FO + F&B (tanpa HK), "Uji FO dan HK"
    private const RAHMAT = 6;

    private const RINA = 7;

    private const DEWI = 8;

    private const ANTO = 9;

    private const SARI = 10;

    private const UJI_FO_HK = 13;

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

    private function batas(int $id, ?Carbon $tanggal = null): array
    {
        return BatasBebanStaf::untuk(User::findOrFail($id), $tanggal ?? today());
    }

    private function ketersediaan(int $id, array $isi): void
    {
        DB::table('staff_availabilities')->insert(['user_id' => $id, 'work_date' => '2026-10-06', 'is_available' => 1, ...$isi]);
    }

    private function setting(string $kunci, string $nilai): void
    {
        DB::table('settings')->where('setting_key', $kunci)->update(['value' => $nilai]);
        Cache::forget('settings.all');
    }

    /* ---------------- Aturan batas ---------------- */

    public function test_BB01_hk_murni_dan_multi_role_tanpa_isian_memakai_batas_otomatis(): void
    {
        $this->assertSame([4.0, 'HK murni'], $this->batas(self::RAHMAT));
        $this->assertSame([2.0, 'Multi-role (HK + F&B)'], $this->batas(self::ANTO));
        $this->assertSame([2.0, 'Multi-role (HK + F&B)'], $this->batas(self::DEWI));
        $this->assertSame([2.0, 'Multi-role (HK + Front Office)'], $this->batas(self::UJI_FO_HK));
        // Peran Admin dan Manager tidak dihitung: staf HK yang juga Manager tetap HK murni
        DB::table('user_roles')->insert([['user_id' => self::RINA, 'role_id' => 1], ['user_id' => self::RINA, 'role_id' => 2]]);
        $this->assertSame([4.0, 'HK murni'], $this->batas(self::RINA));
    }

    public function test_BB02_isian_manual_mengalahkan_batas_otomatis(): void
    {
        $this->ketersediaan(self::ANTO, ['max_workload' => 3]);
        $this->assertSame([3.0, 'Manual'], $this->batas(self::ANTO));
        // Hanya untuk tanggal itu
        $this->assertSame([2.0, 'Multi-role (HK + F&B)'], $this->batas(self::ANTO, today()->addDay()));
    }

    public function test_BB03_settings_multi_role_15_dipakai_anto_dan_dewi(): void
    {
        $this->setting('hk_max_workload_multirole', '1.5');
        $staf = app(PembagianTugasService::class)->staf(today())->keyBy('id');
        $this->assertSame([1.5, 1.5, 4.0], [$staf[self::ANTO]['batas'], $staf[self::DEWI]['batas'], $staf[self::RAHMAT]['batas']]);
        // Batas kosong tersimpan NULL, jadi tanggal lain juga ikut nilai baru
        $this->assertSame(1.5, $this->batas(self::DEWI, today()->addDays(3))[0]);
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->assertSee('Batas otomatis: HK murni 4 poin, multi-role 1,5 poin.')->assertSee('placeholder="1,5 (otomatis)"', false);
    }

    /* ---------------- Halaman Ketersediaan Staf ---------------- */

    public function test_BB04_tugas_rangkap_tampil_dari_peran_dan_staf_tanpa_hk_tidak_muncul(): void
    {
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->assertSee('Kosongkan bila tidak ada perubahan. Batas otomatis: HK murni 4 poin, multi-role 2 poin.')
            ->assertDontSee('merangkap tugas hari ini')
            ->assertSeeHtml('data-rangkap="'.self::ANTO.'">F&amp;B</span>')
            ->assertSeeHtml('data-rangkap="'.self::DEWI.'">F&amp;B</span>')
            ->assertSeeHtml('data-rangkap="'.self::RAHMAT.'">—</span>')
            ->assertSeeHtml('data-rangkap="'.self::RINA.'">—</span>')
            ->assertSeeHtml('data-rangkap="'.self::UJI_FO_HK.'">Front Office</span>')
            ->assertSee('placeholder="2 (otomatis)"', false)->assertSee('placeholder="4 (otomatis)"', false)
            ->assertSeeHtml('data-sumber-batas="'.self::ANTO.'" data-awal="otomatis"')
            ->assertSee('Otomatis · multi-role')->assertSee('Otomatis · HK murni')
            // Sari (FO + F&B) bukan staf Housekeeping
            ->assertDontSee('Sari Puspita')->assertSee('Anto Saputra');
        $this->assertNotContains(self::SARI, PembagianTugasService::stafHousekeeping()->pluck('id')->all());
    }

    public function test_BB05_isian_3_tersimpan_manual_dan_isian_sama_dengan_otomatis_tersimpan_null(): void
    {
        $l = Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->set('baris.'.self::ANTO.'.batas', '3')
            ->set('baris.'.self::RAHMAT.'.batas', '4')
            ->call('simpan')->assertHasNoErrors();
        $this->assertSame('3.00', StaffAvailability::where('user_id', self::ANTO)->value('max_workload'));
        $this->assertNull(StaffAvailability::where('user_id', self::RAHMAT)->value('max_workload'));
        $l->assertSeeHtml('data-sumber-batas="'.self::ANTO.'" data-awal="manual"')->assertSee('kembalikan ke otomatis')
            ->assertSeeHtml('data-sumber-batas="'.self::RAHMAT.'" data-awal="otomatis"')
            ->assertSet('baris.'.self::RAHMAT.'.batas', '');

        // Isian 2 untuk Anto sama dengan batas otomatis multi-role: kembali NULL
        $l->set('baris.'.self::ANTO.'.batas', '2')->call('simpan')->assertHasNoErrors()
            ->assertSeeHtml('data-sumber-batas="'.self::ANTO.'" data-awal="otomatis"');
        $this->assertNull(StaffAvailability::where('user_id', self::ANTO)->value('max_workload'));
        $this->assertSame([2.0, 'Multi-role (HK + F&B)'], $this->batas(self::ANTO));
    }

    public function test_BB06_staf_tidak_hadir_batas_nonaktif_catatan_tetap(): void
    {
        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)
            ->set('baris.'.self::RINA.'.hadir', false)->set('baris.'.self::RINA.'.batas', '3')->set('baris.'.self::RINA.'.catatan', 'Izin keluarga')
            ->call('simpan')->assertHasNoErrors()
            ->assertSeeHtml('data-sumber-batas="'.self::RINA.'" data-awal="tidak-hadir"')
            ->assertSeeHtml('aria-label="Batas beban Rina Wulandari (tidak hadir)"');
        $k = StaffAvailability::where('user_id', self::RINA)->first();
        $this->assertSame([false, null, 'Izin keluarga'], [$k->is_available, $k->max_workload, $k->note]);
    }

    /* ---------------- Pengaturan ---------------- */

    public function test_BB07_pengaturan_housekeeping_dua_angka_dan_peringatan_tidak_memblokir(): void
    {
        $this->actingAs($this->akun('admin'))->get(route('staf.pengaturan'))->assertOk()
            ->assertSee('Batas beban staf HK murni')->assertSee('Batas beban staf multi-role')
            ->assertDontSee('merangkap hari itu')->assertSee('data-peringatan-multirole', false)
            ->assertSee('style="display: none;"', false);

        $isi = Setting::all()->pluck('value', 'setting_key')->merge(['hk_max_workload' => '4', 'hk_max_workload_multirole' => '5'])->all();
        $this->actingAs($this->akun('admin'))->putJson(route('staf.pengaturan.update'), $isi)->assertOk()
            ->assertJsonPath('pesan', 'Pengaturan disimpan (1 diubah). '.PengaturanController::PERINGATAN_MULTIROLE);
        $this->assertSame([5.0, 'Multi-role (HK + F&B)'], $this->batas(self::ANTO));

        // Multi-role tidak melebihi HK murni: tanpa peringatan
        $isi['hk_max_workload_multirole'] = '2';
        $this->actingAs($this->akun('admin'))->putJson(route('staf.pengaturan.update'), $isi)->assertOk()
            ->assertJsonPath('pesan', 'Pengaturan disimpan (1 diubah).');
    }

    /* ---------------- File SQL ---------------- */

    /** Menjalankan pernyataan DML dari file SQL (tanpa ALTER, supaya transaksi RefreshDatabase tidak ter-commit). */
    private function jalankanSql(string $file): string
    {
        $isi = file_get_contents(database_path('sql/'.$file));
        $tanpaKomentar = preg_replace('/^\s*--.*$/m', '', $isi);
        foreach (array_filter(array_map('trim', explode(';', $tanpaKomentar))) as $perintah) {
            if (! str_starts_with(strtoupper($perintah), 'ALTER')) {
                DB::statement($perintah);
            }
        }

        return $isi;
    }

    public function test_BB08_file_sql_mengganti_settings_lama_dan_mengosongkan_batas_yang_sama_dengan_otomatis(): void
    {
        // Keadaan setelah versi pertama file SQL: multi-role 3 dan settings rangkap
        $this->setting('hk_max_workload_multirole', '3');
        DB::table('settings')->insert(['setting_key' => 'hk_max_workload_rangkap', 'value' => '2', 'type' => 'decimal']);
        $this->jalankanSql('2026-10-13-batas-beban-hk.sql');
        $this->assertSame(['hk_max_workload' => '4', 'hk_max_workload_multirole' => '2'],
            DB::table('settings')->where('setting_key', 'like', 'hk_max_workload%')->orderBy('setting_key')->pluck('value', 'setting_key')->all());

        foreach ([[self::ANTO, '2026-10-06', 2], [self::ANTO, '2026-10-07', 3], [self::RAHMAT, '2026-10-06', 4], [self::RAHMAT, '2026-10-07', 2]] as [$id, $tgl, $batas]) {
            DB::table('staff_availabilities')->insert(['user_id' => $id, 'work_date' => $tgl, 'is_available' => 1, 'max_workload' => $batas]);
        }
        $isi = $this->jalankanSql('2026-10-13-hapus-tugas-rangkap.sql');
        $this->assertSame(['9 2026-10-06 -', '9 2026-10-07 3.00', '6 2026-10-06 -', '6 2026-10-07 2.00'],
            DB::table('staff_availabilities')->orderByDesc('user_id')->orderBy('work_date')->get()
                ->map(fn ($r) => "{$r->user_id} {$r->work_date} ".($r->max_workload ?? '-'))->all());

        // Kolom secondary_duty dihapus oleh file SQL dan sudah tidak ada di skema
        $this->assertStringContainsString('ALTER TABLE staff_availabilities DROP COLUMN IF EXISTS secondary_duty;', $isi);
        $this->assertFalse(Schema::hasColumn('staff_availabilities', 'secondary_duty'));
    }

    /* ---------------- Skenario demo: mode lama dan mode rasio ---------------- */

    /** Urutan staf Rahmat, Rina, Anto, Dewi; Anto dan Dewi multi-role (batas 2), Rahmat HK murni (4), Rina (dan staf uji) tidak hadir; urutan prioritas 207, 109, 114, 101, 102. */
    private function skenarioDemo(bool $rasio): array
    {
        config(['housekeeping.greedy_rasio' => $rasio]);
        $this->ketersediaan(self::RINA, ['is_available' => 0]);
        $this->ketersediaan(self::UJI_FO_HK, ['is_available' => 0]);

        DB::table('rooms')->where('status', '!=', 'maintenance')->update(['status' => 'available', 'dirty_since' => null]);
        foreach (['207' => ['vacant_dirty', '05:00'], '109' => ['vacant_dirty', '05:10'], '114' => ['vacant_dirty', '05:20'],
            '101' => ['occupied_dirty', '08:20'], '102' => ['occupied_dirty', '08:30']] as $nomor => [$status, $jam]) {
            DB::table('rooms')->where('room_number', $nomor)->update(['status' => $status, 'dirty_since' => '2026-10-06 '.$jam.':00']);
        }
        app(PrioritasPembersihanService::class)->hitungBatch($this->akun('manager'));
        $u = app(PembagianTugasService::class)->usulan(today());

        $urutan = collect($u['penugasan'])->flatten(1)->sortBy('peringkat')->pluck('nomor')->all();
        $this->assertSame(['207', '109', '114', '101', '102'], $urutan, 'Urutan prioritas skenario');
        $this->assertSame([], $u['belum']);
        $batas = $u['staf']->keyBy('id');
        $this->assertSame([2.0, 2.0, 4.0], [$batas[self::ANTO]['batas'], $batas[self::DEWI]['batas'], $batas[self::RAHMAT]['batas']]);
        $this->assertSame(['F&B', 'F&B', null, null], [$batas[self::ANTO]['rangkap'], $batas[self::DEWI]['rangkap'], $batas[self::RAHMAT]['rangkap'], $batas[self::RINA]['rangkap']]);
        // Tidak ada staf yang melewati batas efektifnya
        foreach ($u['staf'] as $s) {
            $this->assertLessThanOrEqual($s['batas'] + 1e-9, $u['beban'][$s['id']] ?? 0.0, $s['nama']);
        }

        return $u;
    }

    private function kamar(array $u, int $staf): array
    {
        return collect($u['penugasan'][$staf] ?? [])->pluck('nomor')->all();
    }

    /** Urutan tampilan: HK murni (Rahmat, Rina) lalu multi-role (Anto, Dewi, staf uji), Rina tidak hadir tetap di posisinya. */
    private const NAMA_URUT = ['Rahmat Hidayat', 'Rina Wulandari', 'Anto Saputra', 'Dewi Anggraini', 'Uji FO dan HK'];

    public function test_BB09_skenario_demo_mode_lama_beban_terkecil(): void
    {
        $u = $this->skenarioDemo(false);

        // Seri beban (dan jumlah tugas) dipecah urutan staf: Rahmat, Anto, Dewi
        $this->assertSame(['207', '101'], $this->kamar($u, self::RAHMAT));
        $this->assertSame(['109', '102'], $this->kamar($u, self::ANTO));
        $this->assertSame(['114'], $this->kamar($u, self::DEWI));
        $this->assertSame([1.5, 1.5, 1.0], [$u['beban'][self::RAHMAT], $u['beban'][self::ANTO], $u['beban'][self::DEWI]]);
    }

    public function test_BB10_skenario_demo_mode_rasio(): void
    {
        $u = $this->skenarioDemo(true);

        // Rasio (beban + poin) / batas terkecil, seri dipecah urutan staf
        $this->assertSame(['207', '109'], $this->kamar($u, self::RAHMAT));
        $this->assertSame(['114'], $this->kamar($u, self::ANTO));
        $this->assertSame(['101', '102'], $this->kamar($u, self::DEWI));
        $this->assertSame([0.5, 0.5, 0.5], [$u['rasio'][self::RAHMAT], $u['rasio'][self::ANTO], $u['rasio'][self::DEWI]]);

        // Setelah disimpan, halaman Pembagian Tugas menampilkan persentase beban dan sumber batas tiap staf, dalam urutan yang sama
        app(PembagianTugasService::class)->simpan($this->akun('manager'), today());
        $this->actingAs($this->akun('manager'))->get(route('staf.housekeeping.pembagian'))->assertOk()
            ->assertSee('/ 4 poin')->assertSee('/ 2 poin')->assertSee('50%')->assertSee('multi-role')->assertSee('HK murni')
            ->assertSee('rangkap: F&B')->assertSeeInOrder(self::NAMA_URUT);
    }

    public function test_BB11_satu_urutan_staf_hk_murni_dulu_lalu_multi_role_untuk_tampilan_dan_greedy(): void
    {
        $this->ketersediaan(self::RINA, ['is_available' => 0]);
        $ids = [self::RAHMAT, self::RINA, self::ANTO, self::DEWI, self::UJI_FO_HK];

        $this->assertSame($ids, PembagianTugasService::stafHousekeeping()->pluck('id')->all());
        // Kunci "urutan" untuk pemecah seri Greedy = posisi yang sama; staf tidak hadir tidak dipindah ke bawah
        $staf = app(PembagianTugasService::class)->staf(today());
        $this->assertSame($ids, $staf->pluck('id')->all());
        $this->assertSame([0, 1, 2, 3, 4], $staf->pluck('urutan')->all());
        $this->assertFalse($staf[1]['hadir']);

        Livewire::actingAs($this->akun('manager'))->test(KetersediaanStaf::class)->assertSeeInOrder(self::NAMA_URUT);
        // Kartu staf Pembagian Tugas tampil setelah ada batch prioritas
        app(PrioritasPembersihanService::class)->hitungBatch($this->akun('manager'));
        $this->actingAs($this->akun('manager'))->get(route('staf.housekeeping.pembagian'))->assertOk()->assertSeeInOrder(self::NAMA_URUT);

        // Nama baru di kelompok HK murni tetap di atas semua staf multi-role
        DB::table('users')->where('id', self::RINA)->update(['name' => 'Zainal Arifin']);
        $this->assertSame([self::RAHMAT, self::RINA, self::ANTO, self::DEWI, self::UJI_FO_HK], PembagianTugasService::stafHousekeeping()->pluck('id')->all());
    }
}
