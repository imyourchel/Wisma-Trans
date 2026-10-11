<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\RoomStatusBoard;
use App\Services\RoomStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Bagian C (Papan Status) serta ST-04, ST-15, RW-02, RW-03, diuji lewat Livewire::test. */
class PapanStatusKamarTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
    }

    private function papan(string $akun)
    {
        return Livewire::actingAs($this->akun($akun))->test(RoomStatusBoard::class);
    }

    private static function nomorDiPapan($floors): array
    {
        return $floors->flatten(1)->pluck('room_number')->all();
    }

    public function test_PS01_ringkasan_sesuai_jumlah_kamar_aktif_per_status(): void
    {
        $this->papan('manager')
            ->assertViewHas('total', 32)
            ->assertViewHas('counts', fn ($counts) => $counts->sortKeys()->all() === [
                'available' => 21, 'cleaning' => 1, 'maintenance' => 1,
                'occupied_clean' => 5, 'occupied_dirty' => 1, 'vacant_dirty' => 3,
            ]);
    }

    public function test_PS02_kamar_nonaktif_tidak_tampil_di_papan(): void
    {
        $this->actingAs($this->akun('admin'))->patch(route('staf.kamar.toggle', $this->kamar('101')));

        $this->papan('manager')
            ->assertViewHas('total', 31)
            ->assertViewHas('floors', fn ($floors) => ! in_array('101', self::nomorDiPapan($floors), true));
    }

    public function test_PS07_pilihan_status_hk_untuk_kamar_kosong_kotor(): void
    {
        $this->papan('hk')
            ->call('open', $this->kamar('105')->id)
            ->assertViewHas('options', fn ($opsi) => array_map(fn (RoomStatus $s) => $s->value, $opsi) === ['cleaning', 'maintenance']);
    }

    public function test_PS08_manager_hanya_melihat_tanpa_pilihan_status(): void
    {
        $this->papan('manager')
            ->call('open', $this->kamar('105')->id)
            ->assertViewHas('canChange', false)
            ->assertViewHas('options', [])
            ->assertDontSeeHtml('wire:submit="save"')
            ->assertSee('Anda dapat melihat status kamar.');
    }

    public function test_ST04_dari_sedang_dibersihkan_disarankan_kembali_terisi(): void
    {
        $id = $this->kamar('203')->id;

        $this->papan('hk')
            ->call('open', $id)->set('status', 'cleaning')->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', teks: 'Status kamar 203 diubah menjadi Sedang dibersihkan.')
            ->call('open', $id)
            ->assertSet('status', 'occupied_clean')
            ->assertViewHas('suggested', RoomStatus::OccupiedClean);
    }

    public function test_ST15_manager_memanggil_method_simpan_langsung_mendapat_403(): void
    {
        $this->papan('manager')
            ->call('open', $this->kamar('105')->id)
            ->set('status', 'cleaning')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(RoomStatus::VacantDirty, $this->kamar('105')->status);
    }

    public function test_RW02_riwayat_menampilkan_10_perubahan_terbaru_di_atas(): void
    {
        $kamar = $this->kamar('101');
        $awal = now()->subDays(3);
        for ($i = 0; $i < 12; $i++) {
            DB::table('room_status_logs')->insert([
                'room_id' => $kamar->id, 'status' => $i % 2 ? 'available' : 'vacant_dirty',
                'changed_by' => 1, 'changed_at' => $awal->copy()->addHours($i), 'note' => "Perubahan {$i}",
            ]);
        }

        $this->papan('fo')
            ->call('open', $kamar->id)
            ->assertViewHas('history', fn ($riwayat) => $riwayat->count() === 10
                && $riwayat->first()->note === 'Perubahan 11'
                && $riwayat->last()->note === 'Perubahan 2');
    }

    public function test_KL01_kalender_menampilkan_nama_pemesan_per_kamar(): void
    {
        $pesan = function (string $kode, string $nama, ?string $kamar, string $status, string $statusKamar = 'booked') {
            $pelanggan = DB::table('customers')->insertGetId(['full_name' => $nama, 'phone' => '08'.random_int(1000000000, 9999999999)]);
            $rsv = DB::table('reservations')->insertGetId(['reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'walk_in', 'status' => $status]);
            DB::table('reservation_rooms')->insert([
                'reservation_id' => $rsv, 'room_type_id' => $this->tipe('DLX')->id, 'room_id' => $kamar ? $this->kamar($kamar)->id : null,
                'check_in_date' => '2026-10-05', 'check_out_date' => '2026-10-07', 'price_per_night' => 460000, 'status' => $statusKamar,
            ]);
        };
        $pesan('RSV-T1', 'Hasan Basri', '203', 'checked_in', 'checked_in');
        $pesan('RSV-T2', 'Dian Permata', null, 'confirmed');
        $pesan('RSV-T3', 'Tamu Batal', '204', 'cancelled', 'cancelled');

        $this->papan('fo')
            ->set('view', 'kalender')
            ->set('start', '2026-10-01')
            ->assertViewHas('kalender', fn ($k) => $k['perKamar']->has($this->kamar('203')->id) && $k['tanpaKamar']->count() === 1)
            ->assertSee('Hasan Basri')
            ->assertSee('Belum dapat kamar · Deluxe Room')
            ->assertSee('Dian Permata')
            ->assertDontSee('Tamu Batal');
    }

    public function test_KL02_pilih_tanggal_menjadi_kolom_pertama_kalender(): void
    {
        $this->papan('fo')
            ->set('view', 'kalender')
            ->call('pickDate', '2026-10-20')
            ->assertSet('start', '2026-10-20')
            ->assertViewHas('kalender', fn ($k) => $k['hari']->first()->toDateString() === '2026-10-20' && $k['akhir']->toDateString() === '2026-11-02')
            ->call('pickDate', '2026-02-31')
            ->assertSet('start', '')
            ->call('pickDate', '2026-10-20')
            ->call('pickDate', today()->toDateString())
            ->assertSet('start', '');
    }

    public function test_RW03_log_pengubah_yang_dihapus_tetap_ada_dan_tampil_sistem(): void
    {
        $hk = $this->akun('hk');
        $kamar = $this->kamar('105');
        app(RoomStatusService::class)->change($kamar, RoomStatus::Cleaning, null, $hk);
        $hk->delete();

        $this->assertDatabaseHas('room_status_logs', ['room_id' => $kamar->id, 'status' => 'cleaning']);
        $this->papan('fo')
            ->call('open', $kamar->id)
            ->assertViewHas('history', fn ($riwayat) => $riwayat->first()->status === RoomStatus::Cleaning && $riwayat->first()->changedBy === null)
            ->assertSee('oleh Sistem');
    }
}
