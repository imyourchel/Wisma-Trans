<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Tes asap: setiap route GET bernama tanpa parameter wajib dibuka sebagai tamu (tanpa login) dan sebagai setiap peran.
 * Tidak boleh ada respons 5xx. Menangkap view atau komponen Livewire yang path-nya terlewat saat struktur folder
 * berubah, termasuk halaman yang belum punya tes khusus. 403, 404, dan pengalihan ke login tetap dianggap wajar.
 */
class SemuaHalamanTerbukaTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    /** Peran yang dicoba; null = tamu tanpa login. */
    private const PERAN = [null, 'customer', 'admin', 'manager', 'fo', 'fnb', 'hk'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->siapkanKriteriaAhp();
        $this->siapkanSettingTagihan();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<RouteItem> */
    private function routeTanpaParameter(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteItem $r) => in_array('GET', $r->methods(), true) && $r->getName() !== null
                && preg_match('/\{\w+\}/', $r->uri()) !== 1)
            ->values()->all();
    }

    public function test_semua_halaman_get_tanpa_parameter_tidak_error_server(): void
    {
        $route = $this->routeTanpaParameter();
        $this->assertGreaterThan(50, count($route), 'Daftar route terlalu sedikit; penyaring route mungkin salah.');

        $gagal = [];
        $dibuka = 0;
        foreach (self::PERAN as $peran) {
            foreach ($route as $r) {
                $this->app['auth']->forgetGuards();
                $uji = $peran ? $this->actingAs($this->akun($peran)) : $this;
                $res = $uji->get('/'.ltrim($r->uri(), '/'));
                $dibuka++;
                if ($res->getStatusCode() >= 500) {
                    $gagal[] = sprintf('%s (%s) sebagai %s: %d %s', $r->getName(), $r->uri(), $peran ?? 'tamu', $res->getStatusCode(),
                        $res->exception ? class_basename($res->exception).': '.$res->exception->getMessage() : '');
                }
            }
        }

        $this->assertSame([], $gagal, implode("\n", $gagal));
        $this->assertSame(count($route) * count(self::PERAN), $dibuka);
    }
}
