<?php

namespace Tests\Feature;

use App\Livewire\Tamu\PesanLaundry;
use App\Models\Hall;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Resto: foto menu bisa diketuk untuk dilihat detail. Resto, Laundry, Aula: tawaran masuk berupa pop-up berisi manfaat akun.
 * Resto dan Laundry: label pilihan jenis tamu beserta keterangannya.
 */
class RestoFotoDanTawaranAkunTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function menu(string $kode, string $nama, ?string $foto = null): Service
    {
        if ($foto) {
            Storage::disk('public')->put($foto, UploadedFile::fake()->image('menu.jpg')->getContent());
        }

        return Service::create([
            'code' => $kode, 'name' => $nama, 'description' => 'Teh melati dengan es', 'category' => 'fnb', 'menu_group' => 'Teh',
            'department' => 'fnb', 'unit' => 'gelas', 'price' => 8000, 'is_active' => true, 'photo' => $foto,
        ]);
    }

    public function test_foto_menu_bisa_diketuk_untuk_dilihat_detail(): void
    {
        $teh = $this->menu('TEH01', 'Teh Manis', 'Layanan/TEH01.jpg');
        $tawar = $this->menu('TEH02', 'Teh Tawar');

        $this->get(route('menu'))->assertOk()
            ->assertSee('data-penampil-foto', false)
            ->assertSee('data-lihat-foto="'.$teh->id.'"', false)
            ->assertSee('aria-label="Lihat foto Teh Manis"', false)
            ->assertSee('storage/Layanan/TEH01.jpg', false)
            ->assertSee('Ketuk foto untuk memperbesar.')
            // Menu tanpa foto tidak punya tombol foto
            ->assertDontSee('data-lihat-foto="'.$tawar->id.'"', false)
            ->assertDontSee('aria-label="Lihat foto Teh Tawar"', false);
    }

    public function test_tamu_ditawari_masuk_lewat_pop_up_berisi_manfaat_akun(): void
    {
        $this->menu('TEH01', 'Teh Manis');
        Hall::create(['name' => 'Aula Utama', 'capacity' => 100, 'description' => 'Aula serbaguna', 'is_active' => true]);

        // Resto: muncul saat Lanjut ditekan; varian tamu luar, tamu menginap, dan tamu menginap tanpa kode kamar
        $this->get(route('menu'))->assertOk()
            ->assertSee('data-tawar-akun', false)->assertSee('Pesan lebih praktis dengan akun Anda')->assertSee('Buat akun gratis')
            ->assertSee('data-varian-akun="luar"', false)->assertSee('data-varian-akun="menginap"', false)->assertSee('data-varian-akun="kamar"', false)
            ->assertSee('Lanjutkan sebagai tamu')->assertSee('Gunakan kode kamar')
            ->assertSee("tawar-akun', { varian: 'kamar' }", false)
            ->assertSee('Isi keranjang Anda tetap tersimpan setelah masuk.')
            // Banner x-tawaran-masuk di atas panel pesanan sudah diganti pop-up
            ->assertDontSee('<span class="font-semibold text-ink-900">Sudah punya akun?</span>', false);

        // Laundry dan Aula: muncul saat mulai mengisi formulir (focusin), sebelum ada isian yang bisa hilang
        foreach ([route('laundry.pesan') => ['menginap', 'luar'], route('aula.ajukan') => ['aula']] as $url => $varian) {
            $r = $this->get($url)->assertOk()->assertSee('data-tawar-akun', false)->assertSee('@focusin.once', false)
                ->assertSee('Lanjutkan sebagai tamu')->assertSee('Sebaiknya masuk sebelum mengisi formulir agar isian tidak perlu diulang.')
                ->assertDontSee('<span class="font-semibold text-ink-900">Sudah punya akun?</span>', false);
            foreach ($varian as $v) {
                $r->assertSee('data-varian-akun="'.$v.'"', false);
            }
        }

        // Sudah masuk: tidak ditawari
        $this->actingAs($this->akun('customer'));
        foreach ([route('menu'), route('laundry.pesan'), route('aula.ajukan')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('data-tawar-akun', false)->assertDontSee('@focusin.once', false);
        }
    }

    public function test_pilihan_jenis_tamu_berlabel_singkat_dengan_keterangan(): void
    {
        $this->menu('TEH01', 'Teh Manis');
        $luar = 'Bayar langsung saat memesan.';
        $menginap = 'Masukkan kode kamar, tagihan masuk ke nota kamar Anda.';

        // Resto: keterangan mengikuti pilihan aktif (Alpine), awalnya Tidak menginap
        $this->get(route('menu'))->assertOk()
            ->assertSeeInOrder(['Tidak menginap', 'Sedang menginap', 'data-keterangan-jenis'], false)
            ->assertSee($luar)->assertSee($menginap)
            ->assertDontSee('Saya tamu luar')->assertDontSee('Saya tamu yang sedang menginap');

        // Laundry: keterangan sesuai jenis yang dipilih, nilai jenis tetap menginap/luar
        Livewire::test(PesanLaundry::class)
            ->assertSeeInOrder(['Sedang menginap', 'Tidak menginap'])->assertSee($menginap)->assertDontSee($luar)
            ->assertDontSee('Saya sedang menginap')->assertDontSee('Saya tamu luar')
            ->call('$set', 'jenis', 'luar')->assertSet('jenis', 'luar')
            ->assertSee($luar)->assertDontSee($menginap);
    }
}
