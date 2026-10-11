<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Livewire\Staf\Layanan\PapanPesanan;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Tamu\PesanKamar;
use App\Livewire\Tamu\PesanLaundry;
use App\Livewire\Tamu\SewaAula;
use App\Models\Customer;
use App\Models\Hall;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Layanan\PesananMakananService;
use App\Services\Reservasi\KodeKamar;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Kode kamar untuk Resto dan Laundry, saran masuk di setiap pemesanan, ajakan membuat akun setelah memesan,
 * serta salin/bagikan kode kamar. RSV-0101 (Budi Santoso, akun budi@contoh.id) menginap di kamar 205 dan 206
 * dengan kode LK7Q-2M9X; RSV-0102 (tamu tanpa akun) di kamar 210 dengan kode CCCC-4444.
 */
class KodeKamarTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const BUDI = 12;

    private const SARAN = 'Sudah punya akun?';

    private const AJAKAN = 'Ingin memantau pesanan ini dari akun?';

    private Service $nasi;

    private Reservation $budi;

    private Reservation $lain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        config(['services.fonnte.token' => 'uji-fonnte', 'services.midtrans.server_key' => 'uji-server-key', 'services.midtrans.client_key' => 'uji-client-key']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        $this->app->instance(PembayaranMidtrans::class, new class extends PembayaranMidtrans
        {
            public array $dibuat = [];

            public function buatTagihan(BayarOnline $order, string $metode): array
            {
                $this->dibuat[] = $metode;
                $tagihan = ['metode' => $metode, 'order_id' => 'WT-UJI', 'jumlah' => $order->jumlahBayar(), 'nomor' => '8277000012345678', 'kode_perusahaan' => null, 'qr' => null];
                Cache::put(PembayaranMidtrans::kunci($order, 'tagihan'), $tagihan);

                return $tagihan;
            }

            public function sinkronkan(BayarOnline $order): void {}

            public function akhiriTagihan(BayarOnline $x): void {}
        });

        $this->nasi = Service::create(['code' => 'F-NG', 'name' => 'Nasi Goreng', 'category' => 'fnb', 'menu_group' => 'Makanan Utama',
            'department' => 'fnb', 'unit' => 'porsi', 'price' => 35000, 'is_active' => true]);
        Service::create(['code' => 'L-KG', 'name' => 'Laundry Kiloan', 'category' => 'laundry', 'menu_group' => 'Laundry',
            'department' => 'housekeeping', 'unit' => 'kg', 'price' => 15000, 'is_active' => true]);
        $this->budi = $this->reservasi('RSV-0101', 'LK7Q2M9X', 'checked_in', ['205' => 'checked_in', '206' => 'checked_in', '207' => 'moved'], self::BUDI);
        $this->lain = $this->reservasi('RSV-0102', 'CCCC4444', 'checked_in', ['210' => 'checked_in']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservasi(string $kode, string $token, string $status, array $kamar, ?int $akun = null): Reservation
    {
        $tamu = $akun
            ? Customer::firstOrCreate(['user_id' => $akun], ['full_name' => 'Budi Santoso', 'phone' => '081355501010'])
            : Customer::create(['full_name' => 'Tamu '.$kode, 'phone' => '0813'.random_int(10000000, 99999999)]);
        $id = DB::table('reservations')->insertGetId([
            'reservation_code' => $kode, 'customer_id' => $tamu->id, 'booking_channel' => 'walk_in', 'status' => $status, 'access_token' => $token,
        ]);
        foreach ($kamar as $nomor => $st) {
            ReservationRoom::create([
                'reservation_id' => $id, 'room_type_id' => $this->kamar((string) $nomor)->room_type_id, 'room_id' => $this->kamar((string) $nomor)->id,
                'check_in_date' => '2026-10-05', 'check_out_date' => '2026-10-14', 'price_per_night' => 460000, 'status' => $st,
            ]);
        }

        return Reservation::with('rooms', 'customer')->find($id);
    }

    private function baris(Reservation $r, string $nomor): ReservationRoom
    {
        return $r->rooms->firstWhere('room_id', $this->kamar($nomor)->id);
    }

    private function cocokkan(string $kode)
    {
        return $this->postJson(route('resto.kode-kamar'), ['kode_kamar' => $kode]);
    }

    private function pesan(array $isi)
    {
        return $this->postJson(route('resto.pesan'), $isi + ['items' => [['id' => $this->nasi->id, 'qty' => 2, 'note' => 'tidak pedas']], 'note' => null]);
    }

    private function pesanKamar(ReservationRoom $baris, string $tagih = 'kamar', array $tambahan = [])
    {
        return $this->pesan($tambahan + ['reservation_room_id' => $baris->id, 'tagih' => $tagih,
            'serving_type' => 'room_service', 'metode' => $tagih === 'sekarang' ? 'bca' : null]);
    }

    private function budiUser(): User
    {
        return User::find(self::BUDI);
    }

    /* ---------- 1. Kode kamar ---------- */

    public function test_01_cocokkan_kode_kamar(): void
    {
        foreach (['lk7q-2m9x', 'LK7Q2M9X', ' lk7q 2m9x '] as $kode) {
            $this->cocokkan($kode)->assertOk()->assertExactJson(['kamar' => [
                ['id' => $this->baris($this->budi, '205')->id, 'nomor' => '205', 'keluar' => '14 Okt'],
                ['id' => $this->baris($this->budi, '206')->id, 'nomor' => '206', 'keluar' => '14 Okt'],
            ]])->assertJsonMissing(['nama' => 'Budi Santoso']);
        }
        // Session menyimpan id reservasi
        $this->assertSame($this->budi->id, session(KodeKamar::SESI));

        $this->cocokkan('ZZZZ-9999')->assertUnprocessable()->assertJsonPath('pesan', 'Kode kamar tidak cocok.');
        $this->reservasi('RSV-0103', 'AAAA2222', 'confirmed', ['208' => 'booked']);
        $this->reservasi('RSV-0104', 'BBBB3333', 'checked_out', ['209' => 'checked_out']);
        foreach (['aaaa-2222', 'BBBB3333'] as $kode) {
            $this->cocokkan($kode)->assertUnprocessable()->assertJsonPath('pesan', 'Kode ini hanya berlaku selama Anda menginap.');
        }

        // Tautan /resto?t=: langsung dicocokkan, pilihan tamu menginap aktif
        $this->get(route('menu', ['t' => 'lk7q2m9x']))->assertOk()->assertViewHas('jenisAwal', 'menginap')
            ->assertViewHas('kamarTamu', fn ($k) => $k->pluck('nomor')->all() === ['205', '206'])->assertSee('Kode kamar cocok · Kamar 205, 206');
        $this->flushSession();
        $this->get(route('menu', ['t' => 'SALAH123']))->assertOk()->assertViewHas('kodeGalat', 'Kode kamar tidak cocok.')
            ->assertViewHas('kamarTamu', fn ($k) => $k->isEmpty());
        $this->get(route('menu'))->assertOk()->assertViewHas('jenisAwal', 'luar');
    }

    public function test_01b_percobaan_kesebelas_dalam_semenit_ditolak(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->cocokkan('ZZZZ-9999')->assertUnprocessable();
        }
        $this->cocokkan('LK7Q-2M9X')->assertTooManyRequests();
        $this->assertMatchesRegularExpression('/^Terlalu banyak percobaan\. Coba lagi dalam \d+ detik\.$/', $this->cocokkan('LK7Q-2M9X')->json('pesan'));
        // Batas yang sama berlaku untuk Laundry
        Livewire::test(PesanLaundry::class)->set('kodeKamar', 'LK7Q-2M9X')->call('cariKamar')->assertHasErrors('kodeKamar')->assertSee('Terlalu banyak percobaan.');
    }

    /* ---------- 2. Resto ---------- */

    public function test_02_tagihan_kamar_langsung_masuk_dapur(): void
    {
        $this->cocokkan('lk7q-2m9x')->assertOk();
        $this->pesanKamar($this->baris($this->budi, '205'))->assertOk()->assertJsonStructure(['status_url']);

        $o = ServiceOrder::sole();
        $this->assertSame(['in_house', 'charge_to_room', null, $this->baris($this->budi, '205')->id, $this->budi->customer_id, 'room_service'],
            [$o->order_type, $o->payment_mode, $o->invoice_id, $o->reservation_room_id, $o->customer_id, $o->serving_type]);
        $this->assertSame([], app(PembayaranMidtrans::class)->dibuat);
        $this->assertTrue(PesananMakananService::diDapur()->whereKey($o->id)->exists());
        $papan = Livewire::actingAs($this->akun('fnb'))->test(PapanPesanan::class);
        $this->assertSame([$o->id], $papan->viewData('kolom')['pending']->pluck('id')->all());
        $papan->assertSee('Kamar 205 · Antar ke kamar')->assertSee('Tagihan kamar');

        // Halaman status: kamar, cara bayar, dan pemantauan otomatis
        auth()->logout();
        $this->get($o->urlStatus())->assertOk()->assertSee('Kamar 205')->assertSee('Masuk tagihan kamar, dibayar saat check-out')
            ->assertSee('Pesanan diterima')->assertSee('Muat ulang status')->assertSee('Rp70.000')->assertDontSee('Menunggu pembayaran');

        // Tampil di Pesanan Saya pemilik reservasi
        $this->actingAs($this->budiUser())->get(route('pesanan'))->assertOk()->assertSee($o->order_code)->assertSee('Ditagihkan ke kamar');
    }

    public function test_02b_bayar_sekarang_menunggu_lunas(): void
    {
        $this->cocokkan('LK7Q-2M9X')->assertOk();
        $this->pesanKamar($this->baris($this->budi, '206'), 'sekarang', ['serving_type' => 'dine_in'])->assertOk();

        $o = ServiceOrder::with('invoice')->sole();
        $this->assertSame(['in_house', 'pay_now', 'issued', 70000.0], [$o->order_type, $o->payment_mode, $o->invoice->status, (float) $o->invoice->total_amount]);
        $this->assertSame(['bca'], app(PembayaranMidtrans::class)->dibuat);
        $this->assertFalse(PesananMakananService::diDapur()->whereKey($o->id)->exists());
        $this->get($o->urlStatus())->assertOk()->assertSee('Kamar 206');

        $this->pesanKamar($this->baris($this->budi, '206'), 'sekarang', ['metode' => null])->assertUnprocessable()->assertJsonValidationErrors('metode');
    }

    public function test_02c_kamar_reservasi_lain_ditolak(): void
    {
        $tolak = fn ($respon) => $respon->assertUnprocessable()->assertJsonPath('pesan', 'Kamar ini tidak bisa dipakai untuk memesan. Cocokkan ulang kode kamar.');

        // Tanpa kode kamar di session
        $tolak($this->pesanKamar($this->baris($this->budi, '205')));
        // Session milik RSV-0101, tetapi reservation_room_id milik RSV-0102
        $this->cocokkan('LK7Q-2M9X')->assertOk();
        $tolak($this->pesanKamar($this->baris($this->lain, '210')));
        // Kamar yang sudah pindah, dan reservasi yang sudah check-out walaupun session masih menyimpannya
        $tolak($this->pesanKamar($this->baris($this->budi, '207')));
        $this->budi->update(['status' => 'checked_out']);
        $tolak($this->pesanKamar($this->baris($this->budi, '205')));
        $this->assertSame(0, ServiceOrder::count());
    }

    public function test_02d_akun_pemilik_tanpa_kode_akun_lain_ditolak_dan_tamu_luar_tanpa_antar_kamar(): void
    {
        $this->actingAs($this->budiUser())->get(route('menu'))->assertOk()
            ->assertViewHas('kamarTamu', fn ($k) => $k->pluck('nomor')->all() === ['205', '206']);
        $this->actingAs($this->budiUser())->pesanKamar($this->baris($this->budi, '205'))->assertOk();
        $this->assertSame('in_house', ServiceOrder::sole()->order_type);

        $this->actingAs($this->akun('customer'))->get(route('menu'))->assertViewHas('kamarTamu', fn ($k) => $k->isEmpty());
        $this->actingAs($this->akun('customer'))->pesanKamar($this->baris($this->budi, '205'))->assertUnprocessable();

        // Tamu luar: antar ke kamar tetap tidak tersedia
        $this->pesan(['name' => 'Dian Permata', 'phone' => '081234567890', 'serving_type' => 'room_service', 'metode' => 'bca'])
            ->assertUnprocessable()->assertJsonValidationErrors(['serving_type' => 'Antar ke kamar hanya untuk tamu yang sedang menginap.']);
        $this->assertSame(1, ServiceOrder::count());
    }

    /* ---------- 3. Laundry ---------- */

    public function test_03_laundry_dengan_kode_kamar_session_resto_dan_tautan_lama(): void
    {
        Livewire::test(PesanLaundry::class)->assertSee('Cari kamar')->assertDontSee('Kode reservasi')
            ->set('kodeKamar', 'lk7q-2m9x')->call('cariKamar')->assertHasNoErrors()
            ->assertSee('Kamar 205')->assertSee('Kamar 206')->assertDontSee('Budi Santoso');
        $this->flushSession();
        Livewire::test(PesanLaundry::class)->set('kodeKamar', 'SALAH123')->call('cariKamar')->assertHasErrors('kodeKamar')->assertSee('Kode kamar tidak cocok.');

        // Kode yang sudah cocok di Resto langsung memunculkan kamar di Laundry
        $this->cocokkan('CCCC-4444')->assertOk();
        Livewire::test(PesanLaundry::class)->assertSee('Kamar 210')->assertSet('kamar', (string) $this->baris($this->lain, '210')->id)
            ->set('tagih', 'kamar')->call('kirim')->assertHasNoErrors();
        $this->assertSame(['in_house', 'charge_to_room'], [ServiceOrder::sole()->order_type, ServiceOrder::sole()->payment_mode]);

        // Tautan lama ?kode=&t= (kode diabaikan) dan tautan baru ?t=
        $this->flushSession();
        Livewire::withQueryParams(['kode' => 'RSV-9999', 't' => 'lk7q2m9x'])->test(PesanLaundry::class)->assertSee('Kamar 205');
        $this->flushSession();
        Livewire::withQueryParams(['t' => 'CCCC4444'])->test(PesanLaundry::class)->assertSee('Kamar 210');
    }

    public function test_03b_laundry_reservasi_check_out_tidak_bisa_dipakai_walau_ada_di_session(): void
    {
        $this->cocokkan('CCCC-4444')->assertOk();
        $halaman = Livewire::test(PesanLaundry::class)->assertSee('Kamar 210');
        $this->lain->update(['status' => 'checked_out']);

        $halaman->set('tagih', 'kamar')->call('kirim')->assertHasErrors('kamar');
        Livewire::test(PesanLaundry::class)->assertDontSee('Kamar 210');
        $this->assertSame(0, ServiceOrder::count());
    }

    /* ---------- 4. Aula ---------- */

    public function test_04_aula_tanpa_kode_kamar_dan_tetap_bisa_diajukan_tanpa_login(): void
    {
        Hall::create(['name' => 'Aula Utama', 'capacity' => 150, 'description' => 'Aula serbaguna', 'is_active' => true]);
        $this->get(route('aula'))->assertOk()->assertDontSee('Kode kamar')->assertDontSee('kodeKamar')
            ->assertDontSee('Tagihkan ke kamar')->assertDontSee('Nomor kamar');

        Livewire::test(SewaAula::class)->set('tanggal', '2026-10-17')->set('jamMulai', '08:00')->set('jamSelesai', '12:00')
            ->set('peserta', '80')->set('keperluan', 'Syukuran keluarga')->set('nama', 'Abdul Rahman')->set('hp', '081244556677')
            ->call('kirim')->assertHasNoErrors()->assertSee('terkirim')
            ->assertSee(self::AJAKAN)->assertSee('081244556677');
    }

    /* ---------- 5. Saran masuk dan ajakan membuat akun ---------- */

    public function test_05_saran_masuk_di_setiap_formulir_pemesanan(): void
    {
        Hall::create(['name' => 'Aula Utama', 'capacity' => 150, 'description' => 'Aula serbaguna', 'is_active' => true]);
        $kamar = fn () => Livewire::withQueryParams(['masuk' => '2026-10-10', 'keluar' => '2026-10-13', 'tamu' => '2-0'])->test(PesanKamar::class, ['kode' => 'dlx']);

        $kamar()->assertSee(self::SARAN);
        $this->get(route('menu'))->assertOk()->assertSee(self::SARAN)->assertSee('data pemesan terisi otomatis dan pesanan tersimpan di Pesanan saya')
            ->assertSee('kamar Anda langsung tampil tanpa kode kamar dan pesanan tersimpan di Pesanan saya');
        Livewire::test(PesanLaundry::class)->assertSee(self::SARAN)->assertSee('kamar Anda langsung tampil tanpa kode kamar')
            ->set('jenis', 'luar')->assertSee(self::SARAN)->assertSee('status laundry tampil di Pesanan saya');
        $this->get(route('aula'))->assertOk()->assertSee(self::SARAN);
        $this->get(route('instansi'))->assertOk()->assertSee(self::SARAN);

        // Sudah masuk: tidak ditawari
        $this->actingAs($this->akun('customer'));
        $kamar()->assertDontSee(self::SARAN);
        $this->get(route('menu'))->assertOk()->assertDontSee(self::SARAN);
        Livewire::test(PesanLaundry::class)->assertDontSee(self::SARAN)->set('jenis', 'luar')->assertDontSee(self::SARAN);
        $this->get(route('aula'))->assertOk()->assertDontSee(self::SARAN);
        $this->get(route('instansi'))->assertOk()->assertDontSee(self::SARAN);
    }

    public function test_05b_ajakan_akun_setelah_memesan_lalu_pesanan_tampil_setelah_daftar(): void
    {
        // Makanan tamu luar
        $this->pesan(['name' => 'Dian Permata', 'phone' => '081234567890', 'serving_type' => 'take_away', 'metode' => 'bca'])->assertOk();
        $makanan = ServiceOrder::sole();
        $this->get($makanan->urlStatus())->assertOk()->assertSee(self::AJAKAN)->assertSee('081234567890')
            ->assertSee("mode: 'daftar'", false)->assertSee('hp: ', false);
        // Laundry tamu luar
        Livewire::test(PesanLaundry::class)->set('jenis', 'luar')->set('nama', 'Dian Permata')->set('hp', '081234567890')->call('kirim')->assertHasNoErrors();
        $laundry = ServiceOrder::latest('id')->first();
        $this->get($laundry->urlStatus())->assertOk()->assertSee(self::AJAKAN)->assertSee('081234567890');
        // Reservasi kamar dan pesanan tamu menginap: nomor dari pemesan reservasi
        $this->get($this->lain->urlStatus())->assertOk()->assertSee(self::AJAKAN)->assertSee($this->lain->customer->phone);
        $this->cocokkan('CCCC-4444')->assertOk();
        $this->pesanKamar($this->baris($this->lain, '210'))->assertOk();
        $this->get(ServiceOrder::latest('id')->first()->urlStatus())->assertOk()->assertSee(self::AJAKAN)->assertSee($this->lain->customer->phone);

        // Setelah daftar dengan nomor itu, pesanan tampil di Pesanan saya; ajakan tidak tampil lagi
        $this->postJson(route('register.store'), ['name' => 'Dian Permata', 'phone' => '081234567890', 'email' => 'dian@contoh.id',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])->assertOk();
        $this->get(route('pesanan'))->assertOk()->assertSee($makanan->order_code)->assertSee($laundry->order_code);
        $this->get($makanan->urlStatus())->assertOk()->assertDontSee(self::AJAKAN);
    }

    /* ---------- 6. Salin dan bagikan ---------- */

    public function test_06_kartu_kode_kamar_di_pesanan_saya_dan_halaman_reservasi(): void
    {
        $this->reservasi('RSV-0105', 'DDDD5555', 'confirmed', ['208' => 'booked'], self::BUDI);
        $this->reservasi('RSV-0106', 'EEEE6666', 'checked_out', ['209' => 'checked_out'], self::BUDI);
        $this->reservasi('RSV-0107', 'FFFF7777', 'cancelled', ['211' => 'cancelled'], self::BUDI);

        $this->actingAs($this->budiUser())->get(route('pesanan'))->assertOk()
            ->assertSeeInOrder(['RSV-0101 · Kamar 205, 206', 'Kode kamar', 'LK7Q-2M9X', 'Salin', 'Bagikan',
                'Bagikan hanya kepada orang yang menginap bersama Anda. Kode ini bisa dipakai untuk menagihkan pesanan ke kamar.', 'Pesan makanan', 'Pesan laundry'])
            ->assertSee('DDDD-5555')->assertDontSee('EEEE-6666')->assertDontSee('FFFF-7777')
            ->assertSee('Kode kamar Wisma Trans: ', false)->assertSee("'Makanan: '", false)->assertSee("' · Laundry: '", false)
            ->assertSee('navigator.share', false)->assertSee('https://wa.me/?text=', false)->assertSee('Kode kamar disalin', false)
            ->assertSee(e(route('menu', ['t' => 'LK7Q2M9X'])), false)->assertSee(e(route('laundry', ['t' => 'LK7Q2M9X'])), false);

        // Halaman reservasi: label Kode kamar, Salin/Bagikan, Pesan makanan dan laundry saat menginap
        auth()->logout();
        $halaman = $this->get($this->budi->urlStatus())->assertOk()->assertSee('Kode kamar')->assertSee('Salin')->assertSee('Bagikan')
            ->assertSee('Pesan makanan')->assertSee('Pesan laundry')->assertDontSee('Token');
        $this->assertSame(1, substr_count($halaman->getContent(), 'LK7Q-2M9X'));
        $this->get(Reservation::where('reservation_code', 'RSV-0105')->first()->urlStatus())->assertOk()->assertSee('DDDD-5555')
            ->assertSee('Bagikan')->assertDontSee('Pesan makanan');
        $this->get(Reservation::where('reservation_code', 'RSV-0106')->first()->urlStatus())->assertOk()->assertDontSee('Bagikan');
    }

    /* ---------- 7. WhatsApp saat check-in ---------- */

    public function test_07_check_in_mengirim_kode_kamar_dan_kedua_tautan(): void
    {
        $fo = $this->akun('fo');
        $r = app(ReservasiService::class)->buatLangsung(Customer::create(['full_name' => 'Hasan Basri', 'phone' => '081322006677']),
            CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-08'), [$this->kamar('104')->id], $fo);
        Carbon::setTestNow('2026-10-06 15:00:00');

        Livewire::actingAs($fo)->test(CheckIn::class)->call('open', $r->id)->set('ktpDitahan', false)->call('simpan')->assertHasNoErrors();
        $r->refresh();
        Http::assertSent(fn (Request $q) => (string) $q['message'] === 'Selamat datang di Wisma Trans. Kamar Anda: 104. '
            .'Pesan makanan dan laundry ke kamar dengan kode kamar '.$r->kodeKamar().'. '
            .'Makanan: '.route('menu', ['t' => $r->access_token]).' · Laundry: '.route('laundry', ['t' => $r->access_token]));
    }

    public function test_07b_gagal_kirim_whatsapp_tidak_membatalkan_check_in(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => false], 500)]);
        $fo = $this->akun('fo');
        $r = app(ReservasiService::class)->buatLangsung(Customer::create(['full_name' => 'Hasan Basri', 'phone' => '081322006677']),
            CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-08'), [$this->kamar('104')->id], $fo);
        Carbon::setTestNow('2026-10-06 15:00:00');

        Livewire::actingAs($fo)->test(CheckIn::class)->call('open', $r->id)->set('ktpDitahan', false)->call('simpan')->assertHasNoErrors();
        $this->assertSame('checked_in', $r->fresh()->status);
    }
}
