<?php

namespace Tests\Feature;

use App\Livewire\Staf\Pelanggan\Instansi as InstansiPelanggan;
use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Services\Keuangan\PiutangService;
use App\Services\Reservasi\ReservasiService;
use App\Support\NamaInstansi;
use App\Support\Nomor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Instansi ganda: normalisasi nama, saran di dialog setuju, narahubung A dan B, dan gabungkan instansi duplikat. */
class InstansiGandaTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private Institution $vale;

    private Customer $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        $this->vale = Institution::create(['code' => 'VALE', 'name' => 'PT Vale Indonesia Tbk', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $this->a = Customer::create(['full_name' => 'Andi Saputra', 'phone' => '081355500101', 'institution_id' => $this->vale->id, 'is_active' => true]);
        $this->vale->update(['primary_contact_id' => $this->a->id]);
    }

    /** Pengajuan dari situs oleh narahubung B. */
    private function ajukan(string $instansi, string $nama = 'Budi Hartono', string $hp = '081355500202'): Reservation
    {
        $this->post(route('instansi.store'), [
            'instansi' => $instansi, 'name' => $nama, 'phone' => $hp,
            'masuk' => '2026-10-12', 'keluar' => '2026-10-14', 'jumlah' => 1, 'peserta' => 2,
        ])->assertRedirect();

        return Reservation::latest('id')->first();
    }

    private function dialog(Reservation $r, string $kamar = '106')
    {
        return Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)->call('open', $r->id, 'setuju')
            ->set('kamar', [(string) $this->kamar($kamar)->id]);
    }

    public function test_IG01_normalisasi_nama_instansi(): void
    {
        $this->assertSame('vale', NamaInstansi::normalisasi('PT. Vale'));
        $this->assertSame('vale indonesia', NamaInstansi::normalisasi('PT Vale Indonesia Tbk'));
        $this->assertSame('pln up3 palopo', NamaInstansi::normalisasi('PT PLN (Persero) UP3 Palopo'));
        $this->assertSame('dinas kesehatan luwu timur', NamaInstansi::normalisasi('  Dinas  Kesehatan, Luwu Timur '));
        $this->assertSame('maju jaya', NamaInstansi::normalisasi('CV. Maju Jaya'));

        // "vale" ⊂ "vale indonesia"; yang tidak berhubungan tidak disarankan
        $this->assertSame([$this->vale->id], NamaInstansi::saran('PT. Vale')->pluck('id')->all());
        $this->assertTrue(NamaInstansi::saran('Dinas Kesehatan')->isEmpty());
        $this->assertSame($this->vale->id, NamaInstansi::sama('pt vale indonesia tbk.')?->id);
        $this->assertNull(NamaInstansi::sama('PT. Vale'));
    }

    public function test_IG02_nama_mirip_tidak_ditaut_otomatis_dan_harus_dipilih(): void
    {
        $r = $this->ajukan('PT. Vale');
        $this->assertNull($r->institution_id);
        $this->assertSame('Jumlah peserta: 2 orang
Instansi: PT. Vale', $r->special_request);
        // Tamu tidak pernah melihat daftar instansi terdaftar
        $this->get(route('instansi'))->assertDontSee('PT Vale Indonesia Tbk');

        // Nama yang normalisasinya sama persis langsung tertaut
        $this->assertSame($this->vale->id, $this->ajukan('PT. Vale Indonesia', 'Citra Lestari', '081355500303')->institution_id);

        $this->dialog($r)
            ->assertSee('Nama yang ditulis pemohon:')->assertSee('“PT. Vale”', false)
            ->assertSee('Mungkin:')->assertSee('PT Vale Indonesia Tbk')->assertSee('narahubung utama Andi Saputra')
            ->assertSet('instansi', '')->assertSet('pilihan', '')
            ->call('setujui')->assertHasErrors(['instansi'])->assertSee('Pilih instansi terdaftar atau buat instansi baru.');
        $this->assertSame('waiting_approval', $r->fresh()->status);
    }

    public function test_IG03_instansi_baru_dengan_nama_terdaftar_ditolak(): void
    {
        $r = $this->ajukan('Vale');
        $this->dialog($r)->set('pilihan', 'baru')->assertSet('namaBaru', 'Vale')
            ->set('namaBaru', 'pt vale indonesia tbk.')
            ->call('setujui')->assertHasErrors(['namaBaru'])->assertSee('Sudah terdaftar sebagai PT Vale Indonesia Tbk. Pilih dari daftar.');
        $this->assertSame(1, Institution::count());
    }

    public function test_IG04_pakai_instansi_terdaftar_narahubung_b_tanpa_mengganti_utama(): void
    {
        $r = $this->ajukan('PT. Vale');
        $this->dialog($r)->set('pilihan', 'terdaftar')->set('cariInstansi', 'vale')->assertSee('PT Vale Indonesia Tbk')
            ->call('pakaiInstansi', $this->vale->id)->assertSet('instansi', (string) $this->vale->id)
            // Vale sudah punya narahubung utama aktif: tidak dicentang otomatis
            ->assertSet('jadikanUtama', false)->assertSee('Jadikan Budi Hartono narahubung utama (tujuan tagihan)')
            ->call('setujui')->assertHasNoErrors();

        $r->refresh();
        $b = $r->customer;
        $this->assertSame(['confirmed', $this->vale->id], [$r->status, $r->institution_id]);
        $this->assertSame($this->vale->id, $b->institution_id);
        $this->assertSame($this->a->id, $this->vale->fresh()->primary_contact_id);

        // Pengajuan berikutnya dari B dengan centang: B menjadi narahubung utama
        $r2 = $this->ajukan('PT Vale Indonesia Tbk');
        $this->dialog($r2, '107')->assertSet('instansi', (string) $this->vale->id)->set('jadikanUtama', true)->call('setujui')->assertHasNoErrors();
        $this->assertSame($b->id, $this->vale->fresh()->primary_contact_id);

        // Narahubung utama nonaktif: centang otomatis untuk pemohon baru
        $this->a->update(['is_active' => false]);
        $this->vale->fresh()->update(['primary_contact_id' => $this->a->id]);
        $r3 = $this->ajukan('PT Vale Indonesia Tbk', 'Citra Lestari', '081355500303');
        $this->dialog($r3, '108')->assertSet('jadikanUtama', true);
    }

    public function test_IG05_narahubung_instansi_lain_diberi_peringatan_tidak_dipindah_otomatis(): void
    {
        $dinkes = Institution::create(['code' => 'DINKES', 'name' => 'Dinas Kesehatan Luwu Timur', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $b = Customer::create(['full_name' => 'Budi Hartono', 'phone' => '081355500202', 'institution_id' => $dinkes->id, 'is_active' => true]);

        $r = $this->ajukan('PT. Vale');
        // Instansi narahubung tidak dipakai untuk pengajuan dari situs
        $this->assertNull($r->institution_id);
        $this->dialog($r)->call('pakaiInstansi', $this->vale->id)
            ->assertSee('Nomor ini tercatat sebagai narahubung Dinas Kesehatan Luwu Timur')
            ->assertSee('Pindahkan ke PT Vale Indonesia Tbk')->assertSet('pindahNarahubung', 'biarkan')
            ->assertDontSee('data-jadikan-utama', false)
            ->call('setujui')->assertHasNoErrors();

        $this->assertSame($this->vale->id, $r->fresh()->institution_id);
        $this->assertSame($dinkes->id, $b->fresh()->institution_id);

        // Dipindah hanya bila dipilih
        $r2 = $this->ajukan('PT. Vale');
        $this->dialog($r2, '107')->call('pakaiInstansi', $this->vale->id)->set('pindahNarahubung', 'pindah')->call('setujui')->assertHasNoErrors();
        $this->assertSame($this->vale->id, $b->fresh()->institution_id);

        // Narahubung utama instansi lain tidak bisa dipindah sebelum diganti
        $c = Customer::create(['full_name' => 'Citra Lestari', 'phone' => '081355500303', 'institution_id' => $dinkes->id, 'is_active' => true]);
        $dinkes->update(['primary_contact_id' => $c->id]);
        $r3 = $this->ajukan('PT. Vale', 'Citra Lestari', '081355500303');
        $this->dialog($r3, '108')->call('pakaiInstansi', $this->vale->id)->set('pindahNarahubung', 'pindah')
            ->call('setujui')->assertHasErrors(['pindahNarahubung']);
        $this->assertSame($dinkes->id, $c->fresh()->institution_id);
    }

    public function test_IG06_gabungkan_instansi_duplikat(): void
    {
        $ganda = Institution::create(['name' => 'PT. Vale', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $b = Customer::create(['full_name' => 'Budi Hartono', 'phone' => '081355500202', 'institution_id' => $ganda->id, 'is_active' => true]);
        $ganda->update(['primary_contact_id' => $b->id]);
        $r = app(ReservasiService::class)->ajukanInstansi($b, $ganda, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-14'), 1, null, null);
        $nota = fn (Customer $c, Institution $i, float $n) => Invoice::create(['invoice_number' => Nomor::nota(), 'invoice_type' => 'service', 'customer_id' => $c->id,
            'institution_id' => $i->id, 'payment_term' => 'credit', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-20', 'subtotal' => $n, 'total_amount' => $n, 'status' => 'issued']);
        $notaGanda = $nota($b, $ganda, 500000);
        $nota($this->a, $this->vale, 300000);
        $tagihan = DB::table('billing_statements')->insertGetId(['statement_number' => 'TB-UJI-1', 'institution_id' => $ganda->id, 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'due_date' => '2026-10-15', 'total_amount' => 200000, 'status' => 'issued']);
        DB::table('billing_reminders')->insert(['invoice_id' => $notaGanda->id, 'reminder_stage' => 'internal', 'scheduled_date' => '2026-10-18', 'channel' => 'email', 'status' => 'pending']);
        $piutang = fn () => app(PiutangService::class)->sisaPerInstansi();
        $this->assertSame(700000.0, $piutang()[$ganda->id]);
        $this->assertSame(300000.0, $piutang()[$this->vale->id]);

        $layar = Livewire::actingAs($this->akun('manager'))->test(InstansiPelanggan::class)->call('open', $ganda->id)
            ->call('mulaiGabung')->assertSee('Mungkin:')->assertSee('PT Vale Indonesia Tbk')
            ->call('pilihTujuan', $this->vale->id)->assertSee('Yang dipindah ke PT Vale Indonesia Tbk:');
        $this->assertSame(['reservasi' => 1, 'narahubung' => 1, 'nota' => 1, 'tagihan' => 1, 'pengingat' => 1, 'aula' => 0], $layar->viewData('ringkasanGabung'));
        // Tidak bisa ke dirinya sendiri
        $layar->call('pilihTujuan', $ganda->id)->assertHasErrors(['tujuan'])->assertSee('Instansi tidak bisa digabung ke dirinya sendiri.');

        Livewire::actingAs($this->akun('manager'))->test(InstansiPelanggan::class)->call('open', $ganda->id)
            ->call('mulaiGabung')->call('pilihTujuan', $this->vale->id)->call('gabungkan')->assertHasNoErrors()
            ->assertSet('instansiId', $this->vale->id);

        $this->assertNull(Institution::find($ganda->id));
        $this->assertSame($this->vale->id, $r->fresh()->institution_id);
        $this->assertSame($this->vale->id, $b->fresh()->institution_id);
        $this->assertSame($this->vale->id, $notaGanda->fresh()->institution_id);
        $this->assertSame($this->vale->id, DB::table('billing_statements')->where('id', $tagihan)->value('institution_id'));
        // Narahubung utama tujuan tetap A; total piutang tujuan = gabungan
        $this->assertSame($this->a->id, $this->vale->fresh()->primary_contact_id);
        $this->assertSame(1000000.0, $piutang()[$this->vale->id]);

        // Hanya Manager
        $this->actingAs($this->akun('fo'))->get(route('staf.pelanggan.instansi'))->assertForbidden();
    }

    public function test_IG07_tamu_b_tidak_melihat_reservasi_narahubung_a(): void
    {
        $akunB = $this->akun('customer');
        $b = $akunB->customer ?? Customer::create(['user_id' => $akunB->id, 'full_name' => 'Budi Hartono', 'phone' => '081355500202', 'is_active' => true]);
        $b->update(['institution_id' => $this->vale->id]);
        $layanan = app(ReservasiService::class);
        $milikA = $layanan->ajukanInstansi($this->a, $this->vale, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-14'), 2, 'Rapat A', null);
        $milikB = $layanan->ajukanInstansi($b, $this->vale, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-22'), 1, 'Rapat B', null);

        $this->actingAs($akunB)->get(route('pesanan'))->assertOk()
            ->assertSee($milikB->reservation_code)->assertDontSee($milikA->reservation_code);
        // Halaman status tanpa token milik orang lain tidak bisa dibuka; cek pesanan dengan nomor B tidak membuka milik A
        $this->actingAs($akunB)->get(route('reservasi.status', ['kode' => $milikA->reservation_code]))->assertNotFound();
        $this->actingAs($akunB)->get(route('reservasi.status', ['kode' => $milikB->reservation_code]))->assertOk();
        $this->post(route('cek.cari'), ['kode' => $milikA->reservation_code, 'kunci' => $b->phone])->assertSessionHasErrors();
    }
}
