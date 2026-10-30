<?php

namespace Tests\Feature;

use App\Models\HallBooking;
use App\Models\Reservation;
use App\Models\ServiceOrder;
use App\Support\PesananPerangkat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Cek Pesanan: tab "Pesanan terakhir" dari cookie terenkripsi wt_pesanan, dan tab "Cari dengan kode" untuk semua jenis pesanan. */
class CekPesananPerangkatTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->siapkanDataContoh();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservasi(string $kode, string $token): Reservation
    {
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => 'Dian Permata', 'phone' => '0812'.random_int(10000000, 99999999)]);
        $id = DB::table('reservations')->insertGetId(['reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'online',
            'status' => 'confirmed', 'access_token' => $token, 'created_at' => '2026-10-05 09:00:00']);
        DB::table('reservation_rooms')->insert(['reservation_id' => $id, 'room_type_id' => $this->kamar('201')->room_type_id, 'room_id' => $this->kamar('201')->id,
            'check_in_date' => '2026-10-10', 'check_out_date' => '2026-10-12', 'price_per_night' => 460000, 'status' => 'booked']);

        return Reservation::findOrFail($id);
    }

    /** Nilai cookie wt_pesanan (sudah didekripsi) dari respons. */
    private function cookieDari($respons): string
    {
        return (string) $respons->getCookie(PesananPerangkat::COOKIE)?->getValue();
    }

    private function cek(string $cookie)
    {
        return $this->withCookie(PesananPerangkat::COOKIE, $cookie)->get(route('cek'));
    }

    public function test_membuka_status_reservasi_tanpa_akun_mencatatnya_di_perangkat_ini(): void
    {
        $r = $this->reservasi('RSV-0901', 'LK7Q2M9X');

        // Sebelum ada pesanan: tab Cari dibuka, tab perangkat menampilkan keadaan kosong
        $this->get(route('cek'))->assertOk()->assertSee('Pesanan terakhir')->assertDontSee('Di perangkat ini')->assertSee('Cari dengan kode')
            ->assertSee('Belum ada pesanan terakhir')->assertSee('x-data="{ tab: \'cari\' }"', false);

        $respons = $this->get($r->urlStatus())->assertOk()->assertCookie(PesananPerangkat::COOKIE);
        $cookie = $this->cookieDari($respons);
        $this->assertSame(['kamar|RSV-0901|LK7Q2M9X'], json_decode($cookie, true));

        $this->cek($cookie)->assertOk()
            ->assertSee('x-data="{ tab: \'perangkat\' }"', false)
            ->assertSee('RSV-0901')->assertSee('Terkonfirmasi')->assertSee('Lihat detail')->assertSee($r->urlStatus(), false)
            ->assertDontSee('Hapus RSV-0901 dari daftar pesanan terakhir')->assertDontSee('Hapus semua dari daftar')
            ->assertSee('Lihat pesanan yang baru Anda buat atau buka')->assertDontSee('Daftar ini hanya tersimpan di peramban ini.');

        // Token akses berubah (misalnya dibuat ulang resepsionis): tidak tampil lagi
        $r->update(['access_token' => 'ZZZZ9999']);
        $this->cek($cookie)->assertOk()->assertDontSee('RSV-0901')->assertSee('Belum ada pesanan terakhir');
    }

    public function test_cookie_palsu_atau_rusak_diabaikan_dan_pengguna_masuk_tidak_dicatat(): void
    {
        $r = $this->reservasi('RSV-0902', 'AB12CD34');

        // Token salah di cookie: tidak tampil
        $this->cek(json_encode(['kamar|RSV-0902|SALAH000']))->assertOk()->assertDontSee('RSV-0902');
        $this->cek('bukan json')->assertOk()->assertSee('Belum ada pesanan terakhir');

        // Sudah masuk: status dibuka lewat akun pemilik, tidak dicatat di perangkat
        $akun = $this->akun('customer');
        $r->customer->update(['user_id' => $akun->id]);
        $this->actingAs($akun)->get(route('reservasi.status', ['kode' => 'RSV-0902']))->assertOk()->assertCookieMissing(PesananPerangkat::COOKIE);
    }

    public function test_aula_dan_beberapa_pesanan_terbaru_dulu_maksimal_dua_puluh(): void
    {
        $a = $this->reservasi('RSV-0903', 'AAAA1111');
        $b = $this->reservasi('RSV-0904', 'BBBB2222');
        DB::table('halls')->insertOrIgnore(['id' => 1, 'name' => 'Aula Utama', 'capacity' => 100, 'is_active' => 1]);
        $aula = HallBooking::create(['booking_code' => 'AUL-0007', 'hall_id' => 1, 'customer_id' => $a->customer_id, 'start_at' => '2026-10-20 08:00:00',
            'end_at' => '2026-10-20 12:00:00', 'participant_count' => 40, 'rental_price' => 0, 'purpose' => 'Rapat kerja', 'status' => 'pending']);

        PesananPerangkat::reservasi($a);
        PesananPerangkat::aula($aula);
        PesananPerangkat::reservasi($b);
        PesananPerangkat::reservasi($a); // dibuka lagi: naik ke atas, tidak dobel
        $cookie = Cookie::queued(PesananPerangkat::COOKIE)->getValue();
        $this->assertSame(['kamar|RSV-0903|AAAA1111', 'kamar|RSV-0904|BBBB2222', 'aula|AUL-0007|'], json_decode($cookie, true));

        $this->cek($cookie)->assertOk()->assertSee('RSV-0903')->assertSee('RSV-0904')
            ->assertSee('AUL-0007')->assertSee('Aula Utama · Rapat kerja')->assertSee('Menunggu konfirmasi');

        // Hanya 20 pesanan terakhir yang diingat
        foreach (range(1, 25) as $n) {
            PesananPerangkat::aula(new HallBooking(['booking_code' => 'AUL-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)]));
        }
        $isi = json_decode(Cookie::queued(PesananPerangkat::COOKIE)->getValue(), true);
        $this->assertCount(PesananPerangkat::MAKS, $isi);
        $this->assertSame('aula|AUL-0025|', $isi[0]);
    }

    public function test_hapus_satu_dan_hapus_semua_dari_perangkat_ini(): void
    {
        $this->reservasi('RSV-0905', 'CCCC3333');
        $this->reservasi('RSV-0906', 'DDDD4444');
        $cookie = json_encode(['kamar|RSV-0905|CCCC3333', 'kamar|RSV-0906|DDDD4444']);

        $respons = $this->withCookie(PesananPerangkat::COOKIE, $cookie)->post(route('cek.lupakan'), ['kunci' => 'kamar|RSV-0905'])
            ->assertRedirect(route('cek'))->assertSessionHas('status', 'Pesanan dihapus dari daftar pesanan terakhir.');
        $sisa = $this->cookieDari($respons);
        $this->assertSame(['kamar|RSV-0906|DDDD4444'], json_decode($sisa, true));
        $this->cek($sisa)->assertDontSee('RSV-0905')->assertSee('RSV-0906');

        $respons = $this->withCookie(PesananPerangkat::COOKIE, $sisa)->post(route('cek.lupakan'))
            ->assertRedirect(route('cek'))->assertSessionHas('status', 'Daftar pesanan terakhir sudah dikosongkan.');
        $this->assertSame('', $this->cookieDari($respons));
        // Pesanannya sendiri tidak berubah
        $this->assertSame(2, Reservation::whereIn('reservation_code', ['RSV-0905', 'RSV-0906'])->where('status', 'confirmed')->count());
    }

    public function test_pencarian_gagal_tetap_di_tab_cari(): void
    {
        $this->reservasi('RSV-0907', 'EEEE5555');
        $this->withCookie(PesananPerangkat::COOKIE, json_encode(['kamar|RSV-0907|EEEE5555']))
            ->from(route('cek'))->post(route('cek.cari'), ['kode' => 'RSV-0907', 'token' => 'SALAH999'])->assertRedirect(route('cek'));
        $this->withCookie(PesananPerangkat::COOKIE, json_encode(['kamar|RSV-0907|EEEE5555']))->get(route('cek'))
            ->assertOk()->assertSee('x-data="{ tab: \'cari\' }"', false)->assertSee('Kode pesanan atau nomor WhatsApp tidak cocok.');
    }

    public function test_cari_semua_jenis_pesanan_dengan_kode_dan_nomor_whatsapp(): void
    {
        // Banyak percobaan dalam satu tes; batas 10 per menit diuji terpisah
        $this->withoutMiddleware(ThrottleRequests::class);
        $r = $this->reservasi('RSV-0908', 'FFFF6666');
        DB::table('customers')->where('id', $r->customer_id)->update(['phone' => '081234567890']);
        $order = ServiceOrder::create(['order_code' => 'ORD/2026/10/0084', 'customer_id' => $r->customer_id, 'order_type' => 'outside_guest',
            'serving_type' => 'take_away', 'payment_mode' => 'pay_now', 'order_date' => '2026-10-06 09:00:00', 'status' => 'pending', 'access_token' => str_repeat('a', 40)]);
        DB::table('halls')->insertOrIgnore(['id' => 1, 'name' => 'Aula Utama', 'capacity' => 100, 'is_active' => 1]);
        HallBooking::create(['booking_code' => 'HB-0007', 'hall_id' => 1, 'customer_id' => $r->customer_id, 'start_at' => '2026-10-20 08:00:00',
            'end_at' => '2026-10-20 12:00:00', 'rental_price' => 0, 'purpose' => 'Rapat kerja', 'status' => 'pending']);
        $cari = fn (string $kode, string $kunci) => $this->from(route('cek'))->post(route('cek.cari'), ['kode' => $kode, 'kunci' => $kunci]);

        // Hasilnya halaman lihat saja (bukan tautan bertoken), lihat CekPesananAmanTest
        // Reservasi: nomor WhatsApp (format apa pun) atau kode kamar
        $cari('rsv0908', '+62 812-3456-7890')->assertRedirect(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0908']));
        $cari('RSV-0908', 'ffff-6666')->assertRedirect(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0908']));
        // Makanan/laundry: ORD dengan garis miring, tanda hubung, atau tanpa pemisah
        foreach (['ORD/2026/10/0084', 'ord-2026-10-0084', 'ORD2026100084'] as $kode) {
            $cari($kode, '081234567890')->assertRedirect(route('cek.lihat', ['jenis' => 'makanan', 'kode' => 'ORD-2026-10-0084']));
        }
        // Aula belum punya halaman status: dimasukkan ke daftar pesanan terakhir
        $respons = $cari('hb-0007', '6281234567890')->assertRedirect(route('cek'))
            ->assertSessionHas('status', 'Pengajuan aula HB-0007 ditambahkan ke daftar pesanan terakhir.');
        $this->assertSame(['aula|HB-0007|'], json_decode($this->cookieDari($respons), true));

        // Nomor salah, kode kamar untuk pesanan non-kamar, atau kode tak dikenal: pesan yang sama tanpa membocorkan mana yang salah
        foreach ([['RSV-0908', '089999999999'], ['ORD/2026/10/0084', 'FFFF6666'], ['HB-0007', '0812'], ['XYZ-1', '081234567890']] as [$kode, $kunci]) {
            $cari($kode, $kunci)->assertRedirect(route('cek'))->assertSessionHasErrors(['kode' => 'Kode pesanan atau nomor WhatsApp tidak cocok. Periksa lagi pesan konfirmasi Anda.']);
        }
        $this->from(route('cek'))->post(route('cek.cari'), [])->assertSessionHasErrors(['kode' => 'Isi kode pesanan.', 'kunci' => 'Isi nomor WhatsApp pemesan.']);

        $this->get(route('cek'))->assertSee('Berlaku untuk reservasi kamar, pesanan makanan, laundry, dan sewa aula.')
            ->assertSee('Nomor WhatsApp pemesan')->assertSee('Cari pesanan');
    }

    public function test_pencarian_dibatasi_sepuluh_kali_per_menit(): void
    {
        foreach (range(1, 10) as $n) {
            $this->from(route('cek'))->post(route('cek.cari'), ['kode' => 'RSV-0999', 'kunci' => '081234567890'])->assertRedirect(route('cek'));
        }
        $this->from(route('cek'))->post(route('cek.cari'), ['kode' => 'RSV-0999', 'kunci' => '081234567890'])->assertStatus(429);
    }
}
