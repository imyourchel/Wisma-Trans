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
        $kriteria = [];
        foreach ([['C1', 'Waktu menuju check-in berikutnya', 'cost', 0.647947], ['C2', 'Status kamar', 'benefit', 0.229871], ['C3', 'Lama menunggu', 'benefit', 0.122182]] as [$kode, $nama, $sifat, $bobot]) {
            $kriteria[$kode] = [DB::table('cleaning_criteria')->insertGetId(['code' => $kode, 'name' => $nama, 'attribute_type' => $sifat, 'is_active' => 1]), $bobot];
        }
        $sesi = DB::table('ahp_sessions')->insertGetId([
            'session_name' => 'Bobot dari penelitian terdahulu', 'calculated_at' => '2026-09-01 08:00:00', 'lambda_max' => 3.003697,
            'consistency_index' => 0.001848, 'random_index' => 0.58, 'consistency_ratio' => 0.003187, 'is_active' => 1,
        ]);
        foreach ($kriteria as [$id, $bobot]) {
            DB::table('ahp_criteria_weights')->insert(['ahp_session_id' => $sesi, 'criteria_id' => $id, 'weight' => $bobot]);
        }

        $this->actingAs($this->akun('admin'))->get(route('staf.kriteria.index'))->assertOk()
            ->assertSee('Waktu menuju check-in berikutnya')->assertSee('0,647947')->assertSee('0,003187')
            ->assertSee('bobot konsisten dan dipakai')->assertDontSee('Tambah kriteria');

        // Tidak ada jalan untuk menambah, mengubah, atau menonaktifkan kriteria
        foreach (['staf.kriteria.store', 'staf.kriteria.update', 'staf.kriteria.toggle'] as $nama) {
            $this->assertFalse(Route::has($nama), "Rute {$nama} masih ada");
        }
        $this->actingAs($this->akun('admin'))->post('/staf/kriteria', ['code' => 'C4', 'name' => 'Baru', 'attribute_type' => 'benefit'])->assertStatus(405);
        $this->assertSame(3, DB::table('cleaning_criteria')->count());
    }
}
