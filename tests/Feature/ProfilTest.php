<?php

namespace Tests\Feature;

use App\Livewire\Profil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Halaman Profil: ubah data diri (ikut ke data pelanggan) dan kata sandi, untuk tamu maupun staf. */
class ProfilTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private int $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $akun = $this->akun('customer');
        $akun->forceFill(['phone' => '081277770001'])->save();
        $this->pelanggan = DB::table('customers')->insertGetId([
            'user_id' => $akun->id, 'full_name' => $akun->name, 'phone' => '081277770001', 'email' => $akun->email, 'is_active' => 1,
        ]);
    }

    public function test_PR01_halaman_profil_butuh_login_dan_tampil_untuk_tamu_dan_staf(): void
    {
        $this->get(route('profil'))->assertRedirect(route('login'));
        $this->actingAs($this->akun('customer'))->get(route('profil'))->assertOk()->assertSee('Data diri')->assertSee('Lihat pesanan saya');
        // Staf membuka profil di panel staf, tanpa ringkasan pesanan
        $this->actingAs($this->akun('fo'))->get(route('profil'))->assertOk()->assertSee('Panel Staf')->assertDontSee('Lihat pesanan saya');
    }

    public function test_PR02_ubah_data_diri_ikut_memperbarui_data_pelanggan(): void
    {
        Livewire::actingAs($this->akun('customer'))->test(Profil::class)
            ->set('name', 'Citra  Lestari  D’Silva')->set('phone', '+62 812-7777-0099')->set('email', 'CITRA.BARU@contoh.id')
            ->set('gender', 'P')->set('date_of_birth', '1995-04-12')->set('address', 'Jl. Ahmad Yani 5, Malili')
            ->call('simpanData')->assertHasNoErrors()->assertDispatched('toast');

        $this->assertDatabaseHas('users', ['id' => $this->akun('customer')->id, 'name' => "Citra Lestari D'Silva", 'phone' => '081277770099', 'email' => 'citra.baru@contoh.id']);
        $this->assertDatabaseHas('customers', ['id' => $this->pelanggan, 'full_name' => "Citra Lestari D'Silva", 'phone' => '081277770099', 'email' => 'citra.baru@contoh.id',
            'gender' => 'P', 'date_of_birth' => '1995-04-12', 'address' => 'Jl. Ahmad Yani 5, Malili']);
    }

    public function test_PR03_email_dan_nomor_milik_akun_lain_ditolak(): void
    {
        $lain = $this->akun('fo');
        $lain->forceFill(['phone' => '081355550001'])->save();

        Livewire::actingAs($this->akun('customer'))->test(Profil::class)
            ->set('email', $lain->email)->set('phone', '081355550001')->set('name', 'C1tra')
            ->call('simpanData')
            ->assertHasErrors(['email' => 'unique', 'phone' => 'unique', 'name' => 'regex']);
    }

    public function test_PR04_ganti_kata_sandi_butuh_sandi_lama_yang_benar(): void
    {
        $akun = $this->akun('customer');
        Livewire::actingAs($akun)->test(Profil::class)
            ->set('sandiLama', 'salah123')->set('sandiBaru', 'sandibaru123')->set('sandiBaru_confirmation', 'sandibaru123')
            ->call('simpanSandi')->assertHasErrors(['sandiLama'])
            ->set('sandiLama', 'password')->set('sandiBaru', 'password')->set('sandiBaru_confirmation', 'password')
            ->call('simpanSandi')->assertHasErrors(['sandiBaru'])
            ->set('sandiLama', 'password')->set('sandiBaru', 'sandibaru123')->set('sandiBaru_confirmation', 'beda')
            ->call('simpanSandi')->assertHasErrors(['sandiBaru' => 'confirmed'])
            ->set('sandiLama', 'password')->set('sandiBaru', 'sandibaru123')->set('sandiBaru_confirmation', 'sandibaru123')
            ->call('simpanSandi')->assertHasNoErrors()->assertSet('sandiLama', '');

        $this->assertTrue(Hash::check('sandibaru123', $akun->fresh()->password));
    }

    public function test_PR05_akun_google_tanpa_sandi_bisa_membuat_kata_sandi(): void
    {
        $akun = $this->akun('customer');
        $akun->forceFill(['password' => null])->save();
        DB::table('social_accounts')->insert(['user_id' => $akun->id, 'provider' => 'google', 'provider_user_id' => 'g-123', 'provider_email' => $akun->email]);

        $this->actingAs($akun)->get(route('profil'))->assertSee('Buat kata sandi')->assertSee('Terhubung');
        Livewire::actingAs($akun)->test(Profil::class)
            ->set('sandiBaru', 'sandibaru123')->set('sandiBaru_confirmation', 'sandibaru123')
            ->call('simpanSandi')->assertHasNoErrors();

        $this->assertTrue(Hash::check('sandibaru123', $akun->fresh()->password));
    }
}
