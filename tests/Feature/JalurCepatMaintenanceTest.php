<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\Kamar\RoomStatusBoard;
use App\Models\FacilityUnit;
use App\Models\MaintenanceRequest;
use App\Models\RoomStatusLog;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * R3–R6 hasil bimbingan: peringatan laporan ganda menyebut kerusakannya, istilah "Belum dikerjakan",
 * jalur cepat dengan persetujuan Manager, dan melengkapi laporan jalur cepat. Waktu acuan 6 Okt 2026 10.00 WITA.
 */
class JalurCepatMaintenanceTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private FacilityUnit $ac;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->siapkanDataContoh();
        $k = $this->kamar('201');
        $this->ac = FacilityUnit::create(['facility_id' => 1, 'room_id' => $k->id, 'area_label' => 'Lantai 2', 'unit_label' => 'AC-201', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lapor(FacilityUnit|string $unit, string $deskripsi, bool $tutup, array $tambahan = [])
    {
        return $this->actingAs($this->akun('hk'))->postJson(route('staf.maintenance.lapor.store'), $tambahan + [
            'lokasi' => 'kamar', 'room_id' => $this->kamar('201')->id, 'unit' => $unit instanceof FacilityUnit ? (string) $unit->id : $unit,
            'deskripsi' => $deskripsi, 'priority' => 'high', 'tidak_bisa_dipakai' => $tutup ? '1' : '0',
        ]);
    }

    private function laporan(string $kode): MaintenanceRequest
    {
        return MaintenanceRequest::where('request_code', $kode)->sole();
    }

    /** Kamar 201 Perbaikan: MNT-0001 dan MNT-0002 menahan kamar, MNT-0003 laporan kecil (tidak menahan). */
    private function kamarTertahan(): void
    {
        $this->lapor($this->ac, 'AC tidak menyala', true)->assertOk();
        $this->lapor('lainnya', 'Pintu kamar mandi tidak bisa dikunci', true)->assertOk();
        $this->lapor('lainnya', 'Lampu meja redup', false)->assertOk();
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('201')->status);
    }

    private function papan(string $akun = 'fo')
    {
        return Livewire::actingAs($this->akun($akun))->test(RoomStatusBoard::class)->call('open', $this->kamar('201')->id);
    }

    /* ---------- R3. Peringatan laporan ganda ---------- */

    public function test_R3_peringatan_ganda_memuat_kode_dan_deskripsi_semua_laporan_terbuka(): void
    {
        $panjang = 'AC menetes air ke kasur, air keluar dari unit dalam sejak pagi dan lantai di bawahnya basah sekali';
        $this->lapor($this->ac, 'AC tidak menyala', false)->assertOk();
        $this->lapor($this->ac, $panjang, false, ['tetap_kirim' => '1'])->assertOk();
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $this->laporan('MNT-0001')),
            ['pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik'])->assertOk();

        $this->lapor($this->ac, 'AC tidak dingin', false)->assertStatus(422)->assertJsonPath('errors.tetap_kirim.0',
            "AC-201 sudah punya laporan yang belum selesai:\n"
            ."• MNT-0001 · \"AC tidak menyala\" · Rahmat, 6 Okt 10.00 · Sedang dikerjakan\n"
            .'• MNT-0002 · "'.Str::limit($panjang, 80).'" · Rahmat, 6 Okt 10.00 · Belum dikerjakan'."\n"
            .'Kalau kerusakan yang Anda temukan sama, tidak perlu dikirim. Kalau berbeda, centang "Tetap kirim".');

        // Halaman lapor membawa deskripsi laporan terbuka dan placeholder yang meminta gejala spesifik
        $this->actingAs($this->akun('hk'))->get(route('staf.maintenance.lapor'))->assertOk()
            ->assertSee('AC tidak menyala')->assertSee('Tulis gejalanya, misalnya: AC tidak dingin, AC menetes air, AC tidak menyala.');
        // Daftar Laporan Saya dan Penanganan menampilkan potongan deskripsi di bawah nama unit
        $this->actingAs($this->akun('hk'))->get(route('staf.maintenance.saya'))->assertSeeInOrder(['AC-201', 'AC tidak menyala']);
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.index'))->assertSeeInOrder(['AC-201', mb_substr($panjang, 0, 40)]);
    }

    /* ---------- R4. Istilah status awal ---------- */

    public function test_R4_label_status_awal_belum_dikerjakan(): void
    {
        $this->assertSame('Belum dikerjakan', (new MaintenanceRequest(['status' => 'reported']))->labelStatus());
        $this->assertSame('Belum dikerjakan', MaintenanceRequest::labelUntuk('reported'));
        $this->lapor($this->ac, 'AC tidak menyala', false)->assertOk();

        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertOk()->assertSee('Kerusakan belum dikerjakan');
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.index'))->assertSee('Belum dikerjakan')->assertDontSee('Baru dilaporkan');
    }

    /* ---------- R5. Jalur cepat ---------- */

    public function test_R5_fo_membuka_kamar_dengan_kredensial_manager(): void
    {
        $this->kamarTertahan();

        $this->papan()->assertSee('Buka dengan persetujuan Manager')
            ->call('bukaJalurCepat')->assertSee('MNT-0001')->assertSee('AC tidak menyala')
            ->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'password')
            ->call('simpanJalurCepat')->assertHasNoErrors()
            ->assertDispatched('toast', teks: 'Kamar 201 dibuka lewat jalur cepat. MNT-0001, MNT-0002 ditutup atas persetujuan Lina.');

        // Sesi FO tidak berganti
        $this->assertAuthenticatedAs($this->akun('fo'));
        foreach (['MNT-0001', 'MNT-0002'] as $kode) {
            $l = $this->laporan($kode);
            $this->assertSame(['completed', 2, 'Jalur cepat', null, null], [$l->status, $l->handled_by, $l->resolution_note, $l->cost, $l->vendor_name]);
            $this->assertNotNull($l->started_at);
            $this->assertTrue($l->isJalurCepat());
        }
        // Laporan yang tidak menahan kamar tetap terbuka
        $this->assertSame('reported', $this->laporan('MNT-0003')->status);

        $this->assertSame(RoomStatus::Available, $this->kamar('201')->status);
        $log = RoomStatusLog::where('room_id', $this->kamar('201')->id)->latest('id')->first();
        $this->assertSame([RoomStatus::Available, 2, 'Jalur cepat MNT-0001, MNT-0002 oleh Lina Hartono, diminta Yusuf Ramadhan'],
            [$log->status, $log->changed_by, $log->note]);
        $this->assertSame(2, MaintenanceRequest::jalurCepat()->count());
    }

    public function test_R5_kredensial_salah_atau_bukan_manager_ditolak_dan_dibatasi(): void
    {
        $this->kamarTertahan();
        $papan = $this->papan('hk')->call('bukaJalurCepat');

        $papan->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'salah')->call('simpanJalurCepat')
            ->assertHasErrors(['sandiManager'])->assertSee('Email atau kata sandi Manager salah.')->assertSet('sandiManager', '');
        $papan->set('emailManager', 'tidakada@wismatrans.id')->set('sandiManager', 'password')->call('simpanJalurCepat')
            ->assertSee('Email atau kata sandi Manager salah.');
        $papan->set('emailManager', 'yusuf@wismatrans.id')->set('sandiManager', 'password')->call('simpanJalurCepat')
            ->assertSee('Akun ini bukan Manager.');
        $papan->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'salah')->call('simpanJalurCepat');
        $papan->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'salah')->call('simpanJalurCepat');
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('201')->status);

        // Percobaan ke-6 dalam semenit ditolak walau kredensialnya benar
        $papan->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'password')->call('simpanJalurCepat')
            ->assertSee('Terlalu banyak percobaan. Coba lagi dalam');
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('201')->status);
        $this->assertSame(0, MaintenanceRequest::jalurCepat()->count());
    }

    public function test_R5_jalur_cepat_hanya_untuk_kamar_perbaikan(): void
    {
        $layanan = app(MaintenanceService::class);
        try {
            $layanan->selesaikanCepat($this->kamar('105'), $this->akun('manager'), $this->akun('fo'));
            $this->fail('Kamar yang bukan Perbaikan seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('Jalur cepat hanya untuk kamar berstatus Perbaikan.', collect($e->errors())->flatten()->first());
        }
        $this->assertSame(RoomStatus::VacantDirty, $this->kamar('105')->status);

        // Kamar tanpa laporan penahan tidak menawarkan jalur cepat
        Livewire::actingAs($this->akun('fo'))->test(RoomStatusBoard::class)->call('open', $this->kamar('105')->id)
            ->assertDontSee('Buka dengan persetujuan Manager');
    }

    public function test_R5_manager_menyelesaikan_jalur_cepat_dari_penanganan(): void
    {
        $this->kamarTertahan();
        $url = route('staf.maintenance.jalur-cepat', $this->laporan('MNT-0001'));

        $this->actingAs($this->akun('manager'))->postJson($url, ['sandi_manager' => 'salah'])->assertStatus(422)
            ->assertJsonPath('errors.sandi_manager.0', 'Email atau kata sandi Manager salah.');
        $this->actingAs($this->akun('fo'))->postJson($url, ['sandi_manager' => 'password'])->assertForbidden();
        $this->assertSame(RoomStatus::Maintenance, $this->kamar('201')->status);

        $this->actingAs($this->akun('manager'))->postJson($url, ['sandi_manager' => 'password'])->assertOk()
            ->assertJsonPath('pesan', 'Kamar 201 dibuka lewat jalur cepat. MNT-0001, MNT-0002 ditutup atas persetujuan Lina.');
        $this->assertSame(RoomStatus::Available, $this->kamar('201')->status);
        $this->assertSame('Jalur cepat MNT-0001, MNT-0002 oleh Lina Hartono, diminta Lina Hartono',
            RoomStatusLog::where('room_id', $this->kamar('201')->id)->latest('id')->value('note'));

        // Lencana di Penanganan dan Riwayat; biaya kosong tampil "Belum diisi"
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.index', ['tab' => 'completed']))->assertSee('Jalur cepat')->assertSee('Belum diisi');
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.riwayat', ['jalur' => 'ya']))->assertSee('MNT-0001')->assertSee('Belum diisi');
    }

    /* ---------- R6. Lengkapi laporan jalur cepat ---------- */

    public function test_R6_manager_melengkapi_laporan_jalur_cepat(): void
    {
        $this->kamarTertahan();
        $this->papan()->call('bukaJalurCepat')->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'password')->call('simpanJalurCepat');
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertSeeInOrder(['Jalur cepat belum dilengkapi', '2']);

        $l = $this->laporan('MNT-0001');
        $selesai = $l->completed_at->toDateTimeString();
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.lengkapi', $l), [
            'pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik', 'cost' => '350.000', 'catatan' => 'Freon diisi ulang',
        ])->assertOk()->assertJsonPath('pesan', 'MNT-0001 diperbarui.');

        $l->refresh();
        $this->assertSame(['completed', 'Jalur cepat — Freon diisi ulang', 'Freon diisi ulang', 'CV Sejuk Teknik', null, 350000.0, $selesai],
            [$l->status, $l->resolution_note, $l->catatanTambahan(), $l->vendor_name, $l->handled_by, (float) $l->cost, $l->completed_at->toDateTimeString()]);
        $this->assertTrue($l->isJalurCepat());
        $this->assertSame(1, MaintenanceRequest::jalurCepat()->whereNull('cost')->count());

        // Catatan dikosongkan: penanda tetap ada; validasi sama dengan Selesaikan
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.lengkapi', $l), [
            'pengerja' => 'staf', 'handled_by' => 2, 'cost' => '-5', 'catatan' => str_repeat('a', 1001),
        ])->assertStatus(422)->assertJsonValidationErrors(['cost', 'catatan']);
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.lengkapi', $l), ['pengerja' => 'staf', 'handled_by' => 2, 'cost' => '0', 'catatan' => ''])->assertOk();
        $this->assertSame(['Jalur cepat', null], [$l->fresh()->resolution_note, $l->fresh()->catatanTambahan()]);
    }

    public function test_R6_laporan_selesai_biasa_tidak_bisa_diedit_dan_fo_hk_ditolak(): void
    {
        $this->lapor($this->ac, 'AC tidak menyala', false)->assertOk();
        $l = $this->laporan('MNT-0001');
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $l), ['pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik'])->assertOk();
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l), ['resolution_note' => 'Freon diisi.'])->assertOk();
        $this->assertFalse($l->fresh()->isJalurCepat());

        $isi = ['pengerja' => 'vendor', 'vendor_name' => 'CV Lain', 'cost' => '1'];
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.lengkapi', $l), $isi)->assertForbidden();
        $this->assertSame(['CV Sejuk Teknik', 'Freon diisi.'], [$l->fresh()->vendor_name, $l->fresh()->resolution_note]);

        // Laporan jalur cepat: FO dan HK tetap 403
        $this->kamarTertahan();
        $this->papan()->call('bukaJalurCepat')->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'password')->call('simpanJalurCepat');
        foreach (['fo', 'hk'] as $akun) {
            $this->actingAs($this->akun($akun))->postJson(route('staf.maintenance.lengkapi', $this->laporan('MNT-0002')), $isi)->assertForbidden();
        }
    }

    /* ---------- Perbaikan lanjutan: penanda tidak tertukar, vendor tidak hilang, biaya wajib ---------- */

    public function test_selesai_biasa_tidak_boleh_memakai_awalan_jalur_cepat(): void
    {
        $this->lapor($this->ac, 'AC tidak menyala', false)->assertOk();
        $l = $this->laporan('MNT-0001');
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $l), ['pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik'])->assertOk();

        foreach (['jalur cepat — freon diisi', '  JALUR CEPAT', 'Jalur cepat'] as $catatan) {
            $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l), ['resolution_note' => $catatan])
                ->assertStatus(422)->assertJsonPath('errors.resolution_note.0', 'Awalan "Jalur cepat" hanya untuk laporan yang dibuka lewat jalur cepat.');
        }
        $this->assertSame('in_progress', $l->fresh()->status);

        // Kata itu di tengah catatan tidak masalah
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.selesaikan', $l), ['resolution_note' => 'Freon diisi, tidak perlu jalur cepat.'])->assertOk();
        $this->assertFalse($l->fresh()->isJalurCepat());
    }

    public function test_jalur_cepat_mempertahankan_vendor_yang_sedang_mengerjakan(): void
    {
        $this->kamarTertahan();
        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.kerjakan', $this->laporan('MNT-0001')),
            ['pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik'])->assertOk();

        $this->papan()->call('bukaJalurCepat')->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'password')
            ->call('simpanJalurCepat')->assertHasNoErrors();

        $l = $this->laporan('MNT-0001');
        $this->assertSame(['completed', 'CV Sejuk Teknik', 2, 'Jalur cepat'], [$l->status, $l->vendor_name, $l->handled_by, $l->resolution_note]);
        $this->assertSame([null, 2], [$this->laporan('MNT-0002')->vendor_name, $this->laporan('MNT-0002')->handled_by]);
    }

    public function test_lengkapi_wajib_biaya_dan_biaya_nol_mengurangi_kartu(): void
    {
        $this->kamarTertahan();
        $this->papan()->call('bukaJalurCepat')->set('emailManager', 'manager@wismatrans.id')->set('sandiManager', 'password')->call('simpanJalurCepat');
        $l = $this->laporan('MNT-0001');
        $belum = fn () => MaintenanceRequest::jalurCepat()->whereNull('cost')->count();
        $this->assertSame(2, $belum());

        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.lengkapi', $l), ['pengerja' => 'staf', 'handled_by' => 2, 'cost' => ''])
            ->assertStatus(422)->assertJsonPath('errors.cost.0', 'Isi biaya; tulis 0 bila tanpa biaya.');
        $this->assertSame(2, $belum());

        $this->actingAs($this->akun('manager'))->postJson(route('staf.maintenance.lengkapi', $l), ['pengerja' => 'staf', 'handled_by' => 2, 'cost' => '0'])->assertOk();
        $this->assertSame([0.0, 1], [(float) $l->fresh()->cost, $belum()]);
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertSeeInOrder(['Jalur cepat belum dilengkapi', '1']);
        $this->actingAs($this->akun('manager'))->get(route('staf.maintenance.index', ['tab' => 'completed', 'jalur' => 'belum']))
            ->assertSee('MNT-0002')->assertDontSee('MNT-0001');
    }

    /* ---------- Tambahan: cari nomor kamar di Papan Status ---------- */

    public function test_papan_status_punya_pencarian_nomor_kamar(): void
    {
        Livewire::actingAs($this->akun('fo'))->test(RoomStatusBoard::class)
            ->assertSee('Cari nomor kamar')->assertSee("cocok('201')", false);
    }
}
