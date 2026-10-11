<?php

namespace Tests\Feature;

use App\Livewire\Staf\Keuangan\Nota;
use App\Livewire\Staf\Keuangan\PengingatPenagihan as HalamanPengingat;
use App\Livewire\Staf\Keuangan\PiutangInstansi;
use App\Livewire\Staf\Keuangan\TagihanBulanan;
use App\Models\BillingReminder;
use App\Models\BillingStatement;
use App\Models\Customer;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\PesanReservasi;
use App\Services\AulaService;
use App\Services\CheckOutService;
use App\Services\FolioService;
use App\Services\NotaService;
use App\Services\PembayaranService;
use App\Services\PengingatPenagihan;
use App\Services\PiutangService;
use App\Services\ReservasiService;
use App\Services\TagihanBulananService;
use App\Support\Nomor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Penagihan instansi: piutang, tagihan bulanan, nota aula dan periode menginap panjang, serta pengingat dua tahap. */
class PenagihanInstansiTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->siapkanSettingTagihan();
        Carbon::setTestNow('2026-10-06 15:00:00');
        $this->withoutVite();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        User::whereKey(2)->update(['phone' => '081100000002']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- Bantuan ---------------- */

    private function instansi(string $skema, ?int $tanggalTagih = null, ?int $jatuhTempo = null, string $nama = 'Dinas PU Luwu Timur'): Institution
    {
        $n = ++$this->urut;
        $i = Institution::create(['name' => $nama, 'address' => 'Jl. Poros Malili No. '.$n, 'email' => "kantor{$n}@instansi.test",
            'default_billing_scheme' => $skema, 'billing_day' => $tanggalTagih, 'due_days' => $jatuhTempo]);
        $pic = Customer::create(['full_name' => 'Andi Pratama '.$n, 'phone' => '08134500000'.$n, 'email' => "pic{$n}@instansi.test", 'institution_id' => $i->id]);
        $i->update(['primary_contact_id' => $pic->id]);

        return $i->fresh('primaryContact');
    }

    private function notaKredit(Institution $i, float $total, string $terbit, ?string $jatuhTempo = null, string $term = 'credit'): Invoice
    {
        $n = Invoice::create(['invoice_number' => Nomor::nota(), 'invoice_type' => 'service', 'customer_id' => $i->primary_contact_id,
            'institution_id' => $i->id, 'payment_term' => $term, 'issue_date' => $terbit, 'due_date' => $jatuhTempo, 'status' => 'issued', 'note' => 'Konsumsi rapat']);
        $n->items()->create(['item_type' => 'fnb', 'description' => 'Konsumsi rapat', 'service_date' => $terbit, 'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total]);

        return app(NotaService::class)->hitungUlang($n);
    }

    /** Reservasi instansi Rp300.000/malam yang langsung check-in dengan bukti (waktu uji harus sudah lewat 14.00). */
    private function menginap(Institution $i, string $masuk, string $keluar, string $kamar = '107'): Reservation
    {
        $layanan = app(ReservasiService::class);
        $r = $layanan->ajukanInstansi($i->primaryContact, $i, CarbonImmutable::parse($masuk), CarbonImmutable::parse($keluar), 1, 'Proyek jaringan', null);
        $layanan->setujui($r, $i, [['room_id' => $this->kamar($kamar)->id, 'price' => 300000]], null, 'satu_nota', null, $this->akun('manager'));
        $layanan->checkIn($r->fresh(), [], false, null, $this->akun('fo'), $r->fresh()->access_token);

        return $r->fresh();
    }

    private function aula(Institution $i, string $mulai, string $selesai, string $status = 'confirmed'): HallBooking
    {
        $hall = Hall::firstOrCreate(['name' => 'Aula Utama'], ['capacity' => 150, 'is_active' => true]);

        return HallBooking::create(['booking_code' => 'HB-'.str_pad((string) ++$this->urut, 4, '0', STR_PAD_LEFT), 'hall_id' => $hall->id,
            'customer_id' => $i->primary_contact_id, 'start_at' => $mulai, 'end_at' => $selesai, 'participant_count' => 60,
            'rental_price' => 2500000, 'purpose' => 'Rapat koordinasi', 'status' => $status]);
    }

    private function harian(): void
    {
        Artisan::call('penagihan:harian');
    }

    /* ---------------- 1. Overdue dan umur piutang ---------------- */

    public function test_P01_overdue_dan_umur_piutang(): void
    {
        $i = $this->instansi('due_after_checkout', jatuhTempo: 14);
        $lima = $this->notaKredit($i, 400000, '2026-09-20', '2026-10-01');
        $lama = $this->notaKredit($i, 900000, '2026-07-15', '2026-08-01');
        $belum = $this->notaKredit($i, 250000, '2026-10-05', '2026-10-20');

        $this->harian();
        $this->assertSame(['overdue', 'overdue', 'issued'], [$lima->fresh()->status, $lama->fresh()->status, $belum->fresh()->status]);

        $t = app(PiutangService::class)->tagihanTerbuka($i->id)->keyBy('nomor');
        $this->assertSame([5, '1-30'], [$t[$lima->invoice_number]['umur'], $t[$lima->invoice_number]['kelompok']]);
        $this->assertSame([66, '60+'], [$t[$lama->invoice_number]['umur'], $t[$lama->invoice_number]['kelompok']]);
        $this->assertSame([0, 'belum'], [$t[$belum->invoice_number]['umur'], $t[$belum->invoice_number]['kelompok']]);
        $per = app(PiutangService::class)->perInstansi()->first();
        $this->assertSame([1550000.0, 3, 66], [$per['sisa'], $per['jumlah'], $per['terlama']]);
        $this->assertSame(['belum' => 250000.0, '1-30' => 400000.0, '31-60' => 0.0, '60+' => 900000.0], $per['umur']);

        // Dibayar sebagian tetap overdue (penyaring Nota dan kartu Dashboard)
        app(PembayaranService::class)->catatManual($lima->fresh(), 100000, 'bank_transfer', 'service', $this->akun('manager'));
        $this->assertSame(['overdue', 100000.0], [$lima->fresh()->status, (float) $lima->fresh()->paid_amount]);
        $this->actingAs($this->akun('manager'))->get(route('staf.dashboard'))->assertSee('Total piutang instansi')->assertSee('Rp1.450.000');
    }

    /* ---------------- 2. Penyusunan tagihan bulanan ---------------- */

    public function test_P02_penyusunan_draft_bulanan(): void
    {
        $a = $this->instansi('monthly_fixed_date', 25);
        $b = $this->instansi('monthly_fixed_date', 25, nama: 'Dinas Kesehatan');
        $susulan = $this->notaKredit($a, 300000, '2026-09-20');
        $periodeIni = $this->notaKredit($a, 200000, '2026-10-10');
        $this->notaKredit($a, 50000, '2026-10-26');
        $this->notaKredit($a, 70000, '2026-10-10', term: 'immediate');
        $this->notaKredit($b, 90000, '2026-10-10');

        Carbon::setTestNow('2026-10-26 00:10:00');
        $p = $a->periodeTagihan(today());
        $p = $a->periodeTagihan($p['mulai']->subDay());
        $s = app(TagihanBulananService::class)->susun($a, $p);
        $this->assertSame(['BS/2026/10/0001', 'draft', '2026-09-26', '2026-10-25', 500000.0],
            [$s->statement_number, $s->status, $s->period_start->toDateString(), $s->period_end->toDateString(), (float) $s->total_amount]);
        $this->assertEqualsCanonicalizing([$susulan->id, $periodeIni->id], $s->invoices()->pluck('id')->all());

        try {
            app(TagihanBulananService::class)->susun($a, $p);
            $this->fail('Draft dobel untuk periode yang sama seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('Tagihan bulanan periode ini sudah ada: BS/2026/10/0001.', $e->errors()['periode'][0]);
        }
        // Perintah harian menyusun draft instansi lain, dan dijalankan dua kali tidak membuat dobel
        $this->harian();
        $this->harian();
        $this->assertSame(2, BillingStatement::count());
        $this->assertSame(90000.0, (float) BillingStatement::where('institution_id', $b->id)->sole()->total_amount);

        // Tanggal potong 31 di Februari memakai tanggal terakhir bulan; tanpa nota tidak ada draft
        $c = $this->instansi('monthly_fixed_date', 31, nama: 'Kantor Camat Malili');
        $pf = $c->periodeTagihan(CarbonImmutable::parse('2027-02-10'));
        $this->assertNull(app(TagihanBulananService::class)->susun($c, $pf));
        $this->notaKredit($c, 120000, '2027-02-05');
        Carbon::setTestNow('2027-03-01 00:10:00');
        $sf = app(TagihanBulananService::class)->susun($c, $pf);
        $this->assertSame(['2027-02-01', '2027-02-28'], [$sf->period_start->toDateString(), $sf->period_end->toDateString()]);

        // Halaman: susun sekarang untuk periode yang sudah ada ditolak dengan pesan
        Livewire::actingAs($this->akun('manager'))->test(TagihanBulanan::class)->call('bukaSusun')
            ->set('susunInstansi', (string) $a->id)->set('susunTanggal', '2026-10-10')->call('susun')->assertHasErrors('periode');
    }

    /* ---------------- 3. Penerbitan ---------------- */

    public function test_P03_terbitkan_draft_dan_kirim_pdf(): void
    {
        $a = $this->instansi('monthly_fixed_date', 25);
        $this->notaKredit($a, 300000, '2026-10-10');
        Carbon::setTestNow('2026-10-26 00:10:00');
        $this->harian();
        $s = BillingStatement::sole();
        Carbon::setTestNow('2026-10-27 09:00:00');

        Livewire::actingAs($this->akun('manager'))->test(TagihanBulanan::class)->call('open', $s->id)->assertSee('Terbitkan dan kirim')
            ->call('terbitkan')->assertHasNoErrors();
        $s->refresh();
        $this->assertSame(['issued', '2026-10-27', '2026-11-10'], [$s->status, $s->issue_date->toDateString(), $s->due_date->toDateString()]);
        Notification::assertSentOnDemand(PesanReservasi::class, fn ($n, $kanal, $tujuan) => $tujuan->routes['mail'] === $a->primaryContact->email);
        Http::assertSent(fn (Request $q) => str_contains((string) $q['target'], substr($a->primaryContact->phone, 1))
            && str_contains($q['message'], 'BS/2026/10/0001') && str_contains($q['message'], '/tagihan-bulanan/'));
    }

    /* ---------------- 4. Pembayaran tagihan bulanan ---------------- */

    public function test_P04_pembayaran_bulanan_dibagi_ke_nota_terlama(): void
    {
        $a = $this->instansi('monthly_fixed_date', 25);
        $lama = $this->notaKredit($a, 300000, '2026-10-01');
        $baru = $this->notaKredit($a, 200000, '2026-10-10');
        Carbon::setTestNow('2026-10-26 08:00:00');
        $this->harian();
        $s = BillingStatement::sole();
        app(TagihanBulananService::class)->terbitkan($s, $this->akun('manager'));

        app(PembayaranService::class)->catatTagihanBulanan($s->fresh(), 350000, 'bank_transfer', $this->akun('manager'), 'Ref: TRF 0912');
        $s->refresh();
        $this->assertSame(['partially_paid', 350000.0], [$s->status, (float) $s->paid_amount]);
        $this->assertSame(['paid', 300000.0], [$lama->fresh()->status, (float) $lama->fresh()->paid_amount]);
        $this->assertSame(['partially_paid', 50000.0], [$baru->fresh()->status, (float) $baru->fresh()->paid_amount]);
        $bayar = $s->payments()->sole();
        $this->assertSame([null, 'settlement'], [$bayar->invoice_id, $bayar->payment_purpose]);

        // Nota anggota dibaca dari paid_amount (tidak punya payment sendiri)
        Livewire::actingAs($this->akun('manager'))->test(Nota::class)->call('open', $lama->id)->assertSee('Lunas')->assertSee('Rp300.000')
            ->assertSee('Bagian dari tagihan bulanan BS/2026/10/0001.');
        Livewire::actingAs($this->akun('manager'))->test(PiutangInstansi::class)->call('open', $a->id)
            ->assertSee('BS/2026/10/0001')->assertSee('Rp150.000')->assertDontSee($lama->invoice_number)->assertSee('Ref: TRF 0912');

        Livewire::actingAs($this->akun('manager'))->test(PiutangInstansi::class)->call('open', $a->id)
            ->call('mulaiBayar', 'bulanan', $s->id)->assertSet('jumlah', '150000')->set('metode', 'cash')->call('bayar')->assertHasNoErrors();
        $this->assertSame('paid', $s->fresh()->status);
        $this->assertSame(['paid', 'paid'], [$lama->fresh()->status, $baru->fresh()->status]);
    }

    /* ---------------- 5. Nota sewa aula instansi ---------------- */

    public function test_P05_nota_aula_instansi_tepat_sekali_dan_upfront_sebelum_acara(): void
    {
        $jt = $this->instansi('due_after_checkout', jatuhTempo: 10);
        $selesai = $this->aula($jt, '2026-10-03 08:00:00', '2026-10-03 16:00:00', 'completed');
        $nanti = $this->aula($jt, '2026-10-20 08:00:00', '2026-10-20 16:00:00');
        $bulanan = $this->instansi('monthly_fixed_date', 25, nama: 'Dinas Kesehatan');
        $aulaBulanan = $this->aula($bulanan, '2026-10-04 08:00:00', '2026-10-04 12:00:00');

        $this->harian();
        $this->harian();
        $nota = Invoice::find($selesai->fresh()->invoice_id);
        $this->assertSame(['hall', 'credit', $jt->id, '2026-10-13', 2500000.0],
            [$nota->invoice_type, $nota->payment_term, $nota->institution_id, $nota->due_date->toDateString(), (float) $nota->total_amount]);
        $this->assertNull($nanti->fresh()->invoice_id);
        $this->assertSame(['credit', null], [$aulaBulanan->fresh()->invoice->payment_term, $aulaBulanan->fresh()->invoice->due_date]);
        $this->assertSame(2, Invoice::where('invoice_type', 'hall')->count());

        // Bayar di muka: nota langsung begitu disetujui, jatuh tempo sehari sebelum acara
        $muka = $this->instansi('upfront', nama: 'PT Vale Indonesia');
        $hall = Hall::firstOrCreate(['name' => 'Aula Utama'], ['capacity' => 150, 'is_active' => true]);
        $b = app(AulaService::class)->ajukan($muka->primaryContact, $hall, CarbonImmutable::parse('2026-10-24 08:00'), CarbonImmutable::parse('2026-10-24 15:00'), 80, 'Pelatihan', null);
        app(AulaService::class)->setujui($b, 3000000, $muka, null, $this->akun('manager'));
        $n = $b->fresh()->invoice;
        $this->assertSame(['immediate', '2026-10-23', $muka->id, 3000000.0], [$n->payment_term, $n->due_date->toDateString(), $n->institution_id, (float) $n->total_amount]);
        $this->harian();
        $this->assertSame(1, Invoice::where('institution_id', $muka->id)->count());
    }

    /* ---------------- 6. Menginap panjang ---------------- */

    public function test_P06_nota_periode_menginap_panjang_tidak_ditagih_ulang(): void
    {
        $i = $this->instansi('monthly_fixed_date', 10);
        $r = $this->menginap($i, '2026-10-06', '2026-10-20');

        Carbon::setTestNow('2026-10-10 00:10:00');
        $this->harian();
        $this->assertSame(0, app(FolioService::class)->notaPeriode($r)->count(), 'Periode belum berakhir.');

        Carbon::setTestNow('2026-10-11 00:10:00');
        $this->harian();
        $this->harian();
        $periode = app(FolioService::class)->notaPeriode($r);
        $this->assertCount(1, $periode);
        $n = $periode->first();
        $this->assertSame(['credit', '2026-09-11', '2026-10-10', 1500000.0, 5.0],
            [$n->payment_term, $n->period_start->toDateString(), $n->period_end->toDateString(), (float) $n->total_amount, (float) $n->items()->sole()->quantity]);

        $f = app(FolioService::class)->rincian($r->fresh());
        $this->assertSame(2700000.0, (float) $f['kamar']->sum('subtotal'));
        $this->assertSame([$n->invoice_number], $f['periode']->pluck('nomor')->all());

        Carbon::setTestNow('2026-10-20 10:00:00');
        app(CheckOutService::class)->proses($r->fresh(), [$r->rooms->first()->id], [], $this->akun('fo'));
        $akhir = app(FolioService::class)->notaMenginap($r->fresh());
        $this->assertSame(['Kamar 107, 9 malam (11–20 Okt 2026)'], $akhir->items()->where('item_type', 'room')->pluck('description')->all());
        $this->assertSame([2700000.0, 1500000.0], [(float) $akhir->total_amount, (float) $n->fresh()->total_amount]);
    }

    public function test_P06b_perintah_terlewat_tiga_hari_tetap_menyusul(): void
    {
        $i = $this->instansi('monthly_fixed_date', 10);
        $r = $this->menginap($i, '2026-10-06', '2026-10-20');
        $sudahKeluar = $this->menginap($i, '2026-10-06', '2026-10-15', '108');
        Carbon::setTestNow('2026-10-12 10:00:00');
        app(CheckOutService::class)->proses($sudahKeluar, [$sudahKeluar->rooms->first()->id], [], $this->akun('fo'));

        // Penjadwal mati 11–13 Okt; baru jalan 14 Okt
        Carbon::setTestNow('2026-10-14 00:10:00');
        $this->harian();
        $this->assertSame(['2026-09-11'], app(FolioService::class)->notaPeriode($r)->map(fn ($n) => $n->period_start->toDateString())->all());
        $this->assertCount(0, app(FolioService::class)->notaPeriode($sudahKeluar->fresh()));
    }

    /* ---------------- 7. Pengingat ---------------- */

    public function test_P07_pengingat_dua_tahap_surat_tindak_lanjut_dan_pelunasan(): void
    {
        Carbon::setTestNow('2026-10-06 07:30:00');
        $i = $this->instansi('due_after_checkout', jatuhTempo: 14);
        $n = $this->notaKredit($i, 12400000, '2026-09-25', '2026-10-09');
        $this->notaKredit($i, 500000, '2026-10-01', '2026-10-30');

        $pengingat = app(PengingatPenagihan::class);
        $pengingat->jalankan();
        $pengingat->jalankan();
        $internal = BillingReminder::where('reminder_stage', 'internal')->get();
        $this->assertEqualsCanonicalizing(['email', 'whatsapp'], $internal->pluck('channel')->all());
        $this->assertSame(['sent', 'sent'], $internal->pluck('status')->all());
        Http::assertSent(fn (Request $q) => str_contains((string) $q['target'], '81100000002') && str_contains($q['message'], '1 tagihan instansi menjelang jatuh tempo')
            && str_contains($q['message'], $n->invoice_number.' Dinas PU Luwu Timur Rp12.400.000, jatuh tempo 9 Okt'));
        Notification::assertSentTo($this->akun('manager'), PesanReservasi::class);
        $pending = BillingReminder::where('reminder_stage', 'to_institution')->sole();
        $this->assertSame(['pending', $n->id, 'whatsapp'], [$pending->status, $pending->invoice_id, $pending->channel]);
        // Belum diperiksa Manager: belum ada pesan ke instansi
        Http::assertNotSent(fn (Request $q) => str_contains((string) $q['target'], substr($i->primaryContact->phone, 1)));

        $layar = Livewire::actingAs($this->akun('manager'))->test(HalamanPengingat::class)->assertSee($n->invoice_number)
            ->call('open', $pending->id)->assertSee('Cetak surat tagihan')->assertSee('Rp12.400.000')
            ->set('kirimWa', true)->set('kirimEmail', true)->call('kirim')->assertHasNoErrors();
        $kirim = BillingReminder::where('reminder_stage', 'to_institution')->orderBy('id')->get();
        $this->assertSame([['whatsapp', 'sent'], ['email', 'sent']], $kirim->map(fn ($b) => [$b->channel, $b->status])->all());
        $this->assertSame([2, 2], $kirim->pluck('verified_by')->all());
        Http::assertSent(fn (Request $q) => str_contains((string) $q['target'], substr($i->primaryContact->phone, 1)) && str_contains($q['message'], $n->invoice_number));
        Notification::assertSentOnDemand(PesanReservasi::class, fn ($x, $k, $tujuan) => $tujuan->routes['mail'] === $i->primaryContact->email);

        // Surat untuk tagihan di atas ambang
        $pengingat->suratTerkirim($n->fresh(), $this->akun('manager'));
        $this->assertSame('sent', BillingReminder::where('channel', 'letter')->sole()->status);
        $this->get(route('staf.keuangan.surat-tagihan', ['jenis' => 'nota', 'id' => $n->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Tindak lanjut: lewat jatuh tempo, pengingat baru setiap 7 hari sejak yang terakhir
        Carbon::setTestNow('2026-10-12 07:30:00');
        $pengingat->jalankan();
        $this->assertSame(0, BillingReminder::where('reminder_stage', 'to_institution')->where('status', 'pending')->count());
        Carbon::setTestNow('2026-10-13 07:30:00');
        $pengingat->jalankan();
        $baru = BillingReminder::where('reminder_stage', 'to_institution')->where('status', 'pending')->sole();
        $this->assertSame('2026-10-13', $baru->scheduled_date->toDateString());

        // Lunas: pengingat yang masih menunggu batal
        app(PembayaranService::class)->catatManual($n->fresh(), 12400000, 'bank_transfer', 'service', $this->akun('manager'));
        $this->assertSame('cancelled', $baru->fresh()->status);
    }

    public function test_P07b_di_bawah_ambang_tanpa_surat(): void
    {
        $i = $this->instansi('due_after_checkout', jatuhTempo: 14);
        $this->notaKredit($i, 800000, '2026-09-25', '2026-10-08');
        app(PengingatPenagihan::class)->jalankan();
        $p = BillingReminder::where('reminder_stage', 'to_institution')->sole();
        Livewire::actingAs($this->akun('manager'))->test(HalamanPengingat::class)->call('open', $p->id)->assertDontSee('Cetak surat tagihan')
            ->set('tanggalTunda', '2026-10-09')->call('tunda')->assertHasNoErrors();
        $this->assertSame('2026-10-09', $p->fresh()->scheduled_date->toDateString());
        Livewire::actingAs($this->akun('manager'))->test(HalamanPengingat::class)->assertDontSee('Periksa &amp; kirim', false)
            ->set('tab', 'tertunda')->assertSee('9 Okt 2026');
    }

    /* ---------------- 8. Hak akses dan tautan PDF ---------------- */

    public function test_P08_hanya_manager_dan_tautan_pdf_bertanda_tangan(): void
    {
        foreach (['fo', 'fnb', 'hk'] as $akun) {
            foreach (['staf.keuangan.piutang', 'staf.keuangan.tagihan-bulanan', 'staf.keuangan.pengingat'] as $rute) {
                $this->actingAs($this->akun($akun))->get(route($rute))->assertForbidden();
            }
        }
        foreach (['staf.keuangan.piutang', 'staf.keuangan.tagihan-bulanan', 'staf.keuangan.pengingat'] as $rute) {
            $this->actingAs($this->akun('manager'))->get(route($rute))->assertOk();
        }

        $a = $this->instansi('monthly_fixed_date', 25);
        $this->notaKredit($a, 300000, '2026-10-01');
        Carbon::setTestNow('2026-10-26 08:00:00');
        $this->harian();
        $s = BillingStatement::sole();
        app(TagihanBulananService::class)->terbitkan($s, $this->akun('manager'));

        auth()->logout();
        $tautan = app(TagihanBulananService::class)->tautan($s->fresh());
        $this->get($tautan)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('tagihan-bulanan.unduh', ['tagihan' => $s->id]))->assertForbidden();
        $this->get(route('staf.keuangan.tagihan-bulanan.pdf', $s))->assertRedirect();
    }
}
