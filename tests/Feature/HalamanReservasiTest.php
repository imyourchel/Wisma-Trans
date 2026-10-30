<?php

namespace Tests\Feature;

use App\Contracts\BayarOnline;
use App\Enums\RoomStatus;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Livewire\Tamu\DaftarKamar;
use App\Livewire\Tamu\PesanKamar;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Reservation;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\ReservasiService;
use App\Support\PesananPerangkat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Js;
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
            ->assertSee('Deluxe Room × 2')->assertSee('10 Okt – 13 Okt 2026 · 3 malam')
            ->assertSee('Rp2.760.000')
            ->assertSee('Bayar Rp1.380.000')
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
            ->assertSee('Standard Room hanya muat 2 dewasa + 1 anak, kamar 1 berisi 4 dewasa.');
    }

    public function test_HR03_halaman_reservasi_butuh_token_atau_akun_pemilik_dan_cek_pesanan(): void
    {
        $r = $this->pesanOnline();
        $this->get(route('reservasi.status', ['kode' => $r->reservation_code]))->assertNotFound();
        $this->get(route('reservasi.status', ['kode' => $r->reservation_code, 't' => 'SALAH123']))->assertNotFound();

        // Cek Pesanan memberi akses lihat saja, bukan tautan bertoken (CekPesananAmanTest)
        $this->post(route('cek.cari'), ['kode' => 'rsv0001', 'token' => strtolower($r->token())])
            ->assertRedirect(route('cek.lihat', ['jenis' => 'reservasi', 'kode' => 'RSV-0001']));
        $this->from(route('cek'))->post(route('cek.cari'), ['kode' => 'RSV-0001', 'token' => 'AAAA-BBBB'])
            ->assertRedirect(route('cek'))->assertSessionHasErrors(['kode' => 'Kode pesanan atau nomor WhatsApp tidak cocok. Periksa lagi pesan konfirmasi Anda.']);

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
        // Halaman: tanpa kepala bergambar dan pita keunggulan, tiga bagian bernomor, isian tetap masuk/keluar/jumlah/catatan
        $this->get(route('instansi'))->assertOk()
            ->assertDontSee('Reservasi Instansi &amp; Rombongan', false)->assertDontSee('Tanya via WhatsApp')
            ->assertSee('Data instansi dan penanggung jawab')->assertSee('Nama instansi atau perusahaan')->assertSee('placeholder="Nama resmi instansi atau perusahaan"', false)->assertSee('Nama penanggung jawab')
            ->assertSee('Rencana menginap')->assertSee('Jumlah tamu')->assertDontSee('Jumlah peserta')->assertDontSee('Perkiraan jumlah orang yang menginap')
            ->assertSee('placeholder="08xxxxxxxxxx"', false)->assertSee('Tuliskan kebutuhan lain bila ada')
            ->assertSee('1 bulan')->assertSee('3 bulan')
            ->assertDontSee('Tanpa uang muka')->assertDontSee('Sekalian aula &amp; konsumsi', false)
            ->assertSeeInOrder(['Data instansi dan penanggung jawab', 'Rencana menginap', 'Catatan tambahan', 'Kirim pengajuan'])
            ->assertSeeInOrder(['Butuh aula', 'Butuh konsumsi', 'Butuh laundry', 'Kamar berdekatan', 'Pisah kamar laki-laki/perempuan'])
            ->assertSee('Perkiraan, bisa berubah')->assertSee('Ringkasan pengajuan')->assertSee('Alurnya')
            ->assertSee('Tidak ada pembayaran sekarang. Kami menghubungi Anda lewat WhatsApp dalam 1×24 jam.')
            ->assertSee('name="masuk"', false)->assertSee('name="keluar"', false)->assertSee('name="jumlah"', false)->assertSee('name="catatan"', false);
        // Formulir tanpa placeholder "Contoh: …"
        $html = $this->get(route('instansi'))->getContent();
        $form = substr($html, strpos($html, 'id="form-instansi"'), strpos($html, '</form>', strpos($html, 'id="form-instansi"')) - strpos($html, 'id="form-instansi"'));
        $this->assertStringNotContainsString('Contoh:', $form);

        $this->post(route('instansi.store'), [
            'instansi' => 'PT PLN UP3 Palopo', 'name' => 'Fajar Nugroho', 'phone' => '081455500006',
            'masuk' => '2026-10-12', 'keluar' => '2026-10-15', 'jumlah' => 2, 'peserta' => 4, 'kegiatan' => 'Pemeliharaan jaringan distribusi',
        ])->assertRedirect();
        $r = Reservation::sole();
        $this->assertSame(['waiting_approval', null], [$r->status, $r->institution_id]);
        $this->assertSame('Jumlah peserta: 4 orang
Instansi: PT PLN UP3 Palopo', $r->special_request);

        // FO melihat tetapi tidak bisa memutuskan
        Livewire::actingAs($this->akun('fo'))->test(PengajuanInstansi::class)
            ->assertSee('PT PLN UP3 Palopo')->assertDontSee('Tinjau dan setujui')
            ->call('open', $r->id, 'setuju')->assertForbidden();

        // Tinjau dan setujui tampil sebagai panel halaman (daftar disembunyikan, tanpa modal); tolak tetap dialog kecil
        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->assertSee('Tinjau dan setujui')
            ->call('open', $r->id, 'setuju')
            ->assertSee('data-tinjau-pengajuan', false)->assertSee('Kembali ke daftar pengajuan')
            ->assertDontSee('aria-modal="true"', false)->assertDontSee('Tinjau dan setujui')->assertDontSee('Isi pengajuan untuk instansi')
            ->call('close')->assertSee('Tinjau dan setujui')->assertDontSee('data-tinjau-pengajuan', false)
            ->call('open', $r->id, 'tolak')->assertSee('aria-modal="true"', false)->assertSee('Tinjau dan setujui')
            ->assertSee('placeholder="Tuliskan alasan penolakan secara singkat dan jelas"', false)->assertDontSee('Contoh: hanya tersisa');

        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->call('open', $r->id, 'setuju')
            // Tanpa pilihan bawaan; nama tulisan pemohon siap dipakai bila Manager memilih "Buat instansi baru"
            ->assertSet('instansi', '')->assertSet('pilihan', '')->assertSet('namaBaru', 'PT PLN UP3 Palopo')
            ->set('pilihan', 'baru')->assertSet('instansi', 'baru')
            ->assertSee('Alamat instansi')->assertSee('Telepon kantor (opsional)')->assertSee('placeholder="08xxxxxxxxxx"', false)
            ->assertSee('Email kantor untuk tagihan (opsional)')->assertSee('Kosongkan bila tagihan cukup dikirim ke email penanggung jawab.')
            ->assertSee('placeholder="Catatan tambahan untuk pengajuan ini"', false)->assertDontSee('Contoh: potongan')
            ->set('alamatBaru', 'Jl. Andi Djemma No. 1, Palopo')
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
            ->assertSee('−20%')->assertSee('Rp864.000')
            ->call('setujui')->assertHasNoErrors();

        $baris = $r->rooms()->with('room')->get()->keyBy('room.room_number');
        $this->assertSame(['360000.00', 'percent', '20.00'], [$baris['106']->price_per_night, $baris['106']->discount_type, $baris['106']->discount_value]);
        $this->assertSame(['0.00', null], [$baris['107']->price_per_night, $baris['107']->discount_type]);

        $r->refresh();
        $pln = Institution::sole();
        $this->assertSame(['confirmed', $pln->id, 7], [$r->status, $r->institution_id, $pln->due_days]);
        // Tanpa telepon kantor dan tanpa email pemohon: keduanya kosong, alamat tersimpan
        $this->assertSame(['Jl. Andi Djemma No. 1, Palopo', null, null], [$pln->address, $pln->phone, $pln->email]);
        $this->assertSame($pln->id, $r->customer->institution_id);
        $this->assertSame(['106', '107'], $r->rooms()->with('room')->get()->map(fn ($k) => $k->room->room_number)->all());
    }

    public function test_HR05c_instansi_baru_wajib_alamat_email_kantor_tidak_diisi_otomatis(): void
    {
        $this->post(route('instansi.store'), [
            'instansi' => 'PT PLN UP3 Palopo', 'name' => 'Fajar Nugroho', 'phone' => '081455500006', 'email' => 'fajar@pln.test',
            'masuk' => '2026-10-12', 'keluar' => '2026-10-15', 'jumlah' => 1, 'peserta' => 2,
        ])->assertRedirect();
        $r = Reservation::sole();

        $dialog = Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->call('open', $r->id, 'setuju')
            // Email kantor tidak diisi dengan email pemohon; kosong berarti tagihan ke narahubung utama
            ->assertSet('emailBaru', '')
            ->set('pilihan', 'baru')
            ->set('skema', 'due_after_checkout')->set('jatuhTempo', '7')
            ->set('kamar', [(string) $this->kamar('106')->id])
            // Alamat wajib; telepon dan email divalidasi seperti formulir instansi
            ->set('alamatBaru', '  ')->set('teleponBaru', '0474-abc')->set('emailBaru', 'bukan-email')
            ->call('setujui')->assertHasErrors(['alamatBaru' => 'required', 'teleponBaru', 'emailBaru'])
            ->assertSee('Isi alamat instansi.')->assertSee('Isi angka, misalnya 0474 321123.');
        $this->assertSame([0, 'waiting_approval'], [Institution::count(), $r->fresh()->status]);

        $dialog->set('alamatBaru', 'Jl. Andi Djemma No. 1, Palopo')->set('teleponBaru', ' 0474 321123 ')->set('emailBaru', 'Tagihan@PLN.test')
            ->call('setujui')->assertHasNoErrors();
        $pln = Institution::sole();
        $this->assertSame(['Jl. Andi Djemma No. 1, Palopo', '0474 321123', 'tagihan@pln.test'], [$pln->address, $pln->phone, $pln->email]);
        $this->assertSame($pln->id, $r->fresh()->institution_id);

        // Memilih instansi terdaftar tidak mewajibkan alamat dan tidak mengubah kontak instansi itu
        $this->post(route('instansi.store'), [
            'instansi' => 'PT PLN UP3 Palopo', 'name' => 'Fajar Nugroho', 'phone' => '081455500006', 'email' => 'fajar@pln.test',
            'masuk' => '2026-10-20', 'keluar' => '2026-10-21', 'jumlah' => 1, 'peserta' => 2,
        ])->assertRedirect();
        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->call('open', Reservation::latest('id')->first()->id, 'setuju')->assertSet('pilihan', 'terdaftar')
            ->set('kamar', [(string) $this->kamar('106')->id])
            ->call('setujui')->assertHasNoErrors();
        $this->assertSame(['0474 321123', 'tagihan@pln.test'], [$pln->fresh()->phone, $pln->fresh()->email]);
    }

    public function test_HR05d_tanggal_tagih_dan_jatuh_tempo_instansi_terdaftar(): void
    {
        $pln = Institution::create(['name' => 'PT PLN UP3 Palopo', 'address' => 'Jl. Andi Djemma No. 1', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 7]);
        $ajukan = function (string $masuk, string $keluar) {
            $this->post(route('instansi.store'), [
                'instansi' => 'PT PLN UP3 Palopo', 'name' => 'Fajar Nugroho', 'phone' => '081455500006',
                'masuk' => $masuk, 'keluar' => $keluar, 'jumlah' => 1, 'peserta' => 2,
            ])->assertRedirect();

            return Reservation::latest('id')->first();
        };

        // Terisi dari due_days instansi; kolom tampil untuk instansi terdaftar beserta bantuan
        $r = $ajukan('2026-10-12', '2026-10-13');
        $dialog = Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->call('open', $r->id, 'setuju')->assertSet('instansi', (string) $pln->id)->assertSet('jatuhTempo', '7')
            ->assertSee('Jatuh tempo (hari setelah check-out)')->assertSee('Berlaku juga untuk tagihan instansi ini berikutnya.')
            ->set('kamar', [(string) $this->kamar('106')->id])
            // Skema bulanan: tanggal tagih instansi masih kosong, jadi wajib diisi
            ->set('skema', 'monthly_fixed_date')->assertSee('Tanggal tagih tiap bulan')
            ->call('setujui')->assertHasErrors(['tanggalTagih' => 'required'])
            ->set('tanggalTagih', '32')->call('setujui')->assertHasErrors(['tanggalTagih']);
        $this->assertSame('waiting_approval', $r->fresh()->status);
        $dialog->set('tanggalTagih', '25')->call('setujui')->assertHasNoErrors();
        $this->assertSame([25, 7], [$pln->fresh()->billing_day, $pln->fresh()->due_days]);

        // Jatuh tempo dikosongkan ditolak; nilai baru disimpan ke instansi
        $r = $ajukan('2026-10-20', '2026-10-21');
        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->call('open', $r->id, 'setuju')->assertSet('jatuhTempo', '7')->assertSet('tanggalTagih', '25')
            ->set('kamar', [(string) $this->kamar('106')->id])
            ->set('jatuhTempo', '')->call('setujui')->assertHasErrors(['jatuhTempo' => 'required'])
            ->set('jatuhTempo', '21')->call('setujui')->assertHasNoErrors();
        $this->assertSame([25, 21], [$pln->fresh()->billing_day, $pln->fresh()->due_days]);
    }

    public function test_HR05b_pengajuan_instansi_jumlah_peserta_dan_batas_180_malam(): void
    {
        $isi = ['instansi' => 'PT PLN UP3 Palopo', 'name' => 'Fajar Nugroho', 'phone' => '081455500006', 'masuk' => '2026-10-12', 'keluar' => '2026-10-15', 'jumlah' => 3];

        // Peserta lebih sedikit dari kamar ditolak; tanpa peserta juga ditolak
        $this->from(route('instansi'))->post(route('instansi.store'), $isi + ['peserta' => 2])
            ->assertSessionHasErrors(['peserta' => 'Jumlah tamu tidak boleh lebih sedikit dari jumlah kamar.']);
        $this->from(route('instansi'))->post(route('instansi.store'), $isi)->assertSessionHasErrors(['peserta' => 'Isi jumlah tamu.']);
        $this->from(route('instansi'))->post(route('instansi.store'), ['name' => '', 'instansi' => ''] + $isi + ['peserta' => 6])
            ->assertSessionHasErrors(['name' => 'Isi nama penanggung jawab.', 'instansi' => 'Isi nama instansi atau perusahaan.']);
        $this->assertSame(0, Reservation::count());

        // 3 kamar, 10 peserta: baris pertama catatan, ditampilkan terpisah ke Manager
        $this->post(route('instansi.store'), $isi + ['peserta' => 10, 'catatan' => 'Butuh aula'])->assertRedirect();
        $r = Reservation::sole();
        $this->assertStringStartsWith('Jumlah peserta: 10 orang', $r->special_request);
        $this->assertSame("Jumlah peserta: 10 orang\nInstansi: PT PLN UP3 Palopo\nButuh aula", $r->special_request);
        $this->assertSame([10, 'Butuh aula'], [$r->pesertaInstansi(), $r->catatanTamu()]);
        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->assertSee('Jumlah tamu')->assertSee('10 orang')->assertDontSee('Peserta')->assertSee('“Butuh aula”', false)->assertDontSee('Jumlah peserta: 10 orang')
            ->call('open', $r->id, 'setuju')->assertSee('· 10 tamu')->assertDontSee('Peserta: 10 orang');

        // Pengajuan instansi 60 malam diterima; lebih dari 180 malam ditolak
        $this->post(route('instansi.store'), ['masuk' => '2026-10-12', 'keluar' => '2026-12-11', 'peserta' => 6] + $isi)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(60, Reservation::latest('id')->first()->request->malam());
        $this->from(route('instansi'))->post(route('instansi.store'), ['masuk' => '2026-10-12', 'keluar' => '2027-04-20', 'peserta' => 6] + $isi)
            ->assertSessionHasErrors(['keluar' => 'Maksimal 180 malam per pengajuan. Hubungi kami untuk masa menginap lebih lama.']);
        $this->assertSame(180, ReservasiService::MAKS_MALAM_INSTANSI);

        // Pesan kamar umum tetap maksimal 30 malam
        $this->assertSame(30, ReservasiService::MAKS_MALAM);
        Livewire::test(DaftarKamar::class)->call('cek', '2026-10-12', '2026-12-11', '')->assertSee('Maksimal 30 malam per pemesanan.');
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
            ->assertSee($r->reservation_code)->assertSee('Kamar 111')
            ->call('open', $r->id)
            ->set('penempatan.'.$baris->id, (string) $this->kamar('201')->id)
            ->call('simpan')->assertHasErrors(['kamar.'.$baris->id])
            ->set('penempatan.'.$baris->id, (string) $this->kamar('113')->id)
            ->call('simpan')->assertHasNoErrors();

        $this->assertSame('checked_in', $r->fresh()->status);
        $this->assertSame($this->kamar('113')->id, $baris->fresh()->room_id);
    }

    public function test_HR09_satu_tombol_batal_dan_tautan_bagikan_hanya_lihat(): void
    {
        $r = $this->pesanOnline();
        $html = $this->get($r->urlStatus())->assertOk()
            ->assertDontSee("buka('batal')", false)->assertSee('Ya, batalkan')->assertDontSee('Salin tautan reservasi')
            ->assertSee('data-bagikan', false)->assertSee('Salin tautan')->assertSee('WhatsApp')
            ->assertSee('Tautan ini hanya untuk melihat. Pembatalan dan perubahan hanya bisa dilakukan oleh pemesan.')
            ->getContent();
        // Batalkan hanya di kotak bawah
        $this->assertSame(1, substr_count($html, 'Batalkan</button>'));

        // Tautan lihat bertanda tangan tanpa token, dan teks yang dibagikan
        $url = URL::signedRoute('reservasi.lihat', ['kode' => $r->reservation_code]);
        $this->assertStringNotContainsString($r->access_token, $url);
        $this->assertStringContainsString(Js::from($url)->toHtml(), $html);
        $this->assertStringContainsString(Js::from("Reservasi Wisma Trans {$r->reservation_code}\n10–13 Okt 2026 · 3 malam · 2 kamar\nStatus: Menunggu uang muka\nLihat detail: {$url}")->toHtml(), $html);

        // Penerima tautan: baca saja, tanpa bayar, batal, ubah, kode, nota, atau nomor lengkap; tidak dicatat di perangkatnya
        // (antrean cookie dari kunjungan pemesan di atas dikosongkan dulu, karena tes memakai satu aplikasi)
        app('cookie')->flushQueuedCookies();
        $lihat = $this->get($url)->assertOk()->assertCookieMissing(PesananPerangkat::COOKIE)
            ->assertSee('Dibagikan oleh pemesan, hanya untuk melihat.')->assertSee($r->reservation_code)
            ->assertDontSee('8277000012345678')->assertDontSee('Ya, batalkan')->assertDontSee('Pilih tanggal baru')
            ->assertDontSee('data-bagikan', false)->assertDontSee('Kode kamar')->assertDontSee('Pembayaran online sedang tidak tersedia')
            ->assertSee('0812')->assertDontSee('081299110022');
        $this->assertStringNotContainsString($r->token(), $lihat->getContent());
        $this->assertStringNotContainsString($r->access_token, $lihat->getContent());
        $this->assertStringNotContainsString(route('reservasi.bayar', ['kode' => $r->reservation_code]), $lihat->getContent());

        // Tanda tangan diubah atau tidak ada: ditolak
        $this->get($url.'0')->assertForbidden();
        $this->get(route('reservasi.lihat', ['kode' => $r->reservation_code]))->assertForbidden();

        // Terkonfirmasi jauh hari: Ubah tanggal tetap di kartu, Batalkan tetap satu di kotak bawah
        $r2 = $this->pesanOnline('std', ['masuk' => '2026-10-20', 'keluar' => '2026-10-22', 'tamu' => '']);
        $r2->update(['status' => 'confirmed']);
        $html = $this->get($r2->urlStatus())->assertOk()->assertSee('@click="bukaUbah()"', false)->assertSee('Pilih tanggal baru')->getContent();
        $this->assertSame(1, substr_count($html, 'Batalkan</button>'));
        $this->get(URL::signedRoute('reservasi.lihat', ['kode' => $r2->reservation_code]))->assertOk()
            ->assertDontSee('Pilih tanggal baru')->assertDontSee('@click="bukaUbah()"', false)->assertDontSee('Unduh nota');
    }

    public function test_HR10_kartu_pengajuan_instansi_dan_kode_akses(): void
    {
        $this->post(route('instansi.store'), [
            'instansi' => 'PT Baru Sekali', 'name' => 'Fajar Nugroho', 'phone' => '081455500006',
            'masuk' => '2026-10-12', 'keluar' => '2026-10-15', 'jumlah' => 3, 'peserta' => 6,
            'catatan' => "Butuh aula\nButuh konsumsi\nMohon kamar lantai bawah\nuntuk peserta lansia",
        ])->assertRedirect();
        $r = Reservation::sole();

        $this->get($r->urlStatus())->assertOk()
            ->assertSee('PT Baru Sekali')->assertSee('menunggu konfirmasi')->assertDontSee('Diisi saat persetujuan')
            ->assertSee('Jumlah tamu')->assertSee('6 orang')
            ->assertSee('data-kebutuhan-tamu', false)->assertSeeInOrder(['Butuh aula', 'Butuh konsumsi'])
            ->assertSee("Mohon kamar lantai bawah\nuntuk peserta lansia", false)->assertSee('whitespace-pre-line', false)
            ->assertDontSee('Jumlah peserta: 6 orang')->assertDontSee('Instansi: PT Baru Sekali')->assertDontSee('Permintaan khusus')
            ->assertSee('Kode akses')->assertDontSee('Kode kamar')
            ->assertSee('Dipakai untuk cek status. Setelah disetujui, kode ini juga menjadi kode kamar untuk pesan makanan dan laundry.');

        // Nama instansi tidak ditulis: baru tampil "Diisi saat persetujuan"
        $r->update(['special_request' => "Jumlah peserta: 6 orang\nButuh aula"]);
        $this->get($r->urlStatus())->assertOk()->assertSee('Diisi saat persetujuan')->assertDontSee('menunggu konfirmasi');

        // Setelah disetujui: label kode kamar, dan Permintaan khusus tanpa baris sistem
        $r->update(['status' => 'confirmed', 'special_request' => "Jumlah peserta: 6 orang\nInstansi: PT Baru Sekali\nButuh aula\nMohon kamar lantai bawah"]);
        $this->get($r->urlStatus())->assertOk()->assertDontSee('Kode akses')
            ->assertSee('Permintaan khusus')->assertSee('data-permintaan-khusus', false)->assertSee('Butuh aula')->assertSee('Mohon kamar lantai bawah')
            ->assertDontSee('Jumlah peserta: 6 orang')->assertDontSee('Instansi: PT Baru Sekali');
    }
}
