<?php

namespace Tests\Feature;

use App\Models\MaintenanceSchedule;
use App\Models\User;
use App\Notifications\PesanReservasi;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Pengingat harian perawatan rutin untuk Manager. Waktu acuan 6 Okt 2026 07.00 WITA. */
class PengingatJadwalTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 07:00:00');
        $this->siapkanDataContoh();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        User::whereKey(2)->update(['phone' => '081234567890']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jadwal(int $fasilitas, string $area, string $jatuhTempo, array $isi = []): MaintenanceSchedule
    {
        return MaintenanceSchedule::create($isi + ['facility_id' => $fasilitas, 'area_label' => $area, 'interval_months' => 3, 'next_due_date' => $jatuhTempo]);
    }

    public function test_manager_diingatkan_jadwal_lewat_dan_segera_lewat_whatsapp_dan_email(): void
    {
        $this->jadwal(1, 'Lantai 2', '2026-09-20', ['vendor_name' => 'CV Sejuk Teknik']); // lewat 16 hari
        $this->jadwal(1, 'Aula', '2026-10-10');      // 4 hari lagi
        $this->jadwal(1, 'Lantai 1', '2026-10-13');  // tepat 7 hari lagi, masih diingatkan
        $this->jadwal(4, 'Lantai 2', '2026-10-14');  // 8 hari lagi, belum
        $this->jadwal(5, 'Lantai 1', '2026-09-01', ['is_active' => false]); // nonaktif

        $this->artisan('maintenance:pengingat-jadwal')
            ->expectsOutput('Pengingat 3 jadwal perawatan dikirim ke Manager.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $teks = $r['message'];

            return $r['target'] === '081234567890'
                && str_contains($teks, 'Halo Lina')
                && str_contains($teks, "*Terlambat dirawat*\n• AC Lantai 2: perawatan berikutnya 20 Sep 2026, terlambat 16 hari (CV Sejuk Teknik)")
                && str_contains($teks, "*Perlu dirawat 7 hari ke depan*\n• AC Aula: perawatan berikutnya 10 Okt 2026, 4 hari lagi")
                && str_contains($teks, '• AC Lantai 1: perawatan berikutnya 13 Okt 2026, 7 hari lagi')
                && ! str_contains($teks, 'jatuh tempo')
                && ! str_contains($teks, 'WiFi')
                && ! str_contains($teks, 'Water Heater')
                && str_contains($teks, route('staf.maintenance.jadwal'));
        });

        Notification::assertSentTo(User::find(2), PesanReservasi::class, fn (PesanReservasi $n) => str_contains($n->subjek, '3 jadwal perlu dirawat')
            && $n->url === route('staf.maintenance.jadwal'));
        // Peran lain tidak menerima
        Notification::assertNotSentTo(User::find(6), PesanReservasi::class);
    }

    public function test_hanya_manager_aktif_yang_menerima(): void
    {
        $this->jadwal(1, 'Aula', '2026-10-06');
        DB::table('users')->insert(['id' => 20, 'name' => 'Manager Lama', 'email' => 'lama@wismatrans.id', 'phone' => '081200000020', 'is_active' => 0]);
        DB::table('user_roles')->insert(['user_id' => 20, 'role_id' => 2]);

        $this->artisan('maintenance:pengingat-jadwal')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r['message'], 'AC Aula: perawatan berikutnya 06 Okt 2026, hari ini'));
        Http::assertNotSent(fn (Request $r) => $r['target'] === '081200000020');
        Notification::assertNotSentTo(User::find(20), PesanReservasi::class);
    }

    public function test_tidak_ada_perawatan_dekat_tidak_mengirim_apa_pun(): void
    {
        $this->jadwal(1, 'Aula', '2026-12-01');

        $this->artisan('maintenance:pengingat-jadwal')->expectsOutput('Tidak ada pengingat yang dikirim.')->assertSuccessful();

        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_sekali_sehari_lalu_besok_dikirim_lagi_sampai_perawatan_dicatat(): void
    {
        $jadwal = $this->jadwal(1, 'Lantai 2', '2026-09-20');

        $this->artisan('maintenance:pengingat-jadwal')->assertSuccessful();
        $this->artisan('maintenance:pengingat-jadwal')->expectsOutput('Tidak ada pengingat yang dikirim.')->assertSuccessful();
        Http::assertSentCount(1);

        // --paksa untuk kirim ulang manual di hari yang sama
        $this->artisan('maintenance:pengingat-jadwal --paksa')->assertSuccessful();
        Http::assertSentCount(2);

        Carbon::setTestNow('2026-10-07 07:00:00');
        $this->artisan('maintenance:pengingat-jadwal')->assertSuccessful();
        Http::assertSentCount(3);

        // Perawatan dicatat lewat halaman Jadwal Rutin: perawatan berikutnya maju 3 bulan, pengingat berhenti
        $this->actingAs(User::find(2))->postJson(route('staf.maintenance.jadwal.laksanakan', $jadwal), [
            'tanggal' => '2026-10-07', 'pengerja' => 'vendor', 'vendor_name' => 'CV Sejuk Teknik', 'cost' => '450.000',
        ])->assertOk()->assertJsonPath('pesan', 'Perawatan dicatat. Perawatan berikutnya: 7 Jan 2027.');
        $this->assertSame('2027-01-07', $jadwal->fresh()->next_due_date->toDateString());

        Carbon::setTestNow('2026-10-08 07:00:00');
        $this->artisan('maintenance:pengingat-jadwal')->expectsOutput('Tidak ada pengingat yang dikirim.')->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_whatsapp_gagal_email_tetap_terkirim(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response('galat', 500)]);
        $this->jadwal(1, 'Aula', '2026-10-08');

        $this->artisan('maintenance:pengingat-jadwal')->assertSuccessful();

        Notification::assertSentTo(User::find(2), PesanReservasi::class);
    }

    public function test_terjadwal_tiap_hari_pukul_tujuh(): void
    {
        $tugas = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'maintenance:pengingat-jadwal'));

        $this->assertNotNull($tugas);
        $this->assertSame('0 7 * * *', $tugas->expression);
    }
}
