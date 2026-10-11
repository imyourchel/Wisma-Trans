<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Kriteria pembersihan dan bobot AHP sudah tetap dari penelitian: halaman Admin hanya menampilkannya. */
class KriteriaTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    public function test_KR01_kriteria_hanya_dibaca_dan_menampilkan_bobot_ahp(): void
    {
        $this->siapkanDataContoh();
        $this->siapkanKriteriaAhp();

        $this->actingAs($this->akun('admin'))->get(route('staf.kriteria.index'))->assertOk()
            ->assertSee('Waktu menuju check-in tamu berikutnya')->assertSee('Durasi kamar sudah kotor')
            ->assertSee('0,647947')->assertSee('0,003187')
            ->assertSee('bobot konsisten dan dipakai')->assertDontSee('Tambah kriteria');

        // Tidak ada jalan untuk menambah, mengubah, atau menonaktifkan kriteria
        foreach (['staf.kriteria.store', 'staf.kriteria.update', 'staf.kriteria.toggle'] as $nama) {
            $this->assertFalse(Route::has($nama), "Rute {$nama} masih ada");
        }
        $this->actingAs($this->akun('admin'))->post('/staf/kriteria', ['code' => 'C4', 'name' => 'Baru', 'attribute_type' => 'benefit'])->assertStatus(405);
        $this->assertSame(3, DB::table('cleaning_criteria')->count());
    }
}
