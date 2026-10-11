<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\RoomStatusLog;
use App\Notifications\PesanReservasi;
use App\Services\Reservasi\BuktiInstansi;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Bukti pemesanan instansi berupa kode bukti (= token akses, LK7Q-2M9X) yang dikirim ke PIC saat disetujui.
 * Reservasi instansi hanya bisa check-in setelah kode bukti diverifikasi. Waktu acuan 6 Okt 2026 15.00 WITA.
 */
class BuktiInstansiTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private const HP_PIC = '081455500006';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        // Setelah data contoh (yang memasang 10.00): sudah lewat jam check-in 14.00
        Carbon::setTestNow('2026-10-06 15:00:00');
        $this->withoutVite();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Pengajuan dari situs, lalu disetujui Manager dengan dua kamar. */
    private function disetujui(string $masuk = '2026-10-06', string $keluar = '2026-10-08', array $kamar = ['106', '107']): Reservation
    {
        $this->post(route('instansi.store'), [
            'instansi' => 'PT PLN UP3 Palopo', 'name' => 'Fajar Nugroho', 'phone' => self::HP_PIC, 'email' => 'fajar@pln.test',
            'masuk' => $masuk, 'keluar' => $keluar, 'jumlah' => count($kamar), 'kegiatan' => 'Pemeliharaan jaringan',
        ])->assertRedirect();
        $r = Reservation::latest('id')->first();

        Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)
            ->call('open', $r->id, 'setuju')
            ->set('skema', 'due_after_checkout')->set('jatuhTempo', '7')
            ->set('kamar', array_map(fn ($n) => (string) $this->kamar($n)->id, $kamar))
            ->call('setujui')->assertHasNoErrors();

        return $r->fresh();
    }

    /** @return list<string> isi pesan WhatsApp ke PIC */
    private function pesanWaPic(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $q) => str_contains((string) ($q['target'] ?? ''), substr(self::HP_PIC, 1)))
            ->map(fn (Request $q) => (string) $q['message'])->values()->all();
    }

    private function checkIn()
    {
        return Livewire::actingAs($this->akun('fo'))->test(CheckIn::class);
    }

    public function test_B1_persetujuan_mengirim_kode_bukti_lewat_whatsapp_dan_email(): void
    {
        $r = $this->disetujui();
        $this->assertSame($r->token(), $r->kodeBukti());
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $r->kodeBukti());

        $wa = collect($this->pesanWaPic())->last();
        $this->assertStringContainsString("pengajuan reservasi {$r->reservation_code} disetujui", $wa);
        $this->assertStringContainsString("Kode bukti: {$r->kodeBukti()}", $wa);
        $this->assertStringContainsString('/bukti-instansi/'.$r->reservation_code.'?expires=', $wa);
        $this->assertStringContainsString('Teruskan kode ini kepada staf yang ditugaskan menginap. Sebutkan atau tunjukkan di resepsionis saat kedatangan.', $wa);
        $this->assertStringNotContainsString('QR', $wa);

        Notification::assertSentOnDemand(PesanReservasi::class, fn ($n, $ch, $tujuan) => $tujuan->routes['mail'] === 'fajar@pln.test'
            && $n->lampiran && $n->lampiran[1] === "Bukti-{$r->reservation_code}.pdf" && str_starts_with($n->lampiran[0], '%PDF')
            && collect($n->baris)->contains("Kode bukti: {$r->kodeBukti()}. Bukti pemesanan kami lampirkan dalam bentuk PDF."));
    }

    public function test_B2_pdf_lewat_tautan_bertanda_tangan_dan_halaman_status_satu_kode(): void
    {
        $r = $this->disetujui();

        $this->get(url('/bukti-instansi/'.$r->reservation_code))->assertForbidden();
        $this->get(app(BuktiInstansi::class)->tautan($r))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $html = view('pdf.bukti-instansi', ['r' => $r, 'jamCheckIn' => '14.00'])->render();
        $this->assertStringContainsString($r->kodeBukti(), $html);
        $this->assertStringContainsString('Teruskan kode ini kepada staf yang ditugaskan menginap.', $html);
        $this->assertStringNotContainsString('<img src="data:', $html);

        // Halaman status: satu kode berlabel "Kode bukti", tanpa baris Token dan tanpa QR
        $halaman = $this->get($r->urlStatus())->assertOk()->assertSee('Kode bukti')->assertSee('Unduh bukti (PDF)')
            ->assertDontSee('· Token')->assertDontSee('data:image/png', false);
        $this->assertSame(1, substr_count($halaman->getContent(), $r->kodeBukti()));
    }

    public function test_B3_kode_bukti_diterima_dengan_atau_tanpa_tanda_hubung(): void
    {
        $r = $this->disetujui();
        $sah = "Bukti sah: {$r->reservation_code} · PT PLN UP3 Palopo · 2 kamar · 6–8 Okt 2026";

        foreach ([$r->kodeBukti(), strtolower($r->kodeBukti()), strtolower(str_replace('-', '', $r->kodeBukti()))] as $kode) {
            $this->checkIn()->set('kodeBukti', $kode)->call('verifikasi')->assertSee($sah)->assertSet('buktiUntuk', $r->id);
        }
        // Kode salah atau terlalu pendek ditolak
        foreach (['ZZZZ-ZZZZ', substr($r->access_token, 0, 6)] as $kode) {
            $this->checkIn()->set('kodeBukti', $kode)->call('verifikasi')
                ->assertSee('Bukti tidak dikenal. Minta PIC mengirim ulang bukti.')->assertSet('buktiUntuk', null);
        }

        // Bukan hari kedatangan, lalu dibatalkan
        $nanti = $this->disetujui('2026-10-12', '2026-10-14', ['108']);
        $this->checkIn()->set('kodeBukti', $nanti->kodeBukti())->call('verifikasi')
            ->assertSee('Bukti sah, tetapi jadwal datang 12 Okt 2026.')->assertSet('buktiUntuk', null);
        app(ReservasiService::class)->batalkan($nanti, 'Kegiatan ditunda', $this->akun('fo'));
        $this->checkIn()->set('kodeBukti', $nanti->kodeBukti())->call('verifikasi')
            ->assertSee("Reservasi {$nanti->reservation_code} berstatus dibatalkan. Bukti tidak berlaku.");
    }

    public function test_B4_kode_milik_reservasi_lain_ditolak_saat_memverifikasi_baris(): void
    {
        $r = $this->disetujui();
        $lain = $this->disetujui('2026-10-06', '2026-10-07', ['108']);

        // Tombol "Verifikasi bukti" pada baris $r: isian kode bukti untuk $r
        $this->checkIn()->call('mulaiVerifikasi', $r->id)->assertSet('kodeUntuk', $r->reservation_code)->assertDispatched('fokus-kode-bukti')
            ->set('kodeBukti', $lain->kodeBukti())->call('verifikasi')
            ->assertSee("Kode bukti ini bukan untuk {$r->reservation_code}.")->assertSet('buktiUntuk', null)
            ->set('kodeBukti', $r->kodeBukti())->call('verifikasi')->assertSet('buktiUntuk', $r->id);

        // Dari Daftar Reservasi (?kode=) langsung terarah ke reservasi itu
        Livewire::withQueryParams(['kode' => $r->reservation_code])->actingAs($this->akun('fo'))->test(CheckIn::class)
            ->assertSet('kodeUntuk', $r->reservation_code)->assertSeeText("Untuk {$r->reservation_code}");
    }

    public function test_B5_percobaan_ke_sebelas_dalam_semenit_ditolak(): void
    {
        $r = $this->disetujui();
        $halaman = $this->checkIn();
        for ($i = 0; $i < 10; $i++) {
            $halaman->set('kodeBukti', 'ZZZZ-ZZZZ')->call('verifikasi')->assertSee('Bukti tidak dikenal.');
        }
        $halaman->set('kodeBukti', $r->kodeBukti())->call('verifikasi')
            ->assertHasErrors(['kodeBukti'])->assertSee('Terlalu banyak percobaan. Coba lagi dalam')->assertSet('buktiUntuk', null);
    }

    public function test_B6_instansi_hanya_check_in_setelah_verifikasi_dan_tercatat_di_log_kamar(): void
    {
        $r = $this->disetujui();
        $fo = $this->akun('fo');

        try {
            app(ReservasiService::class)->checkIn($r, [], false, null, $fo);
            $this->fail('Reservasi instansi seharusnya tidak bisa check-in tanpa bukti.');
        } catch (ValidationException $e) {
            $this->assertSame('Reservasi instansi hanya bisa check-in setelah bukti pemesanan diverifikasi.', $e->errors()['status'][0]);
        }
        $this->checkIn()->assertSee('Verifikasi bukti')->call('open', $r->id)->assertSet('selectedId', null)->assertSet('kodeUntuk', $r->reservation_code);
        Livewire::actingAs($fo)->test(DaftarReservasi::class)->call('open', $r->id)->assertSee('Verifikasi bukti')->assertSee('Kirim ulang bukti');

        $this->checkIn()->set('kodeBukti', $r->kodeBukti())->call('verifikasi')
            ->call('open', $r->id)->assertSet('selectedId', $r->id)
            ->set('ktpDitahan', false)->call('simpan')->assertHasNoErrors();

        $this->assertSame('checked_in', $r->fresh()->status);
        foreach (['106', '107'] as $nomor) {
            $this->assertSame(RoomStatus::OccupiedClean, $this->kamar($nomor)->status);
            $this->assertSame('Check-in instansi, bukti diverifikasi oleh Yusuf Ramadhan',
                RoomStatusLog::where('room_id', $this->kamar($nomor)->id)->latest('id')->value('note'));
        }
        $this->checkIn()->set('kodeBukti', $r->kodeBukti())->call('verifikasi')
            ->assertSee("{$r->reservation_code} sudah check-in pada 6 Okt 2026 15.00.");
    }

    public function test_B7_reservasi_perorangan_tetap_check_in_seperti_biasa(): void
    {
        $fo = $this->akun('fo');
        $r = app(ReservasiService::class)->buatLangsung(Customer::create(['full_name' => 'Hasan Basri', 'phone' => '081322006677']),
            CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-08'), [$this->kamar('104')->id], $fo);

        $this->checkIn()->assertSee('Proses check-in')
            ->call('open', $r->id)->assertSet('selectedId', $r->id)
            ->set('ktpDitahan', false)->call('simpan')->assertHasNoErrors();
        $this->assertSame('checked_in', $r->fresh()->status);
        $this->assertSame('Check-in '.$r->reservation_code, RoomStatusLog::where('room_id', $this->kamar('104')->id)->latest('id')->value('note'));
    }

    public function test_B8_kirim_ulang_bukti_ke_pic(): void
    {
        $r = $this->disetujui();
        $sebelum = count($this->pesanWaPic());

        Livewire::actingAs($this->akun('fo'))->test(DaftarReservasi::class)->call('open', $r->id)->call('kirimUlangBukti')
            ->assertDispatched('toast', teks: "Bukti {$r->reservation_code} dikirim ulang ke Fajar Nugroho.");
        $this->assertCount($sebelum + 1, $this->pesanWaPic());
        $this->assertStringContainsString("berikut bukti pemesanan {$r->reservation_code}", collect($this->pesanWaPic())->last());

        Livewire::actingAs($this->akun('manager'))->test(DaftarReservasi::class)->call('open', $r->id)->call('kirimUlangBukti')->assertForbidden();
    }
}
