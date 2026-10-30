<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Setting;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Early check-in: mulai early_check_in_from (bawaan 06.00) sampai check_in_time (14.00) tamu yang tiba hari ini
 * boleh langsung masuk bila semua kamarnya Siap. Waktu acuan: Selasa, 6 Okt 2026; kamar 104 dan 113 Siap, 201 kotor.
 */
class EarlyCheckInTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private ReservasiService $reservasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        $this->reservasi = app(ReservasiService::class);
    }

    /** Walk-in hari ini (langsung terkonfirmasi) dengan kamar tertentu. */
    private function walkIn(array $nomor, string $masuk = '2026-10-06', string $keluar = '2026-10-07'): Reservation
    {
        $tamu = Customer::firstOrCreate(['phone' => '081277003344'], ['full_name' => 'Agus Salim', 'is_active' => true]);

        return $this->reservasi->buatLangsung($tamu, CarbonImmutable::parse($masuk), CarbonImmutable::parse($keluar),
            array_map(fn ($n) => $this->kamar($n)->id, $nomor), $this->akun('fo'));
    }

    private function galat(callable $f, string $pesan): void
    {
        try {
            $f();
            $this->fail('Seharusnya ditolak: '.$pesan);
        } catch (ValidationException $e) {
            $this->assertContains($pesan, collect($e->errors())->flatten()->all());
        }
    }

    public function test_E01_jam_12_kamar_siap_berhasil_dan_tercatat_early_check_in(): void
    {
        $r = $this->walkIn(['104', '113']);
        Carbon::setTestNow('2026-10-06 12:00:00');

        $this->reservasi->checkIn($r, [], false, null, $this->akun('fo'));

        $this->assertSame('checked_in', $r->fresh()->status);
        $this->assertSame(RoomStatus::OccupiedClean, $this->kamar('104')->status);
        $this->assertDatabaseHas('reservation_logs', ['reservation_id' => $r->id, 'event_type' => 'status_change', 'status' => 'checked_in', 'note' => 'Early check-in pukul 12.00 WITA']);
        // Tanpa biaya tambahan: nota tetap 1 malam per kamar
        $this->assertSame(2, $r->stayInvoice->items()->count());
        $this->assertEqualsCanonicalizing(['Kamar 104 · Twin Single, 1 malam', 'Kamar 113 · Twin Double, 1 malam'], $r->stayInvoice->items()->pluck('description')->all());
    }

    public function test_E02_jam_12_ada_kamar_belum_siap_ditolak_semua(): void
    {
        $r = $this->walkIn(['104', '201']);
        Carbon::setTestNow('2026-10-06 12:00:00');

        $this->galat(fn () => $this->reservasi->checkIn($r, [], false, null, $this->akun('fo')),
            'Kamar 201 belum siap. Early check-in hanya untuk kamar berstatus Siap.');
        // Semua atau tidak sama sekali: kamar yang Siap pun belum ditempati
        $this->assertSame('confirmed', $r->fresh()->status);
        $this->assertSame(RoomStatus::Available, $this->kamar('104')->status);
        $this->assertSame(['booked', 'booked'], $r->rooms()->pluck('status')->all());
    }

    public function test_E03_jam_03_ditolak_dihitung_malam_sebelumnya(): void
    {
        $r = $this->walkIn(['104']);
        Carbon::setTestNow('2026-10-06 03:00:00');

        $this->galat(fn () => $this->reservasi->checkIn($r, [], false, null, $this->akun('fo')),
            'Kedatangan sebelum pukul 06.00 dihitung menginap malam sebelumnya. Ubah tanggal masuk reservasi terlebih dahulu.');
        $this->assertSame('confirmed', $r->fresh()->status);
    }

    public function test_E04_jam_15_check_in_normal_tanpa_catatan_early(): void
    {
        $r = $this->walkIn(['104']);
        Carbon::setTestNow('2026-10-06 15:00:00');

        $this->reservasi->checkIn($r, [], false, null, $this->akun('fo'));

        $this->assertSame('checked_in', $r->fresh()->status);
        $this->assertDatabaseHas('reservation_logs', ['reservation_id' => $r->id, 'status' => 'checked_in', 'note' => null]);
        $this->assertDatabaseMissing('reservation_logs', ['reservation_id' => $r->id, 'note' => 'Early check-in pukul 15.00 WITA']);
    }

    public function test_E05_tamu_terlambat_tetap_bisa_check_in_pagi_hari(): void
    {
        // Dipesan kemarin untuk kemarin, tamu baru datang hari ini pukul 03.00
        Carbon::setTestNow('2026-10-05 10:00:00');
        $r = $this->walkIn(['104'], '2026-10-05', '2026-10-08');
        Carbon::setTestNow('2026-10-06 03:00:00');

        $this->reservasi->checkIn($r, [], false, null, $this->akun('fo'));
        $this->assertSame('checked_in', $r->fresh()->status);
        $this->assertDatabaseHas('reservation_logs', ['reservation_id' => $r->id, 'status' => 'checked_in', 'note' => null]);
    }

    public function test_E06_jam_mulai_early_dibaca_dari_pengaturan(): void
    {
        DB::table('settings')->insert(['setting_key' => 'early_check_in_from', 'value' => '08:00', 'type' => 'time']);
        cache()->forget('settings.all');
        $r = $this->walkIn(['104']);
        Carbon::setTestNow('2026-10-06 07:30:00');

        $this->galat(fn () => $this->reservasi->checkIn($r, [], false, null, $this->akun('fo')),
            'Kedatangan sebelum pukul 08.00 dihitung menginap malam sebelumnya. Ubah tanggal masuk reservasi terlebih dahulu.');
        Carbon::setTestNow('2026-10-06 08:00:00');
        $this->reservasi->checkIn($r, [], false, null, $this->akun('fo'));
        $this->assertDatabaseHas('reservation_logs', ['reservation_id' => $r->id, 'note' => 'Early check-in pukul 08.00 WITA']);
    }

    public function test_E07_halaman_check_in_banner_lencana_dan_kamar_belum_siap_disembunyikan(): void
    {
        // Kamar 201 (Deluxe, kotor) ditetapkan; Deluxe lain yang Siap: 111, 113, …
        $r = $this->walkIn(['201']);
        $baris = $r->rooms()->sole();
        $banner = 'Check-in normal mulai pukul 14.00 WITA. Tamu yang datang lebih awal bisa langsung masuk bila kamarnya sudah Siap (early check-in).';

        Carbon::setTestNow('2026-10-06 12:00:00');
        $layar = Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)->assertSee($banner)
            ->call('open', $r->id)
            ->assertSee('data-lencana-early', false)->assertSee('Early check-in')
            ->assertSee('Kamar 113 · Twin Double')->assertDontSee('(belum siap)')->assertDontSee('Kamar 201 ·')
            // Kamar yang ditetapkan belum Siap dikosongkan, FO memilih kamar yang Siap
            ->assertSet('penempatan.'.$baris->id, '');
        $layar->call('simpan')->assertHasErrors(['kamar.'.$baris->id]);
        $layar->set('penempatan.'.$baris->id, (string) $this->kamar('201')->id)->call('simpan')
            ->assertHasErrors(['kamar.'.$baris->id => 'Kamar 201 belum siap. Early check-in hanya untuk kamar berstatus Siap.']);
        $layar->set('penempatan.'.$baris->id, (string) $this->kamar('113')->id)->call('simpan')->assertHasNoErrors();
        $this->assertSame([$this->kamar('113')->id, 'checked_in'], [$baris->fresh()->room_id, $baris->fresh()->status]);

        // Setelah 14.00 dan sebelum 06.00: tanpa banner; setelah 14.00 kamar belum siap tetap tampil dengan tanda
        $r2 = $this->walkIn(['112']);
        Carbon::setTestNow('2026-10-06 15:00:00');
        Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)->assertDontSee($banner)
            ->call('open', $r2->id)->assertDontSee('data-lencana-early', false)->assertSee('Kamar 112 · ')->assertSee('(belum siap)');
        Carbon::setTestNow('2026-10-06 05:00:00');
        Livewire::actingAs($this->akun('fo'))->test(CheckIn::class)->assertDontSee($banner);
    }

    public function test_E08_pengaturan_early_check_in_disimpan_sebagai_baris_settings(): void
    {
        DB::table('settings')->insert(['setting_key' => 'check_in_time', 'value' => '14:00', 'type' => 'time']);
        cache()->forget('settings.all');
        $this->assertNull(Setting::find('early_check_in_from'));

        // Baris belum ada: isian tetap tampil dengan bawaan 06.00, di samping jam check-in
        $this->actingAs($this->akun('admin'))->get(route('staf.pengaturan'))->assertOk()
            ->assertSeeInOrder(['Jam check-in', 'Early check-in mulai pukul'])->assertSee('06:00');

        $isi = fn (array $ubah) => Setting::all()->pluck('value', 'setting_key')->merge($ubah)->all();
        $this->actingAs($this->akun('admin'))->putJson(route('staf.pengaturan.update'), $isi(['early_check_in_from' => '07:00']))->assertOk()
            ->assertJsonPath('pesan', 'Pengaturan disimpan (1 diubah).');
        $baris = Setting::find('early_check_in_from');
        $this->assertSame(['07:00', 'time', $this->akun('admin')->id], [$baris->value, $baris->type, $baris->updated_by]);
        $this->assertSame('07.00', ReservasiService::jamEarlyCheckIn());

        // Harus lebih awal dari jam check-in
        $this->actingAs($this->akun('admin'))->putJson(route('staf.pengaturan.update'), $isi(['early_check_in_from' => '14:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['early_check_in_from' => 'Harus lebih awal dari jam check-in.']);
        $this->assertSame('07:00', Setting::find('early_check_in_from')->value);
    }

    public function test_E09_kalimat_early_check_in_untuk_tamu(): void
    {
        $kalimat = 'Early check-in mulai pukul 06.00, bila kamar sudah siap.';
        $r = $this->walkIn(['104'], '2026-10-10', '2026-10-12');

        $this->get($r->urlStatus())->assertOk()->assertSee('data-early-check-in', false)->assertSee($kalimat);
        $this->assertStringContainsString('WITA, Sabtu, 10 Okt 2026. '.$kalimat, app(NotifikasiReservasi::class)->teksUangMukaDiterima($r));

        // Sudah menginap: kalimat tidak tampil lagi
        $r->update(['status' => 'checked_in']);
        $this->get($r->urlStatus())->assertOk()->assertDontSee('data-early-check-in', false);
    }
}
