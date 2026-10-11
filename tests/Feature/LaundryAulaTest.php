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
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Laundry (tamu menginap dan tamu luar), sewa aula, dan halaman kontak. */
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

        Livewire::actingAs($this->akun('fnb'))->test(PemesananAula::class)
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
            ->assertSee('Orang yang dihubungi staf soal acara')->assertSee('Bila staf perlu menghubungi lewat email');
    }

    public function test_A04_kalender_kontak_dan_akses(): void
    {
        $this->ajukan('2026-10-17', '18:00', '22:00');
        $this->actingAs($this->akun('hk'))->get(route('staf.aula-pemesanan'))->assertForbidden();
        Livewire::actingAs($this->akun('fo'))->test(KalenderAula::class)->assertSee('Oktober 2026')->assertSee('18.00 · Syukuran keluarga');

        $this->get(route('kontak'))->assertOk()->assertSee('Pertanyaan umum')
            ->assertSee('Check-in mulai pukul 14.00 WITA')->assertSee('Tanya lewat WhatsApp');
        // Jadwal pemesanan lain tidak ditampilkan ke tamu
        $this->get(route('aula'))->assertOk()->assertSee('Sesuai kesepakatan')->assertDontSee('18.00–22.00');
        $this->get(route('laundry'))->assertOk()->assertSee('Rp15.000');
    }
}
