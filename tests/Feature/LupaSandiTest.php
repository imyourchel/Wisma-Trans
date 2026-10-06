<?php

namespace Tests\Feature;

use App\Notifications\AturUlangKataSandi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Lupa kata sandi boleh lewat email atau nomor WhatsApp, sama seperti saat masuk. */
class LupaSandiTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        config(['services.fonnte.token' => 'token-uji']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        $this->akun('customer')->forceFill(['phone' => '081277770001'])->save();
    }

    public function test_LS01_lewat_email_tautan_dikirim_ke_email(): void
    {
        $akun = $this->akun('customer');
        $this->postJson(route('password.email'), ['login' => strtoupper($akun->email)])
            ->assertOk()->assertJsonPath('pesan', 'Jika email tersebut terdaftar, tautan untuk membuat kata sandi baru sudah dikirim.');

        Notification::assertSentTo($akun, AturUlangKataSandi::class);
        Http::assertNothingSent();
    }

    public function test_LS02_lewat_nomor_tautan_dikirim_ke_whatsapp_dan_email_akun(): void
    {
        $akun = $this->akun('customer');
        $this->postJson(route('password.email'), ['login' => '+62 812-7777-0001'])->assertOk()
            ->assertJsonPath('pesan', 'Jika nomor tersebut terdaftar, tautan untuk membuat kata sandi baru sudah dikirim ke WhatsApp dan email akun Anda.');

        $token = null;
        Notification::assertSentTo($akun, AturUlangKataSandi::class, function (AturUlangKataSandi $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        // Tautan di WhatsApp memakai token yang sama dengan email, dan bisa dipakai membuat kata sandi baru
        Http::assertSent(fn ($r) => $r['target'] === '081277770001' && str_contains($r['message'], route('password.reset', ['token' => $token, 'email' => $akun->email])));

        // Permintaan ulang dalam 60 detik tidak mengirim apa-apa lagi
        $this->postJson(route('password.email'), ['login' => '081277770001'])->assertOk();
        Http::assertSentCount(1);
        Notification::assertSentToTimes($akun, AturUlangKataSandi::class, 1);

        $this->postJson(route('password.update'), ['token' => $token, 'email' => $akun->email, 'password' => 'sandibaru123', 'password_confirmation' => 'sandibaru123'])
            ->assertOk();
        $this->assertAuthenticatedAs($akun);
    }

    public function test_LS03_nomor_tidak_terdaftar_jawabannya_sama_dan_nomor_asal_ditolak(): void
    {
        $this->postJson(route('password.email'), ['login' => '081299998888'])->assertOk()
            ->assertJsonPath('pesan', 'Jika nomor tersebut terdaftar, tautan untuk membuat kata sandi baru sudah dikirim ke WhatsApp dan email akun Anda.');
        Notification::assertNothingSent();
        Http::assertNothingSent();

        $this->postJson(route('password.email'), ['login' => '12345'])->assertStatus(422)
            ->assertJsonValidationErrors(['login' => 'Nomor WhatsApp tidak valid. Contoh: 081234567890.']);
        $this->postJson(route('password.email'), ['login' => ''])->assertStatus(422)
            ->assertJsonValidationErrors(['login' => 'Email atau nomor WhatsApp wajib diisi.']);
    }
}
