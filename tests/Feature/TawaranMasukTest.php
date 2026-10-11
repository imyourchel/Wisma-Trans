<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Js;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Tawaran masuk sebelum mengisi data pemesan, dan kembali ke formulir yang sama setelah masuk/daftar. */
class TawaranMasukTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private string $urlKamar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->siapkanDataContoh();
        $this->withoutVite();
        // Laundry dan aula butuh layanan/aula aktif agar formulirnya tampil
        DB::table('halls')->insertOrIgnore(['id' => 1, 'name' => 'Aula Utama', 'capacity' => 100, 'is_active' => 1]);
        $this->urlKamar = route('kamar.pesan', 'std').'?masuk=2026-10-12&keluar=2026-10-14&tamu=2-0';
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> halaman formulir tamu yang meminta data pemesan */
    private function halaman(): array
    {
        return [
            'kamar' => $this->urlKamar,
            'resto' => route('menu'),
            'laundry' => route('laundry', ['jenis' => 'luar']),
            'aula' => route('aula'),
            'instansi' => route('instansi'),
        ];
    }

    public function test_tamu_ditawari_masuk_di_setiap_formulir_pemesanan(): void
    {
        foreach ($this->halaman() as $nama => $url) {
            $this->get($url)->assertOk()->assertSee('Sudah punya akun?', false)->assertSee("buka-akun', { mode: 'masuk'", false);
        }
        // Resto: setelah masuk, panel pesanan dibuka lagi
        $this->get(route('menu'))->assertSee('kembali: '.Js::from(route('menu', ['lanjut' => 1]))->toHtml(), false);
    }

    public function test_pengguna_yang_sudah_masuk_tidak_ditawari(): void
    {
        $this->actingAs($this->akun('customer'));
        foreach ($this->halaman() as $nama => $url) {
            $this->get($url)->assertOk()->assertDontSee('Sudah punya akun?');
        }
    }

    public function test_setelah_masuk_tamu_kembali_ke_formulir_yang_sama(): void
    {
        $this->postJson(route('login.store'), ['login' => 'citra@contoh.id', 'password' => 'password', 'kembali' => $this->urlKamar])
            ->assertOk()->assertJsonPath('redirect', $this->urlKamar);
        $this->assertAuthenticatedAs($this->akun('customer'));
        $this->assertNull(session('kembali_tamu'));
    }

    public function test_alamat_kembali_di_luar_situs_atau_halaman_masuk_diabaikan(): void
    {
        foreach (['https://situs-lain.test/kamar', '//situs-lain.test/kamar', route('login'), route('register'), url('/staf/dashboard'), 'javascript:alert(1)'] as $kembali) {
            $this->postJson(route('login.store'), ['login' => 'citra@contoh.id', 'password' => 'password', 'kembali' => $kembali])
                ->assertOk()->assertJsonPath('redirect', route('home'));
            $this->post(route('logout'));
        }
    }

    public function test_staf_tetap_ke_panel_staf(): void
    {
        $this->postJson(route('login.store'), ['login' => 'yusuf@wismatrans.id', 'password' => 'password', 'kembali' => $this->urlKamar])
            ->assertOk()->assertJsonPath('redirect', route('staf.dashboard'));
    }

    public function test_setelah_daftar_tamu_kembali_ke_formulir(): void
    {
        Notification::fake();

        $this->postJson(route('register.store'), [
            'name' => 'Rina Amalia', 'phone' => '081377788899', 'email' => 'rina.amalia@contoh.id',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'kembali' => route('aula'),
        ])->assertOk()->assertJsonPath('redirect', route('aula'));
    }

    public function test_masuk_google_menyimpan_halaman_asal(): void
    {
        config(['services.google.client_id' => 'uji', 'services.google.client_secret' => 'uji', 'services.google.redirect' => route('auth.google.callback')]);

        $this->get(route('auth.google', ['kembali' => route('menu', ['lanjut' => 1])]))->assertRedirect();
        $this->assertSame(route('menu', ['lanjut' => 1]), session('kembali_tamu'));
    }

    public function test_modal_membawa_alamat_asal(): void
    {
        $this->get($this->urlKamar)->assertOk()
            ->assertSee('<input type="hidden" name="kembali" :value="kembali">', false)
            ->assertSee("'?kembali=' + encodeURIComponent(kembali)", false);
        $this->assertInstanceOf(User::class, $this->akun('customer'));
    }
}
