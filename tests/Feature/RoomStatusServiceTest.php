<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Services\RoomStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Bagian D (perpindahan status), E (dirty_since), dan RW-01, diuji langsung ke RoomStatusService. */
class RoomStatusServiceTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
    }

    private function ubah(string $nomor, RoomStatus $ke, string $akun, ?string $catatan = null): Room
    {
        $kamar = $this->kamar($nomor);
        app(RoomStatusService::class)->change($kamar, $ke, $catatan, $this->akun($akun));

        return $kamar->refresh();
    }

    /** @return array<string, list<string>> galat validasi */
    private function ditolak(string $nomor, RoomStatus $ke, string $akun, ?string $catatan = null): array
    {
        $sebelum = $this->kamar($nomor)->status;
        $log = RoomStatusLog::count();
        try {
            $this->ubah($nomor, $ke, $akun, $catatan);
        } catch (ValidationException $e) {
            $this->assertSame($sebelum, $this->kamar($nomor)->status, 'Status tidak boleh berubah.');
            $this->assertSame($log, RoomStatusLog::count(), 'Log tidak boleh bertambah.');

            return $e->errors();
        }
        $this->fail("Perubahan {$nomor} ke {$ke->value} oleh {$akun} seharusnya ditolak.");
    }

    private function jumlahLog(string $nomor): int
    {
        return $this->kamar($nomor)->statusLogs()->count();
    }

    public function test_ST01_hk_mulai_membersihkan_kamar_kosong_kotor(): void
    {
        $log = $this->jumlahLog('105');

        $this->assertSame(RoomStatus::Cleaning, $this->ubah('105', RoomStatus::Cleaning, 'hk')->status);
        $this->assertSame($log + 1, $this->jumlahLog('105'));
    }

    public function test_ST02_hk_selesai_membersihkan_kamar_kosong(): void
    {
        $this->ubah('105', RoomStatus::Cleaning, 'hk');

        $this->assertSame(RoomStatus::Available, $this->ubah('105', RoomStatus::Available, 'hk')->status);
    }

    public function test_ST03_hk_membersihkan_kamar_yang_tamunya_masih_menginap(): void
    {
        $log = $this->jumlahLog('203');

        $this->ubah('203', RoomStatus::Cleaning, 'hk');
        $kamar = $this->ubah('203', RoomStatus::OccupiedClean, 'hk');

        $this->assertSame(RoomStatus::OccupiedClean, $kamar->status);
        $this->assertSame($log + 2, $this->jumlahLog('203'));
    }

    public function test_ST05_fo_mencatat_check_out_manual(): void
    {
        $this->assertSame(RoomStatus::VacantDirty, $this->ubah('204', RoomStatus::VacantDirty, 'fo')->status);
    }

    public function test_ST06_hk_tidak_boleh_mencatat_check_out(): void
    {
        $galat = $this->ditolak('204', RoomStatus::VacantDirty, 'hk');

        $this->assertSame('Perubahan status dari Terisi ke Kosong, kotor tidak diizinkan.', $galat['status'][0]);
    }

    public function test_ST07_fo_tidak_boleh_menandai_selesai_dibersihkan(): void
    {
        $galat = $this->ditolak('202', RoomStatus::Available, 'fo');

        $this->assertSame('Perubahan status dari Sedang dibersihkan ke Siap tidak diizinkan.', $galat['status'][0]);
    }

    public function test_ST08_perpindahan_yang_tidak_ada_di_tabel_ditolak_walau_admin(): void
    {
        $galat = $this->ditolak('101', RoomStatus::Cleaning, 'admin');

        $this->assertSame('Perubahan status dari Siap ke Sedang dibersihkan tidak diizinkan.', $galat['status'][0]);
    }

    public function test_ST09_status_sama_tidak_mengubah_apa_pun(): void
    {
        $log = RoomStatusLog::count();

        $this->ubah('101', RoomStatus::Available, 'admin');

        $this->assertSame($log, RoomStatusLog::count());
    }

    public function test_ST10_perbaikan_tanpa_catatan_ditolak(): void
    {
        $galat = $this->ditolak('101', RoomStatus::Maintenance, 'hk', '   ');

        $this->assertSame('Catatan wajib diisi untuk status Perbaikan.', $galat['note'][0]);
    }

    public function test_ST11_perbaikan_dengan_catatan_tersimpan_di_log(): void
    {
        $kamar = $this->ubah('101', RoomStatus::Maintenance, 'hk', 'AC bocor');

        $this->assertSame(RoomStatus::Maintenance, $kamar->status);
        $this->assertDatabaseHas('room_status_logs', ['room_id' => $kamar->id, 'status' => 'maintenance', 'note' => 'AC bocor']);
    }

    public function test_ST12_hk_tidak_boleh_menutup_perbaikan(): void
    {
        $this->ditolak('103', RoomStatus::Available, 'hk');
    }

    public function test_ST13_fo_menutup_perbaikan_dan_kamar_masuk_antrean_bersih(): void
    {
        $kamar = $this->ubah('103', RoomStatus::VacantDirty, 'fo');

        $this->assertSame(RoomStatus::VacantDirty, $kamar->status);
        $this->assertEquals(now(), $kamar->dirty_since);
    }

    public function test_ST14_peran_gabungan_fo_dan_hk_mendapat_hak_keduanya(): void
    {
        $this->ubah('204', RoomStatus::VacantDirty, 'fo_hk');   // hak FO
        $this->ubah('204', RoomStatus::Cleaning, 'fo_hk');      // hak HK
        $kamar = $this->ubah('204', RoomStatus::Available, 'fo_hk');

        $this->assertSame(RoomStatus::Available, $kamar->status);
    }

    public function test_ST16_kamar_nonaktif_tidak_bisa_diganti_statusnya(): void
    {
        $this->kamar('101')->update(['is_active' => false]);

        $galat = $this->ditolak('101', RoomStatus::VacantDirty, 'admin');

        $this->assertStringContainsString('nonaktif', $galat['status'][0]);
    }

    public function test_DS01_terisi_menjadi_perlu_dibersihkan_mengisi_dirty_since(): void
    {
        $this->assertEquals(now(), $this->ubah('204', RoomStatus::OccupiedDirty, 'fo')->dirty_since);
    }

    public function test_DS02_dari_occupied_dirty_ke_vacant_dirty_waktu_lama_dipertahankan(): void
    {
        $kamar = $this->ubah('203', RoomStatus::VacantDirty, 'fo');

        $this->assertSame('2026-10-06 09:30:00', $kamar->dirty_since->format('Y-m-d H:i:s'));
    }

    public function test_DS03_mulai_dibersihkan_mengosongkan_dirty_since(): void
    {
        $this->assertNull($this->ubah('105', RoomStatus::Cleaning, 'hk')->dirty_since);
    }

    public function test_DS04_kamar_siap_menjadi_kotor_mengisi_dirty_since(): void
    {
        $this->assertEquals(now(), $this->ubah('101', RoomStatus::VacantDirty, 'hk')->dirty_since);
    }

    public function test_RW01_log_berisi_status_baru_pengubah_dan_waktu(): void
    {
        $kamar = $this->ubah('105', RoomStatus::Cleaning, 'hk');

        $log = $kamar->statusLogs()->latest('id')->first();
        $this->assertSame(RoomStatus::Cleaning, $log->status);
        $this->assertSame($this->akun('hk')->id, $log->changed_by);
        $this->assertEquals(now(), $log->changed_at);
    }
}
