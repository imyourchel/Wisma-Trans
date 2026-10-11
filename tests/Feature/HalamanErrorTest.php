<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Halaman error berbahasa Indonesia (403, 404, 419, 429, 500) dan JSON untuk endpoint fetch. */
class HalamanErrorTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_E01_404_tetap_memakai_layout_tamu(): void
    {
        $this->get('/halaman-yang-tidak-ada')->assertNotFound()
            ->assertSee('Halaman tidak ditemukan')->assertSee('Kembali ke beranda')->assertSee('wa.me/6282292224111', false)
            ->assertSee('Menu utama'); // navbar layout tamu
    }

    public function test_E02_403_hak_akses_tanpa_menyebut_peran_dan_tombol_sesuai_pengguna(): void
    {
        $this->actingAs($this->akun('fo'))->get(route('staf.pelanggan.instansi'))->assertForbidden()
            ->assertSee('Akses ditolak')->assertSee('Halaman ini bukan untuk peran Anda.')->assertSee('Kembali ke Dashboard')
            ->assertDontSee('This action is unauthorized')->assertDontSee('manage-institutions')->assertDontSee('Tautan kedaluwarsa');

        // Tamu yang membuka area staf: pesan dari middleware dipakai apa adanya, tombol ke beranda
        $this->actingAs($this->akun('customer'))->get(route('staf.dashboard'))->assertForbidden()
            ->assertSee('Halaman ini khusus staf Wisma Trans.')->assertSee('Kembali ke beranda')->assertDontSee('Kembali ke Dashboard');
    }

    public function test_E03_403_ringan_tanpa_skrip_supaya_rapi_di_modal_livewire(): void
    {
        $html = $this->actingAs($this->akun('hk'))->get(route('staf.pelanggan'))->assertForbidden()->getContent();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('Menu utama', $html);
        $this->assertStringContainsString('target="_top"', $html);
    }

    public function test_E04_tautan_bertanda_tangan_kedaluwarsa_bukan_pesan_hak_akses(): void
    {
        $url = URL::temporarySignedRoute('bukti-instansi.unduh', now()->subMinute(), ['kode' => 'RSV-0001']);
        $this->get($url)->assertForbidden()
            ->assertSee('Tautan kedaluwarsa')->assertSee('Tautan ini sudah kedaluwarsa. Minta tautan baru lewat WhatsApp.')
            ->assertSee('wa.me/6282292224111', false)->assertDontSee('bukan untuk peran Anda');

        // Versi JSON
        $this->getJson($url)->assertForbidden()->assertJson(['pesan' => 'Tautan ini sudah kedaluwarsa. Minta tautan baru lewat WhatsApp.']);
    }

    public function test_E05_419_halaman_dan_json(): void
    {
        Route::middleware('web')->post('/uji/sesi-habis', fn () => throw new TokenMismatchException);

        $this->from(route('instansi'))->post('/uji/sesi-habis')->assertStatus(419)
            ->assertSee('Sesi berakhir')->assertSee('Buka halaman sebelumnya')->assertSee(route('instansi'), false)->assertDontSee('Page Expired');
        $this->postJson('/uji/sesi-habis')->assertStatus(419)->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.', 'pesan' => 'Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.']);
    }

    public function test_E06_429_endpoint_json_tetap_json_dan_halaman_biasa_berbahasa_indonesia(): void
    {
        // Pesan makanan (fetch dari Resto): batas 10 per menit
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('resto.pesan'), [])->assertStatus(422);
        }
        $this->postJson(route('resto.pesan'), [])->assertStatus(429)->assertHeader('Content-Type', 'application/json')
            ->assertJson(['pesan' => 'Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.'])->assertHeader('Retry-After');

        // Formulir biasa (Cek Pesanan): halaman 429
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('cek.cari'), ['kode' => 'X']);
        }
        $this->post(route('cek.cari'), ['kode' => 'X'])->assertStatus(429)
            ->assertSee('Terlalu banyak percobaan')->assertSee('Tunggu satu menit')->assertDontSee('Too Many');
    }

    public function test_E07_403_pada_endpoint_json_tetap_json(): void
    {
        // Formulir data master dikirim lewat fetch (staf-skrip); FO tidak berhak
        $this->actingAs($this->akun('fo'))->postJson(route('staf.tipe-kamar.store'), [])->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')->assertJson(['pesan' => 'Halaman ini bukan untuk peran Anda.']);
    }

    public function test_E08_500_mandiri_tanpa_basis_data(): void
    {
        config(['app.debug' => false, 'logging.default' => 'null']);
        Route::middleware('web')->get('/uji/rusak', fn () => throw new RuntimeException('Uji'));
        $this->get('/uji/rusak')->assertStatus(500)->assertSee('Terjadi kesalahan')->assertDontSee('Server Error');

        // Basis data tidak bisa dibaca sama sekali: halaman 500 tetap terbentuk
        DB::connection()->beforeExecuting(fn () => throw new RuntimeException('Basis data mati'));
        $html = view('errors.500')->render();
        $this->assertStringContainsString('Terjadi kesalahan', $html);
        $this->assertStringNotContainsString('build/assets', $html);
    }
}
