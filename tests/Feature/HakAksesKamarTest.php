<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Bagian G (hak akses halaman modul kamar). */
class HakAksesKamarTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const MASTER = ['staf.tipe-kamar.index', 'staf.kamar.index'];

    private const PAPAN = 'staf.papan-status';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
    }

    private function buka(string $akun, string $route): int
    {
        return $this->actingAs($this->akun($akun))->get(route($route))->getStatusCode();
    }

    public function test_HA01_belum_login_diarahkan_ke_halaman_masuk(): void
    {
        foreach ([...self::MASTER, self::PAPAN] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_HA02_admin_membuka_semua_halaman_modul(): void
    {
        foreach ([...self::MASTER, self::PAPAN] as $route) {
            $this->assertSame(200, $this->buka('admin', $route), $route);
        }
    }

    public function test_HA03_manager_tidak_bisa_membuka_data_master(): void
    {
        foreach (self::MASTER as $route) {
            $this->assertSame(403, $this->buka('manager', $route), $route);
        }
    }

    public function test_HA04_manager_membuka_papan_status(): void
    {
        $this->assertSame(200, $this->buka('manager', self::PAPAN));
    }

    public function test_HA05_fo_dan_hk_tidak_bisa_membuka_data_master(): void
    {
        foreach (['fo', 'hk'] as $akun) {
            foreach (self::MASTER as $route) {
                $this->assertSame(403, $this->buka($akun, $route), "$akun $route");
            }
        }
    }

    public function test_HA06_fo_dan_hk_membuka_papan_status(): void
    {
        foreach (['fo', 'hk'] as $akun) {
            $this->assertSame(200, $this->buka($akun, self::PAPAN), $akun);
        }
    }

    public function test_HA07_fnb_dan_customer_tidak_bisa_membuka_papan_status(): void
    {
        foreach (['fnb', 'customer'] as $akun) {
            $this->assertSame(403, $this->buka($akun, self::PAPAN), $akun);
        }
    }

    public function test_HA08_fnb_mengirim_tambah_kamar_langsung_ditolak(): void
    {
        $this->actingAs($this->akun('fnb'))
            ->postJson(route('staf.kamar.store'), ['room_number' => '217', 'room_type_id' => 4, 'floor' => 2, 'bed_type' => 'king'])
            ->assertForbidden();

        $this->assertDatabaseCount('rooms', 32);
    }
}
