<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Enums\RoomStatus;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Livewire\Tamu\PesanKamar;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Reservation;
use App\Services\PembayaranMidtrans;
use App\Services\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Halaman Modul 3: data pemesan, halaman reservasi tamu, cek pesanan, pengajuan instansi, dan halaman staf. */
class HalamanReservasiTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        config(['services.midtrans.server_key' => 'uji-server-key', 'services.midtrans.client_key' => 'uji-client-key']);
        // Tagihan tidak meminta ke Midtrans sungguhan
        $this->app->instance(PembayaranMidtrans::class, new class extends PembayaranMidtrans
        {
            public function buatTagihan(BayarOnline $x, string $metode): array
            {
                $tagihan = ['metode' => $metode, 'order_id' => $x->awalanMidtrans().'-UJI1', 'jumlah' => $x->jumlahBayar(), 'nomor' => '8277000012345678', 'kode_perusahaan' => null, 'qr' => null];
                Cache::put(PembayaranMidtrans::kunci($x, 'tagihan'), $tagihan);

                return $tagihan;
            }

            public function sinkronkan(BayarOnline $x): void {}

            public function akhiriTagihan(BayarOnline $x): void
            {
                Cache::forget(PembayaranMidtrans::kunci($x, 'tagihan'));
            }
        });
    }

    private function pesanOnline(string $kode = 'dlx', array $q = ['masuk' => '2026-10-10', 'keluar' => '2026-10-13', 'tamu' => '2-0_2-0']): Reservation
    {
        Livewire::withQueryParams($q)->test(PesanKamar::class, ['kode' => $kode])
            ->set('nama', 'Rizky Pratama')->set('hp', '0812 9911 0022')->set('metode', 'bca')
            ->call('pesan')->assertHasNoErrors();

        return Reservation::latest('id')->first();
    }

    public function test_HR01_data_pemesan_membuat_reservasi_lalu_halaman_reservasi_menampilkan_nomor_bayar(): void
    {
        Livewire::withQueryParams(['masuk' => '2026-10-10', 'keluar' => '2026-10-13', 'tamu' => '2-0_2-0'])
            ->test(PesanKamar::class, ['kode' => 'dlx'])
            ->assertSee('Deluxe Room × 2 · 3 malam')
            ->assertSee('Rp2.760.000')
            ->assertSee('Pesan dan bayar Rp1.380.000')
            ->call('pesan')
            ->assertHasErrors(['nama' => 'required', 'hp' => 'required', 'metode']);

        $r = $this->pesanOnline();
        $this->assertSame(['pending_payment', 2, '081299110022'], [$r->status, $r->rooms()->count(), $r->customer->phone]);

        $this->get($r->urlStatus())->assertOk()
            ->assertSee('Bayar uang muka')
            ->assertSee('8277000012345678', false)
            ->assertSee('Rp1.380.000')
            ->assertSee($r->token());
    }

    public function test_HR02_kombinasi_kamar_dan_tamu_yang_tidak_muat(): void
    {
        $r = $this->pesanOnline('std', ['masuk' => '2026-10-10', 'keluar' => '2026-10-11', 'tamu' => '2-0_2-1', 'pilihan' => 'STD,FAM']);
        $this->assertSame(['STD', 'FAM'], $r->rooms()->with('roomType')->orderBy('id')->get()->map(fn ($k) => $k->roomType->code)->all());
        $this->assertSame(455000.0, (float) $r->dp_amount_required); // (360.000 + 550.000) × 50%

        Livewire::withQueryParams(['masuk' => '2026-10-10', 'keluar' => '2026-10-11', 'tamu' => '4-0'])
            ->test(PesanKamar::class, ['kode' => 'std'])
            ->assertSee('Standard Room hanya muat 2 orang, kamar 1 berisi 4 orang.');
    }

    public function test_HR03_halaman_reservasi_butuh_token_atau_akun_pemilik_dan_cek_pesanan(): void
    {
        $r = $this->pesanOnline();
        $this->get(route('reservasi.status', ['kode' => $r->reservation_code]))->assertNotFound();
        $this->get(route('reservasi.status', ['kode' => $r->reservation_code, 't' => 'SALAH123']))->assertNotFound();

        $this->post(route('cek.cari'), ['kode' => 'rsv0001', 'token' => strtolower($r->token())])->assertRedirect($r->urlStatus());
        $this->from(route('cek'))->post(route('cek.cari'), ['kode' => 'RSV-0001', 'token' => 'AAAA-BBBB'])
            ->assertRedirect(route('cek'))->assertSessionHasErrors(['kode' => 'Kode reservasi atau token tidak cocok. Periksa lagi pesan konfirmasi Anda.']);

        // Akun pemilik membuka dari Pesanan saya
        $akun = $this->akun('customer');
        $r->customer->update(['user_id' => $akun->id]);
        $this->actingAs($akun)->get(route('pesanan'))->assertOk()->assertSee('Bayar uang muka');
        $this->actingAs($akun)->get(route('reservasi.status', ['kode' => $r->reservation_code]))->assertOk();

        // Cek Pesanan khusus tamu tanpa akun: yang sudah masuk diarahkan ke Pesanan saya dan tautannya tidak tampil
        $this->actingAs($akun)->get(route('cek'))->assertRedirect(route('pesanan'));
        $this->actingAs($akun)->get(route('kontak'))->assertDontSee(route('cek'));
    }

    public function test_HR04_tamu_membatalkan_sendiri_dan_batas_waktu_pembatalan(): void
    {
        $r = $this->pesanOnline();
        $this->post(route('reservasi.batal', ['kode' => $r->reservation_code]), ['t' => $r->access_token, 'alasan' => 'Rencana berubah'])
            ->assertRedirect($r->urlStatus());
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertNull(Cache::get(PembayaranMidtrans::kunci($r, 'tagihan')));

        // Terkonfirmasi, besok datang: tidak bisa dibatalkan online
        $r2 = $this->pesanOnline('std', ['masuk' => '2026-10-07', 'keluar' => '2026-10-08', 'tamu' => '']);
        $r2->update(['status' => 'confirmed']);
        $this->get($r2->urlStatus())->assertOk()->assertDontSee('Ya, batalkan')
            ->assertSee('Perubahan tanggal atau pembatalan kurang dari 2 hari sebelum kedatangan dilayani resepsionis.');
        $this->post(route('reservasi.batal', ['kode' => $r2->reservation_code]), ['t' => $r2->access_token])->assertSessionHas('error');
        $this->assertSame('confirmed', $r2->fresh()->status);
    }

    public function test_HR05_pengajuan_instansi_dari_situs_lalu_disetujui_manager(): void
    {
        $this->post(route('instansi.store'), [
            'instansi' => 'PT PLN UP3 Palopo', 'name' => 'Fajar Nugroho', 'phone' => '081455500006',
            'masuk' => '2026-10-12', 'keluar' => '2026-10-15', 'jumlah' => 2, 'kegiatan' => 'Pemeliharaan jaringan distribusi',
        ])->assertRedirect();
        $r = Reservation::sole();
        $this->assertSame(['waiting_approval', null], [$r->status, $r->institution_id]);
        $this->assertSame('Instansi: PT PLN UP3 Palopo', $r->special_request);

        // FO melihat tetapi tidak bisa memutuskan
        Livewire::actingAs($this->akun('fo'))->test(PengajuanInstansi::class)
            ->assertSee('PT PLN UP3 Palopo')->assertDontSee('Tinjau dan setujui')
            ->call('open', $r->id, 'setuju')->assertForbidden();

        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->call('open', $r->id, 'setuju')
            ->assertSet('instansi', 'baru')->assertSet('namaBaru', 'PT PLN UP3 Palopo')
            ->set('skema', 'due_after_checkout')->set('jatuhTempo', '7')
            ->set('kamar', [(string) $this->kamar('106')->id, (string) $this->kamar('107')->id])
            ->assertSet('harga.'.$this->kamar('106')->id.'.harga', '360000')
            ->assertSee('Rp2.160.000')
            // Potongan persen wajib diisi; harga per malam tidak bisa diubah walau isiannya dimanipulasi
            ->call('jenisPotongan', (string) $this->kamar('106')->id, 'percent')
            ->call('setujui')->assertHasErrors(['harga.'.$this->kamar('106')->id.'.nilai'])
            ->set('harga.'.$this->kamar('106')->id.'.nilai', '20')
            ->set('harga.'.$this->kamar('106')->id.'.harga', '1')
            ->call('jenisPotongan', (string) $this->kamar('107')->id, 'free')
            ->assertSee('Hemat Rp216.000')->assertSee('Rp864.000')
            ->call('setujui')->assertHasNoErrors();

        $baris = $r->rooms()->with('room')->get()->keyBy('room.room_number');
        $this->assertSame(['360000.00', 'percent', '20.00'], [$baris['106']->price_per_night, $baris['106']->discount_type, $baris['106']->discount_value]);
        $this->assertSame(['0.00', null], [$baris['107']->price_per_night, $baris['107']->discount_type]);

        $r->refresh();
        $pln = Institution::sole();
        $this->assertSame(['confirmed', $pln->id, 7], [$r->status, $r->institution_id, $pln->due_days]);
        $this->assertSame($pln->id, $r->customer->institution_id);
        $this->assertSame(['106', '107'], $r->rooms()->with('room')->get()->map(fn ($k) => $k->room->room_number)->all());
    }

    public function test_HR06_hak_akses_halaman_reservasi_staf(): void
    {
        $this->actingAs($this->akun('admin'))->get(route('staf.reservasi'))->assertForbidden();
        $this->actingAs($this->akun('hk'))->get(route('staf.reservasi'))->assertForbidden();
        $this->actingAs($this->akun('manager'))->get(route('staf.reservasi-langsung'))->assertForbidden();
        $this->actingAs($this->akun('manager'))->get(route('staf.reservasi'))->assertOk();
        $this->actingAs($this->akun('fo'))->get(route('staf.reservasi-langsung'))->assertOk();
        $this->actingAs($this->akun('fo'))->get(route('staf.check-in'))->assertOk();

        $r = $this->pesanOnline();
        Livewire::actingAs($this->akun('manager'))->test(DaftarReservasi::class)
            ->assertSee($r->reservation_code)
            ->call('open', $r->id)->assertSee('Rizky Pratama')->assertDontSee('Ubah tanggal')
            ->call('mulai', 'batal')->assertForbidden();
    }

    public function test_HR07_front_office_walk_in_langsung_check_in_lalu_batalkan_lewat_daftar(): void
    {
        Carbon::setTestNow('2026-10-06 15:00:00');
        Livewire::actingAs($this->akun('fo'))->test(ReservasiLangsung::class)
            ->set('kamar', [(string) $this->kamar('104')->id])
            ->set('nama', 'Agus Salim')->set('hp', '081277003344')->set('namaKtp', 'Salim Agus Wijaya')
            ->call('simpan')->assertHasNoErrors()->assertRedirect();

        $r = Reservation::sole();
        $this->assertSame(['checked_in', 'walk_in', 'Salim Agus Wijaya'], [$r->status, $r->booking_channel, $r->id_card_holder_name]);
        $this->assertSame(RoomStatus::OccupiedClean, $this->kamar('104')->status);

        // Reservasi lain dibatalkan FO dengan alasan
        $r2 = app(ReservasiService::class)->buatLangsung(Customer::create(['full_name' => 'Dian Permata', 'phone' => '081388776655']),
            CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-21'), [$this->kamar('105')->id], $this->akun('fo'));
        Livewire::actingAs($this->akun('fo'))->test(DaftarReservasi::class)
            ->call('open', $r2->id)->call('mulai', 'batal')
            ->call('simpanBatal')->assertHasErrors(['alasan' => 'required'])
            ->set('alasan', 'Tamu membatalkan lewat telepon')->call('simpanBatal')->assertHasNoErrors();
        $this->assertSame('cancelled', $r2->fresh()->status);
    }

    public function test_HR08_check_in_reservasi_online_lewat_halaman_check_in(): void
    {
        $r = $this->pesanOnline('dlx', ['masuk' => '2026-10-06', 'keluar' => '2026-10-08', 'tamu' => '']);
        $r->update(['status' => 'confirmed']);
        $baris = $r->rooms()->sole();
        Carbon::setTestNow('2026-10-06 14:10:00');

        Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)
            ->assertSee($r->reservation_code)->assertSee('belum ditetapkan')
            ->call('open', $r->id)
            ->set('penempatan.'.$baris->id, (string) $this->kamar('201')->id)
            ->call('simpan')->assertHasErrors(['kamar.'.$baris->id])
            ->set('penempatan.'.$baris->id, (string) $this->kamar('113')->id)
            ->call('simpan')->assertHasNoErrors();

        $this->assertSame('checked_in', $r->fresh()->status);
        $this->assertSame($this->kamar('113')->id, $baris->fresh()->room_id);
    }
}
