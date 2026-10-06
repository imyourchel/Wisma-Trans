<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\PembayaranMidtrans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Pesan makanan online dari halaman Resto: catatan per menu untuk dapur. */
class PesananMakananTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.midtrans.server_key' => 'uji-server-key', 'services.midtrans.client_key' => 'uji-client-key']);
        // Tagihan tidak meminta ke Midtrans sungguhan
        $this->app->instance(PembayaranMidtrans::class, new class extends PembayaranMidtrans
        {
            public array $dibuat = [];

            public function buatTagihan(BayarOnline $order, string $metode): array
            {
                $this->dibuat[] = $metode;

                $tagihan = ['metode' => $metode, 'order_id' => 'WT-UJI', 'jumlah' => $order->jumlahBayar(),
                    'nomor' => $metode === 'qris' ? null : '8277000012345678', 'kode_perusahaan' => $metode === 'mandiri' ? '70012' : null,
                    'qr' => $metode === 'qris' ? 'https://contoh.test/qr.png' : null];
                Cache::put(PembayaranMidtrans::kunci($order, 'tagihan'), $tagihan);

                return $tagihan;
            }

            public function sinkronkan(BayarOnline $order): void {}
        });
    }

    private function menu(string $kode, string $nama, int $harga): Service
    {
        return Service::create([
            'code' => $kode, 'name' => $nama, 'category' => 'fnb', 'menu_group' => 'Makanan Utama',
            'department' => 'fnb', 'unit' => 'porsi', 'price' => $harga, 'is_active' => true,
        ]);
    }

    private function pesan(array $items, ?string $metode = 'bca')
    {
        return $this->postJson(route('resto.pesan'), [
            'items' => $items, 'name' => 'Dian Permata', 'phone' => '081234567890',
            'serving_type' => 'dine_in', 'note' => 'Meja dekat jendela', 'metode' => $metode,
        ]);
    }

    public function test_PM01_catatan_per_menu_tersimpan_di_item_pesanan(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        $teh = $this->menu('F-TM', 'Teh Manis', 10000);

        $this->pesan([
            ['id' => $nasi->id, 'qty' => 2, 'note' => '  tidak pedas  '],
            ['id' => $teh->id, 'qty' => 1, 'note' => ''],
        ])->assertOk()->assertJsonStructure(['status_url']);

        $order = ServiceOrder::with('items')->sole();
        $this->assertSame('Meja dekat jendela', $order->note);
        $this->assertSame('tidak pedas', $order->items->firstWhere('service_id', $nasi->id)->note);
        $this->assertNull($order->items->firstWhere('service_id', $teh->id)->note);
    }

    public function test_PM02_catatan_per_menu_lebih_dari_150_karakter_ditolak(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);

        $this->pesan([['id' => $nasi->id, 'qty' => 1, 'note' => str_repeat('a', 151)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.note' => 'Catatan per menu maksimal 150 karakter.']);

        $this->assertSame(0, ServiceOrder::count());
    }

    public function test_PM03_catatan_per_menu_tampil_di_halaman_status(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        $this->pesan([['id' => $nasi->id, 'qty' => 1, 'note' => 'tanpa bawang']])->assertOk();

        $this->get(ServiceOrder::sole()->urlStatus())
            ->assertOk()
            ->assertSee('Catatan: tanpa bawang')
            ->assertSee('Catatan untuk restoran:')
            ->assertSee('Meja dekat jendela');
    }

    public function test_PM04_belum_dibayar_menampilkan_pilihan_metode_tanpa_midtrans_snap(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        $this->pesan([['id' => $nasi->id, 'qty' => 1]])->assertOk();
        // Tamu menekan Ganti metode: daftar metode tetap tersedia di halaman pesanan
        Cache::forget(PembayaranMidtrans::kunci(ServiceOrder::sole(), 'tagihan'));

        $this->get(ServiceOrder::sole()->urlStatus())
            ->assertOk()
            ->assertSee('Selesaikan pembayaran')
            ->assertSee('Scan kode QR')
            ->assertSee('Transfer virtual account')
            ->assertSee('BCA Virtual Account')
            ->assertSee('Rp35.000')
            ->assertDontSee('snap.js', false);
    }

    public function test_PM10_metode_dipilih_di_keranjang_lalu_halaman_pesanan_menampilkan_nomor_bayar(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);

        $this->pesan([['id' => $nasi->id, 'qty' => 1]], null)
            ->assertUnprocessable()->assertJsonValidationErrors(['metode' => 'Pilih metode pembayaran.']);
        $this->assertSame(0, ServiceOrder::count());

        $this->pesan([['id' => $nasi->id, 'qty' => 1]], 'mandiri')->assertOk();
        $this->assertSame(['mandiri'], app(PembayaranMidtrans::class)->dibuat);

        $this->get(ServiceOrder::sole()->urlStatus())
            ->assertOk()
            ->assertSee('8277000012345678')
            ->assertSee('70012');
    }

    public function test_PM11_nomor_milik_akun_lain_ditolak_saat_masuk(): void
    {
        $this->siapkanDataContoh();
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        // Nomor 081234567890 sudah menjadi pelanggan milik akun customer
        $this->actingAs($this->akun('customer'))->pesan([['id' => $nasi->id, 'qty' => 1]])->assertOk();

        $this->actingAs($this->akun('fo'))->pesan([['id' => $nasi->id, 'qty' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Nomor ini terdaftar di akun lain. Gunakan nomor WhatsApp Anda sendiri.']);
        $this->assertSame(1, ServiceOrder::count());
    }

    public function test_PM12_status_midtrans_dengan_jumlah_berbeda_diabaikan(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        $this->pesan([['id' => $nasi->id, 'qty' => 1]])->assertOk();
        $order = ServiceOrder::sole();
        $id = 'WT-'.str_replace('/', '', substr($order->order_code, 0, 11)).'-'.substr($order->order_code, -4).'-ASNG';

        // Pesanan lain bernomor sama (misalnya dari database salinan) dengan jumlah berbeda
        $this->assertNull((new PembayaranMidtrans)->terapkan(['order_id' => $id, 'transaction_status' => 'settlement', 'gross_amount' => '14000.00', 'payment_type' => 'qris']));
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertFalse($order->fresh()->sudahDibayar());

        (new PembayaranMidtrans)->terapkan(['order_id' => $id, 'transaction_status' => 'settlement', 'gross_amount' => '35000.00', 'payment_type' => 'qris']);
        $this->assertTrue($order->fresh()->sudahDibayar());
    }

    public function test_PM08_memilih_metode_membuat_tagihan_dan_status_bisa_dicek(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        $this->pesan([['id' => $nasi->id, 'qty' => 2]])->assertOk();
        $order = ServiceOrder::sole();
        $tautan = ['kode' => $order->kodeUrl(), 't' => $order->access_token];

        $this->postJson(route('pesanan.bayar', $tautan), ['metode' => 'bank-lain'])
            ->assertUnprocessable()->assertJsonValidationErrors(['metode' => 'Pilih metode pembayaran.']);

        $this->postJson(route('pesanan.bayar', $tautan), ['metode' => 'mandiri'])
            ->assertOk()
            ->assertJsonPath('tagihan.nomor', '8277000012345678')
            ->assertJsonPath('tagihan.kode_perusahaan', '70012')
            ->assertJsonPath('tagihan.jumlah', 70000);

        $this->getJson(route('pesanan.cek', $tautan))->assertOk()->assertExactJson(['dibayar' => false, 'batal' => false]);
        $order->invoice->update(['status' => 'paid']);
        $this->getJson(route('pesanan.cek', $tautan))->assertOk()->assertJsonPath('dibayar', true);
        $this->postJson(route('pesanan.bayar', $tautan), ['metode' => 'bca'])->assertStatus(409);

        // Tautan tanpa token akses tidak bisa dipakai
        $this->getJson(route('pesanan.cek', ['kode' => $order->kodeUrl()]))->assertNotFound();
    }

    public function test_PM09_tagihan_lama_yang_diakhiri_karena_ganti_metode_tidak_membatalkan_pesanan(): void
    {
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        $this->pesan([['id' => $nasi->id, 'qty' => 1]])->assertOk();
        $order = ServiceOrder::sole();
        $kode = str_replace('/', '', substr($order->order_code, 0, 11)).'-'.substr($order->order_code, -4);
        $status = fn ($id) => ['order_id' => "WT-{$kode}-{$id}", 'transaction_status' => 'expire', 'gross_amount' => '35000.00', 'payment_type' => 'bank_transfer'];

        // Masih dalam batas waktu: hanya percobaan itu yang berakhir
        (new PembayaranMidtrans)->terapkan($status('AAAA'));
        $this->assertSame('pending', $order->fresh()->status);

        // Batas waktu lewat: pesanan dibatalkan
        $order->update(['order_date' => now()->subMinutes(61)]);
        (new PembayaranMidtrans)->terapkan($status('BBBB'));
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_PM05_pesanan_saat_masuk_tertaut_ke_akun_dan_tampil_di_pesanan_saya(): void
    {
        $this->siapkanDataContoh();
        $akun = $this->akun('customer');
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);

        $this->actingAs($akun)->pesan([['id' => $nasi->id, 'qty' => 2]])->assertOk();

        $this->assertSame($akun->id, ServiceOrder::sole()->customer->user_id);
        $this->actingAs($akun)->get(route('pesanan'))
            ->assertOk()
            ->assertSee(ServiceOrder::sole()->order_code)
            ->assertSee('Menunggu pembayaran')
            ->assertSee('Bayar sekarang')
            ->assertDontSee('Lihat detail')
            ->assertSee('Nasi Goreng Kampung ×2');
    }

    public function test_PM06_pesanan_saya_menampilkan_semua_keadaan_dan_penyaring(): void
    {
        $this->siapkanDataContoh();
        $akun = $this->akun('customer');
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        foreach (range(1, 4) as $i) {
            $this->actingAs($akun)->pesan([['id' => $nasi->id, 'qty' => $i]])->assertOk();
        }
        [$lewat, $dibayar, $selesai] = ServiceOrder::orderBy('id')->take(3)->get();
        // Lewat batas bayar: dibatalkan saat daftar dibuka
        $lewat->update(['order_date' => now()->subHours(3)]);
        foreach ([$dibayar, $selesai] as $o) {
            $o->invoice->update(['status' => 'paid']);
        }
        $selesai->update(['status' => 'delivered']);
        $dibayar->update(['status' => 'in_progress']);

        $this->actingAs($akun)->get(route('pesanan'))
            ->assertOk()
            ->assertViewHas('jumlah', fn ($j) => $j->all() == ['batal' => 1, 'diproses' => 1, 'selesai' => 1, 'belum-bayar' => 1, 'semua' => 4])
            ->assertSee('Dibatalkan')
            ->assertSee('Sedang disiapkan')
            ->assertSee('Selesai')
            ->assertSee('Menunggu pembayaran');
        $this->assertSame('cancelled', $lewat->fresh()->status);

        // Penyaring bekerja di peramban: setiap kartu membawa keadaannya, tanpa ?status= di alamat
        $this->actingAs($akun)->get(route('pesanan'))
            ->assertSee('data-keadaan="batal"', false)
            ->assertDontSee('?status=', false);
    }

    public function test_PM07_pesanan_akun_lain_tidak_tampil(): void
    {
        $this->siapkanDataContoh();
        $nasi = $this->menu('F-NGK', 'Nasi Goreng Kampung', 35000);
        $this->actingAs($this->akun('customer'))->pesan([['id' => $nasi->id, 'qty' => 1]])->assertOk();

        DB::table('users')->where('id', $this->akun('fo')->id)->update(['phone' => '081299990000']);
        $this->actingAs($this->akun('fo'))->get(route('pesanan'))
            ->assertOk()
            ->assertViewHas('jumlah', fn ($j) => $j['semua'] === 0)
            ->assertSee('Belum ada pesanan');
    }
}
