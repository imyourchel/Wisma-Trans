<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Livewire\Staf\Aula\KalenderAula;
use App\Livewire\Staf\Aula\PemesananAula;
use App\Livewire\Staf\Layanan\PesananLaundry;
use App\Livewire\Tamu\PesanLaundry;
use App\Livewire\Tamu\SewaAula;
use App\Models\Customer;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Institution;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Laundry (tamu menginap dan tamu luar), sewa aula (F&B hanya melihat jadwal terkonfirmasi), dan halaman kontak. */
class LaundryAulaTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private Hall $aula;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte', 'services.midtrans.server_key' => 'uji', 'services.midtrans.client_key' => 'uji']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        $this->app->instance(PembayaranMidtrans::class, new class extends PembayaranMidtrans
        {
            public function buatTagihan(BayarOnline $x, string $metode): array
            {
                $t = ['metode' => $metode, 'order_id' => $x->awalanMidtrans().'-UJI1', 'jumlah' => $x->jumlahBayar(), 'nomor' => '8277000099990000', 'kode_perusahaan' => null, 'qr' => null];
                Cache::put(PembayaranMidtrans::kunci($x, 'tagihan'), $t);

                return $t;
            }

            public function sinkronkan(BayarOnline $x): void {}
        });
        Service::create(['code' => 'LDR01', 'name' => 'Laundry Kiloan', 'description' => 'Cuci dan setrika, minimal 2 kg, selesai 1x24 jam',
            'category' => 'laundry', 'menu_group' => 'Laundry', 'department' => 'housekeeping', 'unit' => 'kg', 'price' => 15000, 'is_active' => true]);
        $this->aula = Hall::create(['name' => 'Aula Utama', 'capacity' => 150, 'description' => 'Aula serbaguna', 'is_active' => true]);
    }

    private function tamuMenginap(): Reservation
    {
        $fo = $this->akun('fo');
        $r = app(ReservasiService::class)->buatLangsung(Customer::create(['full_name' => 'Hasan Basri', 'phone' => '081322006677']),
            CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-08'), [$this->kamar('104')->id], $fo);
        Carbon::setTestNow('2026-10-06 15:00:00');
        app(ReservasiService::class)->checkIn($r, [], false, null, $fo);

        return $r->fresh();
    }

    public function test_L01_tamu_luar_bayar_setelah_ditimbang_minimal_dua_kg(): void
    {
        Livewire::test(PesanLaundry::class)
            ->set('jenis', 'luar')->set('nama', 'Lukas Wibowo')->set('hp', '085211008899')->set('perkiraan', '3')
            ->call('kirim')->assertHasNoErrors()->assertRedirect();
        $o = ServiceOrder::sole();
        $this->assertSame(['outside_guest', 'pay_now', 'take_away', 'pending'], [$o->order_type, $o->payment_mode, $o->serving_type, $o->status]);
        $this->get($o->urlStatus())->assertOk()->assertSee('Menunggu ditimbang')->assertSee('Antar cucian ke resepsionis');
        Http::assertSent(fn (Request $q) => str_contains($q['message'], 'Silakan antar cucian ke resepsionis'));

        // Ditimbang 1,4 kg: ditagih minimal 2 kg
        Livewire::actingAs($this->akun('hk'))->test(PesananLaundry::class)
            ->call('open', $o->id)->call('mulai', 'timbang')->set('berat', '1,4')->call('timbang')->assertHasNoErrors();
        $o->refresh();
        $item = $o->items()->sole();
        $this->assertSame(['in_progress', 2.0, 30000.0], [$o->status, (float) $item->quantity, (float) $item->subtotal]);
        $this->assertSame('Berat 1,4 kg (dihitung minimal 2 kg)', $item->note);
        $this->assertSame(['service', 30000.0], [$o->invoice->invoice_type, (float) $o->invoice->total_amount]);
        $this->get($o->urlStatus())->assertOk()->assertSee('Bayar laundry')->assertSee('Rp30.000');

        // Lewat batas bayar pesanan makanan pun laundry tidak dibatalkan otomatis
        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame('in_progress', $o->fresh()->batalkanBilaKedaluwarsa()->status);

        (new PembayaranMidtrans)->terapkan(['order_id' => $o->awalanMidtrans().'-UJI1', 'transaction_status' => 'settlement', 'gross_amount' => '30000.00', 'payment_type' => 'qris']);
        $this->assertTrue($o->fresh()->sudahDibayar());

        Livewire::actingAs($this->akun('hk'))->test(PesananLaundry::class)->call('open', $o->id)->call('siap')->call('serahkan')->assertHasNoErrors();
        $this->assertSame('delivered', $o->fresh()->status);
    }

    public function test_L02_tamu_menginap_lewat_kode_kamar_ditagihkan_ke_kamar(): void
    {
        $r = $this->tamuMenginap();
        $belum = app(ReservasiService::class)->buatLangsung(Customer::create(['full_name' => 'Dian Permata', 'phone' => '081388776655']),
            CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-21'), [$this->kamar('105')->id], $this->akun('fo'));

        Livewire::test(PesanLaundry::class)
            ->set('kodeKamar', $belum->token())->call('cariKamar')
            ->assertHasErrors('kodeKamar')->assertSee('Kode ini hanya berlaku selama Anda menginap.')
            ->set('kodeKamar', strtolower(str_replace('-', '', $r->token())))->call('cariKamar')
            ->assertHasNoErrors()->assertSee('Kamar 104')
            ->set('tagih', 'kamar')->set('catatan', 'Pisahkan pakaian putih')
            ->call('kirim')->assertHasNoErrors();

        $o = ServiceOrder::sole();
        $this->assertSame(['in_house', 'charge_to_room', 'room_service', $r->rooms->first()->id], [$o->order_type, $o->payment_mode, $o->serving_type, $o->reservation_room_id]);
        Http::assertSent(fn (Request $q) => str_contains($q['message'], 'Petugas kami menjemput cucian ke kamar 104'));

        $hk = Livewire::actingAs($this->akun('hk'))->test(PesananLaundry::class)->call('open', $o->id)->call('mulai', 'timbang')->set('berat', '3,5')->call('timbang');
        $o->refresh();
        $this->assertNull($o->invoice_id); // ditagih saat check-out
        $this->assertSame(52500.0, (float) $o->items()->sole()->subtotal);
        $hk->call('siap')->call('serahkan')->assertHasNoErrors();
        $this->assertSame('delivered', $o->fresh()->status);
    }

    public function test_L03_pembayaran_tunai_dan_hak_akses_laundry(): void
    {
        $this->actingAs($this->akun('fnb'))->get(route('staf.laundry'))->assertForbidden();
        $this->actingAs($this->akun('manager'))->get(route('staf.laundry'))->assertOk();

        // Diterima langsung di resepsionis oleh FO, berat sudah ditimbang
        $fo = Livewire::actingAs($this->akun('fo'))->test(PesananLaundry::class)
            ->call('bukaTerima')->set('jenisBaru', 'luar')->set('namaBaru', 'Yudi Kurniawan')->set('hpBaru', '082199887766')->set('beratBaru', '4')
            ->call('terima')->assertHasNoErrors();
        $o = ServiceOrder::sole();
        $this->assertSame(['in_progress', 60000.0], [$o->status, (float) $o->invoice->total_amount]);

        $fo->call('open', $o->id)->call('siap')->call('serahkan')->assertHasErrors('status');
        $fo->call('bayarTunai')->call('serahkan')->assertHasNoErrors();
        $this->assertSame(['delivered', 'paid'], [$o->fresh()->status, $o->fresh()->invoice->status]);
        $this->assertDatabaseHas('payments', ['invoice_id' => $o->invoice_id, 'method' => 'cash', 'received_by' => $this->akun('fo')->id, 'amount' => 60000]);

        Livewire::actingAs($this->akun('manager'))->test(PesananLaundry::class)->call('mulai', 'timbang')->assertForbidden();
    }

    private function ajukan(string $tanggal, string $mulai, string $selesai, string $hp = '081244556677'): mixed
    {
        return Livewire::test(SewaAula::class)
            ->set('tanggal', $tanggal)->set('jamMulai', $mulai)->set('jamSelesai', $selesai)
            ->set('peserta', '120')->set('keperluan', 'Syukuran keluarga')->set('instansi', '')
            ->set('nama', 'Abdul Rahman')->set('hp', $hp)->call('kirim');
    }

    public function test_L04_riwayat_laundry_disaring_per_tanggal_masuk(): void
    {
        $hk = $this->akun('hk');
        foreach (['2026-10-04 09:00:00' => ['Rina Marlina', '081377001100'], '2026-10-05 16:00:00' => ['Yosef Lado', '081377002200']] as $waktu => [$nama, $hp]) {
            Carbon::setTestNow($waktu);
            Livewire::actingAs($hk)->test(PesananLaundry::class)->call('bukaTerima')
                ->set('jenisBaru', 'luar')->set('namaBaru', $nama)->set('hpBaru', $hp)->set('tagihBaru', 'sekarang')
                ->call('terima')->assertHasNoErrors();
        }
        [$lama, $baru] = ServiceOrder::orderBy('order_date')->get()->all();
        ServiceOrder::query()->update(['status' => 'delivered']);
        Carbon::setTestNow('2026-10-06 10:00:00');

        $t = Livewire::actingAs($hk)->test(PesananLaundry::class)->set('tab', 'selesai')
            ->assertSee($lama->order_code)->assertSee($baru->order_code)
            ->set('tanggal', '2026-10-05')->assertDontSee($lama->order_code)->assertSee($baru->order_code)->assertSee('Semua tanggal')
            ->set('tanggal', '2026-10-01')->assertSee('Tidak ada cucian diserahkan yang masuk 1 Oktober 2026.');

        // Papan Aktif tidak memakai saringan tanggal
        $t->set('tab', 'aktif')->assertSet('tanggal', '')->assertDontSee('Semua tanggal');
    }

    public function test_A01_pengajuan_aula_tanpa_harga_dan_jadwal_bentrok_ditolak(): void
    {
        $this->ajukan('2026-10-17', '18:00', '22:00')->assertHasNoErrors()->assertSee('Pengajuan HB-0001 terkirim');
        $b = HallBooking::sole();
        $this->assertSame(['pending', 0.0, 120], [$b->status, (float) $b->rental_price, $b->participant_count]);
        Http::assertSent(fn (Request $q) => str_contains($q['message'], 'pengajuan sewa Aula Utama (HB-0001)'));

        $this->ajukan('2026-10-17', '21:00', '23:00', '082199887766')->assertHasErrors('mulai');
        $this->ajukan('2026-10-17', '22:00', '23:30', '082199887766')->assertHasNoErrors();
        Livewire::test(SewaAula::class)->set('tanggal', '2026-10-17')->set('peserta', '200')->set('keperluan', 'Rapat')
            ->set('nama', 'Abdul Rahman')->set('hp', '081244556677')->set('jamMulai', '08:00')->set('jamSelesai', '10:00')
            ->call('kirim')->assertHasErrors(['peserta']);
        $this->assertSame(2, HallBooking::count());
    }

    public function test_A02_harga_usulan_lalu_manager_menyetujui_dengan_nota_atau_menolak(): void
    {
        $this->ajukan('2026-10-17', '18:00', '22:00');
        $b = HallBooking::sole();

        Livewire::actingAs($this->akun('fo'))->test(PemesananAula::class)
            ->call('open', $b->id)->call('mulai', 'harga')->set('harga', '1200000')->call('simpanHarga')->assertHasNoErrors()
            ->call('mulai', 'setuju')->assertForbidden();
        $this->assertSame(1200000.0, (float) $b->fresh()->rental_price);

        Livewire::actingAs($this->akun('manager'))->test(PemesananAula::class)
            ->call('open', $b->id)->call('mulai', 'setuju')->assertSet('harga', '1200000')->assertSet('tagih', 'perorangan')
            ->call('setujui')->assertHasNoErrors();
        $b->refresh();
        $this->assertSame('confirmed', $b->status);
        $this->assertSame(['hall', 1200000.0, '2026-10-16'], [$b->invoice->invoice_type, (float) $b->invoice->total_amount, $b->invoice->due_date->toDateString()]);
        Http::assertSent(fn (Request $q) => str_contains($q['message'], 'terkonfirmasi. Harga sewa Rp1.200.000') && str_contains($q['message'], '/nota/'));

        $this->ajukan('2026-10-24', '09:00', '12:00', '082199887766');
        $lain = HallBooking::latest('id')->first();
        Livewire::actingAs($this->akun('manager'))->test(PemesananAula::class)
            ->call('open', $lain->id)->call('mulai', 'tolak')->call('tolak')->assertHasErrors('alasan')
            ->set('alasan', 'Aula dipakai renovasi')->call('tolak')->assertHasNoErrors();
        $this->assertSame('rejected', $lain->fresh()->status);
        Http::assertSent(fn (Request $q) => str_contains($q['message'], 'Alasan: Aula dipakai renovasi'));
    }

    public function test_A03_sewa_aula_instansi_ditagihkan_bersama_tagihan_instansi(): void
    {
        $vale = Institution::create(['code' => 'VALE', 'name' => 'PT Vale Indonesia Tbk', 'default_billing_scheme' => 'monthly_fixed_date', 'billing_day' => 25]);
        Livewire::test(SewaAula::class)->set('tanggal', '2026-10-09')->set('jamMulai', '08:00')->set('jamSelesai', '16:00')
            ->set('peserta', '80')->set('keperluan', 'Pelatihan keselamatan kerja')->set('instansi', 'PT Vale Indonesia Tbk')
            ->set('nama', 'Andi Saputra')->set('hp', '081255500001')->call('kirim')->assertHasNoErrors();
        $b = HallBooking::sole();

        Livewire::actingAs($this->akun('manager'))->test(PemesananAula::class)
            ->call('open', $b->id)->call('mulai', 'setuju')->assertSet('tagih', 'instansi')
            ->set('harga', '1500000')->set('instansi', (string) $vale->id)->call('setujui')->assertHasNoErrors();
        $b->refresh();
        $this->assertSame(['confirmed', null, $vale->id], [$b->status, $b->invoice_id, $b->customer->institution_id]);
    }

    public function test_A03b_data_penanggung_jawab_tidak_diisi_otomatis(): void
    {
        Livewire::actingAs($this->akun('customer'))->test(SewaAula::class)
            ->assertSet('nama', '')->assertSet('email', '')->assertSet('hp', '')
            ->assertSee('Nama yang akan kami hubungi')->assertSee('Untuk salinan konfirmasi');
    }

    public function test_A04_kalender_kontak_dan_akses(): void
    {
        $this->ajukan('2026-10-17', '18:00', '22:00');
        $this->actingAs($this->akun('hk'))->get(route('staf.aula-pemesanan'))->assertForbidden();
        Livewire::actingAs($this->akun('fo'))->test(KalenderAula::class)->assertSee('Oktober 2026')->assertSee('18.00 · Syukuran keluarga');

        $this->get(route('kontak'))->assertOk()->assertSee('Pertanyaan umum')
            ->assertSee('Check-in mulai pukul 14.00 WITA')->assertSee('Tanya lewat WhatsApp');
        // Jadwal pemesanan lain tidak ditampilkan ke tamu
        $this->get(route('aula'))->assertOk()->assertSee('Rp1.000.000')->assertDontSee('18.00–22.00');
        $this->get(route('laundry'))->assertOk()->assertSee('Rp15.000');
    }

    public function test_A08_halaman_aula_dan_laundry_hanya_informasi_formulir_di_halaman_sendiri(): void
    {
        // Halaman informasi: tanpa formulir, tombol menuju halaman formulir
        $this->get(route('aula'))->assertOk()->assertSee(route('aula.ajukan'))
            ->assertDontSee('wire:submit="kirim"', false)->assertDontSee('Nama atau jenis acara')->assertDontSee('data-tawar-akun', false);
        $this->get(route('laundry'))->assertOk()->assertSee(route('laundry.pesan'))->assertSee('Minimal 2 kg per pesanan')
            ->assertDontSee('wire:submit="kirim"', false)->assertDontSee('Kode kamar');

        // Halaman formulir: formulir, ringkasan singkat, dan tautan kembali ke informasi
        $this->get(route('aula.ajukan'))->assertOk()->assertSee('wire:submit="kirim"', false)->assertSee('Nama atau jenis acara')
            ->assertSee('data-ringkasan-aula', false)->assertSee('75+ orang · mulai Rp1.000.000 / hari')->assertSee(route('aula'))
            ->assertDontSee('data-galeri-aula', false);
        $this->get(route('laundry.pesan'))->assertOk()->assertSee('wire:submit="kirim"', false)->assertSee('data-ringkasan-laundry', false)
            ->assertSee('Rp15.000/kg')->assertSee(route('laundry'));

        // Tautan lama dari WhatsApp (/laundry?t=…) diteruskan ke formulir
        $this->get(route('laundry', ['t' => 'LK7Q2M9X']))->assertRedirect(route('laundry.pesan', ['t' => 'LK7Q2M9X']));
    }

    public function test_A09_deskripsi_dari_database_dan_tiga_langkah_cara_sewa(): void
    {
        // Deskripsi kartu aula dibaca dari halls.description
        $this->aula->update(['description' => 'Ruang serbaguna untuk rapat, seminar, dan acara keluarga di jantung Malili.']);

        $this->get(route('aula'))->assertOk()
            ->assertSee('Ruang serbaguna untuk rapat, seminar, dan acara keluarga di jantung Malili.')->assertDontSee('Aula serbaguna')
            ->assertSee('data-cara-sewa', false)
            ->assertSeeInOrder(['Cara sewa aula', '3 langkah mudah, tanpa bayar di muka',
                'Langkah 1', 'Isi formulir', 'Pilih tanggal, jam, dan jumlah peserta. Gratis, tanpa bayar di muka.',
                'Langkah 2', 'Kami hubungi Anda', 'Staf menghubungi lewat WhatsApp untuk membahas kebutuhan acara dan harga.',
                'Langkah 3', 'Jadwal dikunci', 'Setelah harga disepakati, nota dan konfirmasi jadwal dikirim ke WhatsApp Anda.',
                'Mulai ajukan sewa', 'Ada pertanyaan? Tanya via WhatsApp'])
            ->assertSee('href="'.route('aula.ajukan').'#ajukan-aula" class="wt-btn-primary h-12 px-8 text-xs tracking-[.1em] max-sm:w-full" data-mulai-ajukan', false)
            ->assertSee('https://wa.me/6282292224111?text=', false)
            ->assertDontSee('Ajukan jadwal')->assertDontSee('Dihubungi staf')->assertDontSee('Jadwal terkonfirmasi');

        // Formulir: "tidak ada pembayaran sekarang" cukup sekali, dan anchor #ajukan-aula ada
        $form = $this->get(route('aula.ajukan'))->assertOk()->assertSee('id="ajukan-aula"', false)->getContent();
        $this->assertSame(1, substr_count($form, 'Tidak ada pembayaran sekarang'));
    }

    public function test_A05_fnb_hanya_melihat_jadwal_aula_terkonfirmasi(): void
    {
        $this->ajukan('2026-10-17', '18:00', '22:00');
        $menunggu = HallBooking::sole();
        $this->ajukan('2026-10-24', '09:00', '12:00', '082199887766');
        $jadi = HallBooking::latest('id')->first();
        Livewire::actingAs($this->akun('manager'))->test(PemesananAula::class)
            ->call('open', $jadi->id)->call('mulai', 'setuju')->set('harga', '1500000')->call('setujui')->assertHasNoErrors();

        // F&B: bisa membuka halaman, hanya tab Terkonfirmasi dan Riwayat, tanpa nomor, harga, dan aksi
        $this->actingAs($this->akun('fnb'))->get(route('staf.aula-pemesanan'))->assertOk();
        $this->actingAs($this->akun('fnb'))->get(route('staf.aula-kalender'))->assertOk();
        Livewire::actingAs($this->akun('fnb'))->test(PemesananAula::class)
            ->assertSet('tab', 'terkonfirmasi')
            ->assertSee('Jadwal acara yang sudah terkonfirmasi, untuk persiapan konsumsi.')->assertDontSee('Rundingkan harga')
            ->assertDontSee('Menunggu (')->assertDontSee('Catat pemesanan')
            ->assertSee($jadi->booking_code)->assertDontSee($menunggu->booking_code)
            ->assertSee('Syukuran keluarga · 120 peserta')->assertSee('Abdul Rahman')
            ->assertDontSee('Rp1.500.000')->assertDontSee('082199887766')
            ->set('tab', 'menunggu')->assertSet('tab', 'terkonfirmasi')
            ->call('open', $jadi->id)->assertSee('120 orang')
            ->assertDontSee('Batalkan')->assertDontSee('Kabari penyewa (WhatsApp)')->assertDontSee('Nota PDF')->assertDontSee('Isi harga usulan')
            ->assertDontSee('Harga sewa')->assertDontSee('082199887766')->assertDontSee('Rp1.500.000');

        // Aksi ubah ditolak di server, bukan hanya disembunyikan
        foreach ([['bukaBaru'], ['simpanBaru'], ['mulai', 'harga'], ['mulai', 'batal'], ['simpanHarga'], ['batalkan'], ['tandaiSelesai'], ['mulai', 'setuju']] as $aksi) {
            Livewire::actingAs($this->akun('fnb'))->test(PemesananAula::class)->call('open', $jadi->id)->call(...$aksi)->assertForbidden();
        }
        $this->assertSame(['pending', 'confirmed'], [$menunggu->fresh()->status, $jadi->fresh()->status]);

        // Pemesanan yang belum disepakati tidak bisa dibuka lewat tautan
        $this->assertNull(Livewire::actingAs($this->akun('fnb'))->withQueryParams(['buka' => $menunggu->id])->test(PemesananAula::class)->get('selectedId'));

        // Kalender: hanya terkonfirmasi dan selesai
        Livewire::actingAs($this->akun('fnb'))->test(KalenderAula::class)
            ->assertSee('09.00 · Syukuran keluarga')->assertDontSee('18.00 · Syukuran keluarga')->assertDontSee('Menunggu persetujuan');

        // FO tetap lengkap; staf FO + F&B tetap bisa mengubah
        Livewire::actingAs($this->akun('fo'))->test(PemesananAula::class)
            ->assertSet('tab', 'menunggu')->assertSee('Menunggu (1)')->assertSee('Catat pemesanan')->assertSee($menunggu->booking_code)
            ->call('open', $menunggu->id)->assertSee('081244556677')->assertSee('Isi harga usulan')->assertSee('Batalkan');
        Livewire::actingAs($this->akun('fo'))->test(KalenderAula::class)->assertSee('18.00 · Syukuran keluarga')->assertSee('Menunggu persetujuan');
        $foFnb = User::where('email', 'sari@wismatrans.id')->firstOrFail();
        Livewire::actingAs($foFnb)->test(PemesananAula::class)->assertSee('Catat pemesanan')
            ->call('bukaBaru')->assertSet('formBaru', true)
            ->call('open', $menunggu->id)->call('mulai', 'harga')->set('harga', '900000')->call('simpanHarga')->assertHasNoErrors();
        $this->assertSame(900000.0, (float) $menunggu->fresh()->rental_price);
    }

    public function test_A06_tarif_aula_per_hari_di_beranda_halaman_aula_dan_form_staf(): void
    {
        // Tanpa baris pengaturan: kisaran bawaan Rp1.000.000–Rp2.500.000
        $this->get(route('home'))->assertOk()
            ->assertSee('data-promo-aula', false)->assertSee('data-pengumuman-aula', false)
            ->assertSee('Aula 75+ orang')->assertSee('Mulai dari')->assertSee('Rp1.000.000')->assertSee('/ hari · harga menyesuaikan acara')->assertDontSee('full day')
            ->assertSee('Sound system')->assertSee('Proyektor')->assertSee('Konsumsi bisa dipesan terpisah, harganya menyesuaikan menu dan jumlah peserta.')->assertSee('Belum termasuk dekorasi')
            ->assertDontSee('Aula 150 orang')->assertDontSee('Rp2.500.000');

        $this->get(route('aula'))->assertOk()
            ->assertSeeInOrder(['Kapasitas', '75+', 'orang', 'Rapat · Seminar · Syukuran', 'Mulai', 'Rp1.000.000', '/ hari · harga menyesuaikan acara'])
            ->assertDontSee('Maks. 150 orang')->assertDontSee('Rp2.500.000')->assertDontSee('Full day')->assertDontSee('Bisa dipadukan dengan menginap')
            ->assertSee('Kursi dan meja')->assertSee('Belum termasuk dekorasi')->assertSee('Konsumsi bisa dipesan terpisah, harganya menyesuaikan menu dan jumlah peserta.')
            ->assertDontSee('meja, dan konsumsi')->assertDontSee('Sesuai kesepakatan');
        Livewire::test(SewaAula::class)->assertSet('jamMulai', '08:00')->assertSet('jamSelesai', '17:00');
        $this->get(route('aula.ajukan'))->assertOk()->assertSee('75+ orang · mulai Rp1.000.000 / hari')->assertDontSee('Rp2.500.000');

        // Harga dibaca dari Pengaturan, bukan tertulis di tampilan
        DB::table('settings')->insert([
            ['setting_key' => 'hall_price_min', 'value' => '1200000', 'type' => 'decimal', 'description' => 'Harga sewa aula terendah per hari'],
            ['setting_key' => 'hall_price_max', 'value' => '2000000', 'type' => 'decimal', 'description' => 'Harga sewa aula tertinggi per hari'],
        ]);
        Cache::forget('settings.all');
        $this->get(route('home'))->assertSee('Rp1.200.000')->assertDontSee('Rp1.000.000');
        $this->get(route('aula'))->assertSee('Rp1.200.000')->assertDontSee('Rp2.000.000');
        $this->get(route('aula.ajukan'))->assertSee('75+ orang · mulai Rp1.200.000 / hari')->assertDontSee('Rp2.000.000');

        // Staf FO melihat patokan tarif saat mengisi harga usulan
        $this->ajukan('2026-10-17', '08:00', '17:00');
        Livewire::actingAs($this->akun('fo'))->test(PemesananAula::class)
            ->call('open', HallBooking::sole()->id)->call('mulai', 'harga')
            ->assertSee('Tarif: Rp1.200.000–Rp2.000.000 per hari (full day)');
    }

    public function test_A07_semua_foto_aula_tampil_di_galeri_dan_foto_pertama_di_beranda(): void
    {
        // Tanpa foto unggahan: gambar gedung, tanpa galeri
        $this->get(route('aula'))->assertOk()->assertDontSee('data-galeri-aula', false)->assertSee('ds/img/bangunan_depan.png');

        Storage::fake('public');
        $foto = ['aula/kelas.png', 'aula/teater.png', 'aula/meja_bundar.png'];
        foreach ($foto as $p) {
            Storage::disk('public')->put($p, 'isi');
        }
        $this->aula->update(['photos' => $foto]);

        $this->get(route('aula'))->assertOk()->assertSee('data-galeri-aula', false)
            ->assertSee('storage/aula/kelas.png')->assertSee('storage/aula/teater.png')->assertSee('storage/aula/meja_bundar.png')
            ->assertSee('Foto Aula Utama 3')->assertSee('data-otomatis', false);
        $this->get(route('home'))->assertSee('storage/aula/kelas.png')->assertDontSee('storage/aula/teater.png');
    }

    public function test_A10_label_placeholder_dan_pesan_validasi_formulir_aula(): void
    {
        $this->aula->update(['description' => 'Ruang serbaguna untuk rapat, seminar, dan acara keluarga di jantung Malili.']);

        $this->get(route('aula.ajukan'))->assertOk()
            ->assertSee('Isi data acara Anda. Tidak ada pembayaran sekarang. Staf kami akan menghubungi Anda lewat WhatsApp untuk membahas kebutuhan dan harga.')
            ->assertSee('Ruang serbaguna untuk rapat, seminar, dan acara keluarga di jantung Malili.')
            ->assertSeeInOrder(['Tanggal mulai', 'Jam mulai', 'Jam selesai', 'Acara lebih dari satu hari', 'Tanggal berakhir',
                'Perkiraan jumlah peserta', 'Contoh: 50', 'Nama atau jenis acara', 'Contoh: Rapat kerja, syukuran, pelatihan',
                'Nama kontak', 'Nama yang akan kami hubungi', 'Nomor WhatsApp', '08xxxxxxxxxx',
                'Email (opsional)', 'Untuk salinan konfirmasi', 'Instansi/perusahaan (opsional)', 'Isi bila acara atas nama instansi',
                'Catatan tambahan (opsional)', 'Contoh: Susunan kursi, konsumsi, jam coffee break, perlengkapan lain', 'Kirim pengajuan'])
            ->assertDontSee('Tanggal acara')->assertDontSee('Acara selesai di hari lain')->assertDontSee('Nama penanggung jawab')
            ->assertDontSee('Kebutuhan lain')->assertDontSee('Isi jadwal dan kebutuhan acara Anda');

        // Pesan validasi memakai nama isian yang sama dengan label
        Livewire::test(SewaAula::class)->set('jamMulai', '')->set('jamSelesai', '')->call('kirim')
            ->assertSee('Tanggal mulai wajib diisi.')->assertSee('Jam mulai wajib diisi.')->assertSee('Jam selesai wajib diisi.')
            ->assertSee('Perkiraan jumlah peserta wajib diisi.')->assertSee('Nama atau jenis acara wajib diisi.')
            ->assertSee('Nama kontak wajib diisi.')->assertSee('Nomor WhatsApp wajib diisi.');
        Livewire::test(SewaAula::class)->set('tanggal', '2026-10-17')->set('tanggalSelesai', '2026-10-16')->call('kirim')
            ->assertHasErrors(['tanggalSelesai'])->assertSee('Tanggal berakhir tidak boleh sebelum tanggal mulai.');
        $this->assertSame(0, HallBooking::count());
    }

    public function test_A11_halaman_laundry_berisi_cara_pesan_kalkulator_jenis_jam_tanya_jawab_dan_ajakan(): void
    {
        $halaman = $this->get(route('laundry'))->assertOk();

        // Cara pesan: empat langkah berikon
        $halaman->assertSee('data-cara-pesan', false)
            ->assertSeeInOrder(['Cara pesan laundry', '4 langkah mudah',
                'Langkah 1', 'Pesan', 'Isi formulir. Tamu menginap cukup pilih kamar; tamu luar isi nama dan WhatsApp.',
                'Langkah 2', 'Serahkan cucian', 'Kami ambil ke kamar Anda, atau antar sendiri ke resepsionis.',
                'Langkah 3', 'Ditimbang', 'Tagihan dihitung dari berat dan dikirim ke WhatsApp Anda.',
                'Langkah 4', 'Selesai', 'Diantar ke kamar, atau diambil di resepsionis.']);

        // Kalkulator: harga dari layanan laundry (Rp15.000/kg) dan berat minimal LaundryService::MIN_KG (2 kg)
        $halaman->assertSee('data-kalkulator-laundry', false)->assertSee('Hitung perkiraan biaya')
            ->assertSee('harga: 15000, min: 2, berat: 2', false)->assertSee('Rp30.000')
            ->assertSee('Minimal 2 kg per pesanan. Total akhir mengikuti hasil timbang di wisma.')
            ->assertSee('type="range" min="1" max="15" step="0.5"', false);

        // Jenis cucian, jam layanan, tanya jawab, dan ajakan penutup
        $halaman->assertSeeInOrder(['Yang kami terima', 'Diterima', 'Kaos dan kemeja', 'Handuk dan sarung pribadi',
            'Tidak diterima', 'Jas, blazer, dan pakaian dry clean', 'Selimut atau bed cover tebal', 'Ragu dengan pakaian Anda? Tanyakan dulu ke resepsionis.'])
            ->assertSee('Penerimaan cucian')->assertSee('07.00–20.00 WITA')->assertSee('Pengantaran ke kamar')->assertSee('08.00–21.00 WITA')
            ->assertSee('data-tanya-laundry', false)->assertSee('Berapa lama cucian selesai?')->assertSee('Apakah bisa layanan kilat?')
            ->assertSee('aria-controls="jawab-laundry-1"', false)
            ->assertSee('tunai di resepsionis sebelum cucian diambil')
            ->assertSee('data-ajakan-laundry', false)->assertSee('Tinggal pesan, kami yang urus.')->assertSee('Pakaian bersih dan rapi untuk perjalanan Anda.')
            ->assertSee('Tanya via WhatsApp')->assertSee('https://wa.me/6282292224111?text=', false);

        // Semua tombol pesan menuju formulir laundry
        $this->assertGreaterThanOrEqual(4, substr_count($halaman->getContent(), 'href="'.route('laundry.pesan').'"'));
    }
}
