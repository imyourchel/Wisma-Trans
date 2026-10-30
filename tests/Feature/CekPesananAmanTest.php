<?php

namespace Tests\Feature;

use App\Models\HallBooking;
use App\Models\Reservation;
use App\Models\ServiceOrder;
use App\Support\IzinCekPesanan;
use App\Support\PesananPerangkat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Keamanan Cek Pesanan: hasil pencarian hanya akses LIHAT SAJA (izin di session, tanpa token), aksi yang mengubah
 * pesanan tetap mensyaratkan token atau akun pemilik, batas percobaan per kode pesanan, dan kirim ulang tautan.
 */
class CekPesananAmanTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const HP = '081234567890';

    // Bukan LK7Q2M9X: itu contoh kode kamar yang memang tertulis di halaman Cek Pesanan
    private const TOKEN = 'QW3R8T5Y';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->siapkanDataContoh();
        $this->withoutVite();
        // Tanpa Midtrans sungguhan dan tanpa WhatsApp sungguhan
        config(['services.midtrans.server_key' => null, 'services.midtrans.client_key' => null, 'services.fonnte.token' => 'uji-fonnte']);
        Http::preventStrayRequests();
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        // Throttle per IP diuji di CekPesananPerangkatTest; di sini yang diuji batas per kode pesanan
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservasi(string $kode = 'RSV-0950', string $status = 'waiting_approval', array $isi = []): Reservation
    {
        // Nomor HP pelanggan unik: reservasi berikutnya memakai pelanggan yang sama
        $pelanggan = DB::table('customers')->where('phone', self::HP)->value('id')
            ?? DB::table('customers')->insertGetId(['full_name' => 'Dian Permata', 'phone' => self::HP]);
        $id = DB::table('reservations')->insertGetId(['reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'online',
            // Token akses unik per reservasi; RSV-0950 memakai TOKEN
            'status' => $status, 'access_token' => $kode === 'RSV-0950' ? self::TOKEN : 'ZX'.substr($kode, -4).'AB', 'created_at' => '2026-10-05 09:00:00', ...$isi]);
        DB::table('reservation_rooms')->insert(['reservation_id' => $id, 'room_type_id' => $this->kamar('201')->room_type_id, 'room_id' => $this->kamar('201')->id,
            'check_in_date' => '2026-10-20', 'check_out_date' => '2026-10-22', 'price_per_night' => 460000, 'status' => 'booked']);

        return Reservation::findOrFail($id);
    }

    private function pesananMakanan(): ServiceOrder
    {
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => 'Rudi Hartono', 'phone' => '081377788899']);
        $layanan = DB::table('services')->insertGetId(['code' => 'TEH09', 'name' => 'Teh Manis', 'category' => 'fnb', 'menu_group' => 'Teh',
            'department' => 'fnb', 'unit' => 'gelas', 'price' => 8000, 'is_active' => 1]);
        $order = ServiceOrder::create(['order_code' => 'ORD/2026/10/0084', 'customer_id' => $pelanggan, 'order_type' => 'outside_guest', 'serving_type' => 'take_away',
            'payment_mode' => 'pay_now', 'order_date' => '2026-10-06 09:00:00', 'status' => 'pending', 'access_token' => str_repeat('k', 40)]);
        DB::table('service_order_items')->insert(['service_order_id' => $order->id, 'service_id' => $layanan, 'quantity' => 1, 'unit_price' => 8000, 'subtotal' => 8000]);

        return $order;
    }

    private function cari(string $kode, string $kunci)
    {
        return $this->from(route('cek'))->post(route('cek.cari'), ['kode' => $kode, 'kunci' => $kunci]);
    }

    /** Respons tidak boleh memuat token dalam bentuk apa pun. */
    private function tanpaToken($respons, string $token): void
    {
        $html = $respons->getContent();
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringNotContainsString(strtolower($token), $html);
        if (strlen($token) === 8) {
            $this->assertStringNotContainsString(substr($token, 0, 4).'-'.substr($token, 4), $html);
        }
        $this->assertStringNotContainsString('t='.$token, $html);
    }

    /* 1 */
    public function test_cek_pesanan_berhasil_ke_halaman_lihat_saja_tanpa_token(): void
    {
        $r = $this->reservasi();
        $lihat = route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']);
        $respons = $this->cari('rsv0950', '+62 812-3456-7890')->assertRedirect($lihat);
        $this->tanpaToken($respons, self::TOKEN);
        $this->assertTrue(IzinCekPesanan::berlaku('reservasi', 'RSV-0950'));

        $halaman = $this->get($lihat)->assertOk()->assertSee('RSV-0950')->assertSee('Mode lihat saja.')
            ->assertSee('Kirim ulang link ke WhatsApp')->assertSee('0812****7890');
        $this->tanpaToken($halaman, self::TOKEN);
        // Daftar perangkat menyimpan pesanan ini sebagai lihat saja (tanpa token) dan menautkannya ke jalur lihat saja
        $cookie = (string) $halaman->getCookie(PesananPerangkat::COOKIE)?->getValue();
        $this->assertSame(['kamar|RSV-0950|'], json_decode($cookie, true));
        $daftar = $this->withCookie(PesananPerangkat::COOKIE, $cookie)->get(route('cek'))->assertOk()
            ->assertSee(route('cek.perangkat.buka', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']), false);
        $this->tanpaToken($daftar, self::TOKEN);

        // Kode kamar sebagai kunci juga hanya memberi akses lihat saja
        $this->cari('RSV-0950', 'qw3r-8t5y')->assertRedirect($lihat);

        // Makanan: kode dengan tanda hubung di alamat, token pesanan tidak ikut
        $order = $this->pesananMakanan();
        $lihatOrd = route('cek.lihat', ['jenis' => 'makanan', 'kode' => 'ORD-2026-10-0084']);
        $this->cari('ord-2026-10-0084', '081377788899')->assertRedirect($lihatOrd);
        $this->tanpaToken($this->get($lihatOrd)->assertOk()->assertSee('ORD/2026/10/0084')->assertSee('Mode lihat saja.'), $order->access_token);
    }

    /* 2 */
    public function test_mode_lihat_menyembunyikan_batal_dan_ubah_dan_izin_session_tidak_cukup_untuk_aksi(): void
    {
        $r = $this->reservasi();
        // Lewat tautan bertoken, tombol batal tampil
        $this->get($r->urlStatus())->assertOk()->assertSee('Ya, batalkan');

        $this->cari('RSV-0950', self::HP);
        $this->get(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']))->assertOk()
            ->assertDontSee('Ya, batalkan')->assertDontSee('Pilih tanggal baru')->assertDontSee(route('reservasi.batal', ['kode' => 'RSV-0950']), false)
            ->assertDontSee('Salin tautan reservasi')->assertDontSee('data-bagikan', false);

        // Izin lihat di session tidak cukup untuk aksi yang mengubah pesanan
        $this->post(route('reservasi.batal', ['kode' => 'RSV-0950']), ['alasan' => 'iseng'])->assertNotFound();
        $this->post(route('reservasi.ubah', ['kode' => 'RSV-0950']), ['masuk' => '2026-10-25', 'keluar' => '2026-10-27'])->assertNotFound();
        $this->get(route('reservasi.status', ['kode' => 'RSV-0950']))->assertNotFound();
        $this->assertSame('waiting_approval', $r->fresh()->status);

        // Bayar dan cek status boleh dengan izin lihat (tanpa token), dan ditolak tanpa izin
        $this->getJson(route('reservasi.cek', ['kode' => 'RSV-0950']))->assertOk()->assertJsonPath('batal', false);
        $this->postJson(route('reservasi.bayar', ['kode' => 'RSV-0950']), ['metode' => 'bca'])->assertStatus(409);
        session()->forget(IzinCekPesanan::SESSION);
        $this->getJson(route('reservasi.cek', ['kode' => 'RSV-0950']))->assertNotFound();
        $this->postJson(route('reservasi.bayar', ['kode' => 'RSV-0950']), ['metode' => 'bca'])->assertNotFound();
    }

    /* 3 */
    public function test_tautan_bertoken_lama_tetap_bisa_membatalkan(): void
    {
        $r = $this->reservasi();
        $this->post(route('reservasi.batal', ['kode' => 'RSV-0950']), ['t' => self::TOKEN, 'alasan' => 'Rencana berubah'])->assertRedirect($r->urlStatus());
        $this->assertSame('cancelled', $r->fresh()->status);
        // Pencarian sebelumnya tidak mengubah apa pun pada akses bertoken (ubah tanggal bertoken diuji di HalamanReservasiTest)
        $this->get($r->urlStatus())->assertOk()->assertSee('Reservasi dibatalkan')->assertDontSee('data-bagikan', false);
    }

    /* 4 */
    public function test_halaman_lihat_tanpa_izin_atau_izin_kedaluwarsa_kembali_ke_cek_pesanan(): void
    {
        $this->reservasi();
        $lihat = route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']);
        $this->get($lihat)->assertRedirect(route('cek'))->assertSessionHas('error', 'Sesi cek pesanan berakhir, cari ulang.');

        $this->cari('RSV-0950', self::HP);
        $this->get($lihat)->assertOk();
        Carbon::setTestNow(now()->addMinutes(IzinCekPesanan::MENIT)->addSecond());
        $this->get($lihat)->assertRedirect(route('cek'))->assertSessionHas('error', 'Sesi cek pesanan berakhir, cari ulang.');

        // Izin untuk satu pesanan tidak berlaku untuk pesanan lain
        $this->reservasi('RSV-0951');
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->cari('RSV-0950', self::HP);
        $this->get(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0951']))->assertRedirect(route('cek'));
    }

    /* 5 */
    public function test_lima_kali_gagal_mengunci_kode_itu_dari_perangkat_mana_pun(): void
    {
        $this->reservasi('RSV-0950');
        $this->reservasi('RSV-0952');
        $salah = 'Kode pesanan atau nomor WhatsApp tidak cocok. Periksa lagi pesan konfirmasi Anda.';
        $terkunci = 'Terlalu banyak percobaan untuk kode ini. Coba lagi dalam 15 menit atau hubungi resepsionis.';

        // Penulisan kode berbeda dihitung sebagai kode yang sama
        foreach (['RSV-0950', 'rsv0950', 'RSV 0950', 'RSV-0950', 'rsv-0950'] as $i => $kode) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])->from(route('cek'))
                ->post(route('cek.cari'), ['kode' => $kode, 'kunci' => '089999999'.$i.'00'])->assertSessionHasErrors(['kode' => $salah]);
        }
        // Ke-6 dengan nomor BENAR pun ditolak, dari IP lain
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->from(route('cek'))
            ->post(route('cek.cari'), ['kode' => 'RSV-0950', 'kunci' => self::HP])->assertSessionHasErrors(['kode' => $terkunci]);
        $this->assertFalse(IzinCekPesanan::berlaku('reservasi', 'RSV-0950'));

        // Kode lain tidak terpengaruh
        $this->cari('RSV-0952', self::HP)->assertRedirect(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0952']));

        // Kode yang tidak ada diperlakukan sama: terkunci setelah 5 kali, dengan pesan yang sama
        foreach (range(1, 5) as $n) {
            $this->cari('RSV-9999', self::HP)->assertSessionHasErrors(['kode' => $salah]);
        }
        $this->cari('RSV-9999', self::HP)->assertSessionHasErrors(['kode' => $terkunci]);

        // Setelah 15 menit kode terbuka lagi; berhasil menghapus hitungan
        Carbon::setTestNow(now()->addMinutes(15)->addSecond());
        $this->cari('RSV-0950', self::HP)->assertRedirect(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']));
        foreach (range(1, 4) as $n) {
            $this->cari('RSV-0950', '0899999999'.$n)->assertSessionHasErrors(['kode' => $salah]);
        }
        $this->cari('RSV-0950', self::HP)->assertRedirect(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']));
    }

    /* 6 */
    public function test_kirim_ulang_tautan_ke_nomor_di_database_sekali_per_sepuluh_menit(): void
    {
        $r = $this->reservasi();
        DB::table('customers')->where('id', $r->customer_id)->update(['phone' => '081255566677']);
        // Nomor diketik dalam format lain; yang dipakai untuk kirim tetap nomor di basis data
        $this->cari('RSV-0950', '+6281255566677')->assertRedirect();
        $lihat = route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']);
        $kirim = route('cek.lihat.kirim', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']);

        $this->from($lihat)->post($kirim)->assertRedirect($lihat)->assertSessionHas('status', 'Tautan dikirim ke WhatsApp 0812****6677.');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.fonnte.com') && $req['target'] === '081255566677'
            && str_contains($req['message'], $r->urlStatus()));
        Http::assertSentCount(1);

        $this->from($lihat)->post($kirim)->assertRedirect($lihat)->assertSessionHas('error', 'Tautan baru saja dikirim. Kirim ulang lagi dalam 10 menit.');
        Http::assertSentCount(1);

        // Setelah 10 menit boleh lagi (izin lihat masih berlaku 30 menit)
        Carbon::setTestNow(now()->addMinutes(10)->addSecond());
        $this->from($lihat)->post($kirim)->assertSessionHas('status');
        Http::assertSentCount(2);

        // Tanpa izin lihat tidak bisa memicu kiriman
        session()->forget(IzinCekPesanan::SESSION);
        $this->post($kirim)->assertRedirect(route('cek'));
        Http::assertSentCount(2);
    }

    /* 7 */
    public function test_pesanan_aula_tetap_bisa_dicari_dan_tampil_tanpa_aksi_ubah(): void
    {
        $r = $this->reservasi();
        DB::table('halls')->insertOrIgnore(['id' => 1, 'name' => 'Aula Utama', 'capacity' => 100, 'is_active' => 1]);
        HallBooking::create(['booking_code' => 'HB-0007', 'hall_id' => 1, 'customer_id' => $r->customer_id, 'start_at' => '2026-10-20 08:00:00',
            'end_at' => '2026-10-20 12:00:00', 'rental_price' => 0, 'purpose' => 'Rapat kerja', 'status' => 'pending']);

        $respons = $this->cari('hb-0007', self::HP)->assertRedirect(route('cek'))
            ->assertSessionHas('status', 'Pengajuan aula HB-0007 ditambahkan ke daftar pesanan terakhir.');
        $cookie = (string) $respons->getCookie(PesananPerangkat::COOKIE)?->getValue();
        $this->withCookie(PesananPerangkat::COOKIE, $cookie)->get(route('cek'))->assertOk()
            ->assertSee('HB-0007')->assertSee('Aula Utama · Rapat kerja')->assertSee('Menunggu konfirmasi')
            ->assertDontSee('Batalkan')->assertDontSee('Lihat detail');
    }

    public function test_daftar_perangkat_membuka_lihat_saja_dan_entri_bertoken_tidak_diturunkan(): void
    {
        $r = $this->reservasi();
        // Cookie dari server: entri lihat saja membuka halaman lihat saja dengan izin baru
        $cookie = json_encode(['kamar|RSV-0950|']);
        $this->withCookie(PesananPerangkat::COOKIE, $cookie)->get(route('cek.perangkat.buka', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']))
            ->assertRedirect(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']));
        $this->assertTrue(IzinCekPesanan::berlaku('reservasi', 'RSV-0950'));

        // Tanpa entri di daftar pesanan terakhir: tidak ada izin (cookie bawaan withCookie dikosongkan dulu)
        session()->forget(IzinCekPesanan::SESSION);
        $this->defaultCookies = [];
        $this->get(route('cek.perangkat.buka', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']))->assertRedirect(route('cek'));
        $this->assertFalse(IzinCekPesanan::berlaku('reservasi', 'RSV-0950'));

        // Pesanan yang sudah tercatat lewat tautan bertoken tetap bertoken setelah dicari lewat Cek Pesanan
        $bertoken = (string) $this->get($r->urlStatus())->getCookie(PesananPerangkat::COOKIE)?->getValue();
        $this->cari('RSV-0950', self::HP);
        $sesudah = (string) $this->withCookie(PesananPerangkat::COOKIE, $bertoken)
            ->get(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0950']))->getCookie(PesananPerangkat::COOKIE)?->getValue();
        $this->assertSame(['kamar|RSV-0950|'.self::TOKEN], json_decode($sesudah, true));
    }
}
