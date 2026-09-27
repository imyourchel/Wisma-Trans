<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Bagian A (Tipe Kamar), diuji lewat request HTTP. Foto tidak dibatasi jumlah maupun ukurannya. */
class TipeKamarTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const SUP = ['code' => 'SUP', 'name' => 'Superior', 'base_price' => 420000, 'max_capacity' => 2];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        Storage::fake('public');
        $this->actingAs($this->akun('admin'));
    }

    /** Kirim seperti formulir di browser (multipart + minta jawaban JSON). */
    private function simpan(array $data, ?RoomType $tipe = null)
    {
        $url = $tipe ? route('staf.tipe-kamar.update', $tipe) : route('staf.tipe-kamar.store');
        $berkas = $this->ambilBerkas($data);

        return $this->call($tipe ? 'PUT' : 'POST', $url, $data, [], $berkas, ['HTTP_ACCEPT' => 'application/json']);
    }

    private function ambilBerkas(array &$data): array
    {
        $berkas = [];
        if (isset($data['foto_baru'])) {
            $berkas['foto_baru'] = $data['foto_baru'];
            unset($data['foto_baru']);
        }

        return $berkas;
    }

    /** Isi formulir ubah dari data tipe yang ada, ditimpa $ubah. */
    private function isiTipe(RoomType $t, array $ubah = []): array
    {
        return $ubah + [
            'code' => $t->code, 'name' => $t->name, 'description' => $t->description,
            'base_price' => (int) $t->base_price, 'max_capacity' => $t->max_capacity,
            'fasilitas' => $t->facilities()->where('category', 'room')->pluck('facilities.id')->all(),
        ];
    }

    /** Galeri: urutan berisi "lama:<path>" atau "baru:<indeks foto_baru>". */
    private function galeri(RoomType $t, array $urutan, array $baru = []): array
    {
        return $this->isiTipe($t, ['foto_diatur' => '1', 'urutan' => $urutan, 'foto_baru' => $baru]);
    }

    private function fotoTersimpan(array $paths): void
    {
        foreach ($paths as $p) {
            Storage::disk('public')->put($p, 'isi');
        }
    }

    public function test_TK01_tambah_tipe_valid(): void
    {
        $this->simpan(self::SUP + ['fasilitas' => [1, 3]])->assertOk()->assertJson(['pesan' => 'Tipe kamar berhasil ditambahkan.']);

        $sup = $this->tipe('SUP');
        $this->assertEqualsCanonicalizing(['AC', 'TV'], $sup->facilities->pluck('name')->all());
        $this->get(route('staf.tipe-kamar.index'))->assertOk()->assertSee('Superior');
    }

    public function test_TK02_kode_tipe_kembar_ditolak(): void
    {
        $this->simpan(['code' => 'STD'] + self::SUP)->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'Kode tipe kamar sudah dipakai.']);
    }

    public function test_TK03_kode_huruf_kecil_disimpan_huruf_besar(): void
    {
        $this->simpan(['code' => 'sup'] + self::SUP)->assertOk();

        $this->assertDatabaseHas('room_types', ['code' => 'SUP']);
    }

    public function test_TK04_kolom_wajib_kosong_ditolak_per_kolom(): void
    {
        $this->simpan(['code' => 'SUP', 'name' => '', 'base_price' => '', 'max_capacity' => ''])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'base_price', 'max_capacity']);
    }

    public function test_TK05_harga_negatif_ditolak(): void
    {
        $this->simpan(['base_price' => -1000] + self::SUP)->assertStatus(422)->assertJsonValidationErrors('base_price');
    }

    public function test_TK06_kapasitas_di_luar_batas_ditolak(): void
    {
        $this->simpan(['max_capacity' => 0] + self::SUP)->assertStatus(422)->assertJsonValidationErrors('max_capacity');
        $this->simpan(['max_capacity' => 11] + self::SUP)->assertStatus(422)->assertJsonValidationErrors('max_capacity');
        $this->assertDatabaseMissing('room_types', ['code' => 'SUP']);
    }

    public function test_TK07_ubah_harga_dasar_tidak_mengubah_kamar(): void
    {
        $dlx = $this->tipe('DLX');
        $kamarSebelum = Room::where('room_type_id', $dlx->id)->orderBy('id')->get(['id', 'room_type_id', 'status', 'is_active'])->toArray();

        $this->simpan($this->isiTipe($dlx, ['base_price' => 480000]), $dlx)->assertOk()->assertJson(['pesan' => 'Tipe kamar berhasil diubah.']);

        $this->assertEquals(480000, $dlx->refresh()->base_price);
        $this->assertSame($kamarSebelum, Room::where('room_type_id', $dlx->id)->orderBy('id')->get(['id', 'room_type_id', 'status', 'is_active'])->toArray());
    }

    public function test_TK08_tambah_water_heater_ke_std(): void
    {
        $std = $this->tipe('STD');

        $this->simpan($this->isiTipe($std, ['fasilitas' => [1, 2, 3, 5]]), $std)->assertOk();

        // 4 fasilitas kamar + WiFi (fasilitas umum) yang tetap dipertahankan
        $this->assertSame(5, $std->facilities()->count());
    }

    public function test_TK09_unggah_dua_foto_valid(): void
    {
        $std = $this->tipe('STD');
        $baru = [UploadedFile::fake()->image('depan.jpg')->size(500), UploadedFile::fake()->image('kamar.jpg')->size(800)];

        $this->simpan($this->galeri($std, ['lama:std-1.jpg', 'lama:std-2.jpg', 'baru:0', 'baru:1'], $baru), $std)->assertOk();

        $foto = $std->refresh()->photos;
        $this->assertCount(4, $foto);
        Storage::disk('public')->assertExists([$foto[2], $foto[3]]);
        $this->assertStringStartsWith('room-types/', $foto[2]);
    }

    public function test_TK10_unggah_berkas_bukan_gambar_ditolak(): void
    {
        $std = $this->tipe('STD');
        $pdf = UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf');

        $this->simpan($this->galeri($std, ['baru:0'], [$pdf]), $std)->assertStatus(422)->assertJsonValidationErrors('foto_baru.0');
        $this->assertSame(['std-1.jpg', 'std-2.jpg'], $std->refresh()->photos);
    }

    public function test_TK11_foto_besar_tetap_diterima_karena_tanpa_batas_ukuran(): void
    {
        $std = $this->tipe('STD');

        $this->simpan($this->galeri($std, ['baru:0'], [UploadedFile::fake()->image('besar.jpg')->size(3072)]), $std)->assertOk();

        $this->assertCount(1, $std->refresh()->photos);
    }

    public function test_TK12_lebih_dari_lima_foto_tetap_diterima_karena_tanpa_batas_jumlah(): void
    {
        $lama = ['room-types/1.jpg', 'room-types/2.jpg', 'room-types/3.jpg', 'room-types/4.jpg'];
        $this->fotoTersimpan($lama);
        $std = $this->tipe('STD');
        $std->update(['photos' => $lama]);

        $urutan = [...array_map(fn ($p) => "lama:$p", $lama), 'baru:0', 'baru:1'];
        $this->simpan($this->galeri($std, $urutan, [UploadedFile::fake()->image('5.jpg'), UploadedFile::fake()->image('6.jpg')]), $std)->assertOk();

        $this->assertCount(6, $std->refresh()->photos);
    }

    public function test_TK13_jadikan_foto_kedua_sebagai_foto_utama(): void
    {
        $this->fotoTersimpan(['room-types/a.jpg', 'room-types/b.jpg']);
        $std = $this->tipe('STD');
        $std->update(['photos' => ['room-types/a.jpg', 'room-types/b.jpg']]);

        $this->simpan($this->galeri($std, ['lama:room-types/b.jpg', 'lama:room-types/a.jpg']), $std)->assertOk();

        $this->assertSame(['room-types/b.jpg', 'room-types/a.jpg'], $std->refresh()->photos);
        $this->assertStringEndsWith('storage/room-types/b.jpg', $std->main_photo_url);
    }

    public function test_TK14_hapus_foto_pertama_dari_data_dan_disk(): void
    {
        $this->fotoTersimpan(['room-types/a.jpg', 'room-types/b.jpg']);
        $std = $this->tipe('STD');
        $std->update(['photos' => ['room-types/a.jpg', 'room-types/b.jpg']]);

        $this->simpan($this->galeri($std, ['lama:room-types/b.jpg']), $std)->assertOk();

        $this->assertSame(['room-types/b.jpg'], $std->refresh()->photos);
        Storage::disk('public')->assertMissing('room-types/a.jpg');
    }

    public function test_TK15_hapus_tipe_yang_masih_punya_kamar_ditolak(): void
    {
        $std = $this->tipe('STD');

        $this->delete(route('staf.tipe-kamar.destroy', $std))
            ->assertSessionHas('error', 'Tipe kamar ini masih dipakai oleh 10 kamar. Pindahkan atau nonaktifkan kamarnya dulu.');

        $this->assertDatabaseHas('room_types', ['id' => $std->id]);
        $this->assertSame(4, $std->facilities()->count());
    }

    public function test_TK16_hapus_tipe_tanpa_kamar_beserta_relasi_dan_fotonya(): void
    {
        $this->simpan(self::SUP + ['fasilitas' => [1, 3], 'foto_diatur' => '1', 'urutan' => ['baru:0'], 'foto_baru' => [UploadedFile::fake()->image('sup.jpg')]])->assertOk();
        $sup = $this->tipe('SUP');
        $foto = $sup->photos[0];
        Storage::disk('public')->assertExists($foto);

        $this->delete(route('staf.tipe-kamar.destroy', $sup))->assertSessionHas('status', 'Tipe kamar Superior berhasil dihapus.');

        $this->assertDatabaseMissing('room_types', ['id' => $sup->id]);
        $this->assertDatabaseMissing('room_type_facilities', ['room_type_id' => $sup->id]);
        Storage::disk('public')->assertMissing($foto);
    }
}
