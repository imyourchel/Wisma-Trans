<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Google One Tap: ID token diperiksa ke Google (dipalsukan), lalu aturan akun sama dengan "Masuk dengan Google". */
class GoogleOneTapTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const CLIENT = 'uji-client.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        config(['services.google.client_id' => self::CLIENT, 'services.google.client_secret' => 'uji']);
        Http::preventStrayRequests();
    }

    private function token(array $ubah = []): void
    {
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response([
            'aud' => self::CLIENT, 'iss' => 'https://accounts.google.com', 'sub' => '1122334455', 'exp' => (string) (now()->timestamp + 600),
            'email' => 'citra@contoh.id', 'email_verified' => 'true', 'name' => 'Citra Lestari', ...$ubah,
        ])]);
    }

    private function kirim(?string $kembali = null)
    {
        return $this->postJson(route('auth.google.one-tap'), ['credential' => 'header.payload.tanda', 'kembali' => $kembali ?? route('aula')]);
    }

    public function test_akun_yang_ada_masuk_dan_kembali_ke_halaman_asal(): void
    {
        $this->token();
        $this->kirim()->assertOk()->assertJsonPath('redirect', route('aula'));

        $this->assertAuthenticatedAs($this->akun('customer'));
        $this->assertSame('citra@contoh.id', SocialAccount::where('provider_user_id', '1122334455')->value('provider_email'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tokeninfo') && $r['id_token'] === 'header.payload.tanda');
    }

    public function test_email_baru_melengkapi_nomor_hp_dulu(): void
    {
        $this->token(['email' => 'baru@contoh.id', 'name' => 'Dian Permata']);
        $this->kirim()->assertOk()->assertJsonPath('redirect', route('auth.google.lengkapi'));

        $this->assertGuest();
        $this->assertSame(['id' => '1122334455', 'email' => 'baru@contoh.id', 'name' => 'Dian Permata'], session('google_pending'));
    }

    public function test_token_untuk_aplikasi_lain_kedaluwarsa_atau_ditolak_google_tidak_masuk(): void
    {
        foreach ([['aud' => 'aplikasi-lain'], ['iss' => 'https://palsu.test'], ['exp' => (string) (now()->timestamp - 5)]] as $ubah) {
            $this->token($ubah);
            $this->kirim()->assertStatus(422);
            $this->assertGuest();
        }
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response(['error' => 'invalid_token'], 400)]);
        $this->kirim()->assertStatus(422);
        $this->assertGuest();
    }

    public function test_email_belum_terverifikasi_tidak_masuk(): void
    {
        $this->token(['email_verified' => 'false']);
        $this->kirim()->assertOk()->assertJsonPath('redirect', route('home'));
        $this->assertGuest();
        $this->assertSame('Email akun Google Anda belum terverifikasi.', session('error'));
    }

    public function test_skrip_one_tap_hanya_untuk_tamu_dan_tidak_di_halaman_masuk(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('accounts.google.com/gsi/client', false)->assertSee(self::CLIENT, false)
            // Ditawarkan sekali per kunjungan: tanda sessionStorage diperiksa sebelum pustaka Google dimuat
            ->assertSeeInOrder(["const KUNCI = 'wt.one-tap'", 'sessionStorage.getItem(KUNCI)', 'if (baca()) return;', 'google.accounts.id.prompt()', 'gsi/client'], false);
        $this->get(route('login'))->assertOk()->assertDontSee('accounts.google.com/gsi/client', false);

        config(['services.google.client_id' => null]);
        $this->get(route('home'))->assertOk()->assertDontSee('accounts.google.com/gsi/client', false);

        config(['services.google.client_id' => self::CLIENT]);
        $this->actingAs($this->akun('customer'))->get(route('home'))->assertOk()->assertDontSee('accounts.google.com/gsi/client', false);
    }
}
