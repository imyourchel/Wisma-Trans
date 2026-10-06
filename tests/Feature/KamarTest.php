<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\RoomStatusBoard;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Bagian B (Kamar), diuji lewat request HTTP. */
class KamarTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->actingAs($this->akun('admin'));
    }

    private function isiKamar(Room $kamar, array $ubah = []): array
    {
        return $ubah + ['room_number' => $kamar->room_number, 'room_type_id' => $kamar->room_type_id, 'floor' => $kamar->floor, 'bed_type' => $kamar->bed_type];
    }

    private function tambah(array $ubah = [])
    {
        return $this->postJson(route('staf.kamar.store'), $ubah + ['room_number' => '217', 'room_type_id' => $this->tipe('VIP')->id, 'floor' => 2, 'bed_type' => 'king']);
    }

    public function test_KM01_tambah_kamar_valid_berstatus_siap_dan_aktif(): void
    {
        $this->tambah()->assertOk()->assertJson(['pesan' => 'Kamar berhasil ditambahkan.']);

        $kamar = $this->kamar('217');
        $this->assertSame(RoomStatus::Available, $kamar->refresh()->status);
        $this->assertTrue($kamar->is_active);
        $this->assertSame($this->tipe('VIP')->id, $kamar->room_type_id);
    }

    public function test_KM03_nomor_kamar_kembar_ditolak(): void
    {
        $this->tambah(['room_number' => '101'])->assertStatus(422)
            ->assertJsonValidationErrors(['room_number' => 'Nomor kamar sudah dipakai.']);
    }

    public function test_KM04_nomor_kamar_dengan_simbol_ditolak(): void
    {
        $this->tambah(['room_number' => '10-1'])->assertStatus(422)->assertJsonValidationErrors('room_number');
    }

    public function test_KM05_tipe_kamar_yang_tidak_ada_ditolak(): void
    {
        $this->tambah(['room_type_id' => 999])->assertStatus(422)->assertJsonValidationErrors('room_type_id');
    }

    public function test_KM06_jenis_kasur_tidak_valid_ditolak(): void
    {
        $this->tambah(['bed_type' => 'queen'])->assertStatus(422)->assertJsonValidationErrors('bed_type');
        $this->assertDatabaseCount('rooms', 32);
    }

    public function test_KM07_ubah_tipe_kamar_status_tetap(): void
    {
        $kamar = $this->kamar('110');

        $this->putJson(route('staf.kamar.update', $kamar), $this->isiKamar($kamar, ['room_type_id' => $this->tipe('DLX')->id]))->assertOk();

        $kamar->refresh();
        $this->assertSame($this->tipe('DLX')->id, $kamar->room_type_id);
        $this->assertSame(RoomStatus::Available, $kamar->status);
    }

    public function test_KM08_status_tidak_ikut_terubah_lewat_form(): void
    {
        $kamar = $this->kamar('101');

        $this->putJson(route('staf.kamar.update', $kamar), $this->isiKamar($kamar, ['status' => 'maintenance']))->assertOk();

        $this->assertSame(RoomStatus::Available, $kamar->refresh()->status);
        $this->assertSame(0, $kamar->statusLogs()->count());
    }

    public function test_KM09_nonaktifkan_kamar_kosong_dan_hilang_dari_papan(): void
    {
        $kamar = $this->kamar('101');

        $this->patch(route('staf.kamar.toggle', $kamar))->assertSessionHas('status', 'Kamar 101 dinonaktifkan.');

        $this->assertFalse($kamar->refresh()->is_active);
        // Papan status dilihat Manager/FO/HK; Admin tidak membukanya
        Livewire::actingAs($this->akun('manager'))->test(RoomStatusBoard::class)
            ->assertViewHas('floors', fn ($floors) => ! $floors->flatten(1)->contains('room_number', '101'));
    }

    public function test_KM10_nonaktifkan_kamar_terisi_ditolak(): void
    {
        $kamar = $this->kamar('204');

        $this->patch(route('staf.kamar.toggle', $kamar))
            ->assertSessionHas('error', 'Kamar sedang terisi tamu, tidak bisa dinonaktifkan.');

        $this->assertTrue($kamar->refresh()->is_active);
    }

    public function test_KM11_aktifkan_lagi_tanpa_mengubah_status(): void
    {
        $kamar = $this->kamar('101');

        $this->patch(route('staf.kamar.toggle', $kamar));
        $this->patch(route('staf.kamar.toggle', $kamar))->assertSessionHas('status', 'Kamar 101 diaktifkan.');

        $kamar->refresh();
        $this->assertTrue($kamar->is_active);
        $this->assertSame(RoomStatus::Available, $kamar->status);
    }

    public function test_KM12_tidak_ada_fitur_hapus_kamar(): void
    {
        $status = $this->delete('/staf/kamar/1')->getStatusCode();

        $this->assertContains($status, [404, 405]);
        $this->assertDatabaseCount('rooms', 32);
    }
}
