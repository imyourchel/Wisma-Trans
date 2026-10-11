<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PesanReservasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Pemberitahuan laporan kerusakan: Manager untuk laporan penting, Front Office untuk tamu yang perlu pindah kamar.
 * Waktu acuan 6 Okt 2026 10.00 WITA.
 */
class NotifikasiMaintenanceTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    /** Nomor WhatsApp uji: Manager, dua FO, dan pelapor HK. */
    private const HP = [2 => '081200000002', 3 => '081200000003', 13 => '081200000013', 6 => '081200000006'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->siapkanDataContoh();
        Notification::fake();
        config(['services.fonnte.token' => 'uji-fonnte']);
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);
        foreach (self::HP as $id => $hp) {
            User::whereKey($id)->update(['phone' => $hp]);
        }
        // Sari (FO dan F&B) dinonaktifkan supaya penerima FO pasti: Yusuf (3) dan akun FO+HK (13)
        User::whereKey(10)->update(['is_active' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pesan(string $kode, string $kamar, string $masuk, string $keluar, string $status): void
    {
        $pelanggan = DB::table('customers')->insertGetId(['full_name' => 'Citra Lestari', 'phone' => '081299110022']);
        $rsv = DB::table('reservations')->insertGetId([
            'reservation_code' => $kode, 'customer_id' => $pelanggan, 'booking_channel' => 'walk_in',
            'status' => $status === 'checked_in' ? 'checked_in' : 'confirmed',
        ]);
        DB::table('reservation_rooms')->insert([
            'reservation_id' => $rsv, 'room_type_id' => $this->kamar($kamar)->room_type_id, 'room_id' => $this->kamar($kamar)->id,
            'check_in_date' => $masuk, 'check_out_date' => $keluar, 'price_per_night' => 460000, 'status' => $status,
        ]);
    }

    private function lapor(string $akun, array $isi)
    {
        return $this->actingAs($this->akun($akun))->postJson(route('staf.maintenance.lapor.store'), $isi + [
            'unit' => 'lainnya', 'deskripsi' => 'AC tidak dingin', 'priority' => 'medium', 'tidak_bisa_dipakai' => '0',
        ]);
    }

    /** @return list<string> nomor tujuan WhatsApp yang dikirimi */
    private function tujuanWa(): array
    {
        return Http::recorded()->map(fn ($r) => $r[0]['target'])->sort()->values()->all();
    }

    public function test_tamu_menginap_fo_diminta_pindah_kamar_dan_manager_diberi_tahu(): void
    {
        $this->pesan('RSV-0012', '204', '2026-10-05', '2026-10-08', 'checked_in');

        $this->lapor('hk', ['lokasi' => 'kamar', 'room_id' => $this->kamar('204')->id, 'tidak_bisa_dipakai' => '1'])->assertOk()
            ->assertJsonPath('peringatan', 'Kamar sedang terisi. Status kamar tidak diubah. Front Office sudah diberi tahu lewat WhatsApp dan email untuk memindahkan tamu.');

        // Dua FO dan satu Manager; pelapor (HK) dan FO nonaktif tidak dikirimi
        $this->assertSame(['081200000002', '081200000003', '081200000013'], $this->tujuanWa());
        Http::assertSent(fn (Request $r) => $r['target'] === '081200000003'
            && str_contains($r['message'], 'Mohon pindahkan tamu: kamar 204 tidak bisa dipakai karena kerusakan (MNT-0001: AC tidak dingin).')
            && str_contains($r['message'], 'Tamu: Citra Lestari (RSV-0012), sedang menginap sampai 8 Okt 2026.')
            && str_contains($r['message'], route('staf.reservasi', ['cari' => 'RSV-0012', 'status' => ''])));
        Http::assertSent(fn (Request $r) => $r['target'] === '081200000002'
            && str_contains($r['message'], 'Laporan kerusakan MNT-0001 (prioritas Sedang), belum dikerjakan')
            && str_contains($r['message'], 'Kamar tidak bisa dipakai. Ada tamu RSV-0012; Front Office sudah diminta memindahkan kamarnya.')
            && str_contains($r['message'], 'Pelapor: Rahmat Hidayat'));

        Notification::assertSentTo(User::find(3), PesanReservasi::class, fn (PesanReservasi $n) => $n->subjek === 'Pindahkan tamu dari kamar 204 (MNT-0001)'
            && $n->url === route('staf.reservasi', ['cari' => 'RSV-0012', 'status' => '']));
        Notification::assertSentTo(User::find(2), PesanReservasi::class);
        Notification::assertNotSentTo([User::find(6), User::find(10)], PesanReservasi::class);
    }

    public function test_tamu_datang_besok_fo_diberi_tahu(): void
    {
        $this->pesan('RSV-0013', '112', '2026-10-07', '2026-10-09', 'booked');

        $this->lapor('hk', ['lokasi' => 'kamar', 'room_id' => $this->kamar('112')->id, 'tidak_bisa_dipakai' => '1'])->assertOk()
            ->assertJsonPath('peringatan', 'Kamar 112 dijadwalkan untuk tamu RSV-0013 besok. Front Office sudah diberi tahu lewat WhatsApp dan email untuk memindahkan tamu.');

        Http::assertSent(fn (Request $r) => $r['target'] === '081200000013'
            && str_contains($r['message'], 'Tamu: Citra Lestari (RSV-0013), dijadwalkan masuk besok (7–9 Okt 2026).'));
    }

    public function test_tamu_datang_lusa_hanya_manager(): void
    {
        $this->pesan('RSV-0014', '105', '2026-10-08', '2026-10-10', 'booked');

        $this->lapor('hk', ['lokasi' => 'kamar', 'room_id' => $this->kamar('105')->id, 'tidak_bisa_dipakai' => '1'])->assertOk()
            ->assertJsonPath('peringatan', null);

        $this->assertSame(['081200000002'], $this->tujuanWa());
        Http::assertSent(fn (Request $r) => ! str_contains($r['message'], 'Front Office'));
    }

    public function test_laporan_biasa_tidak_mengirim_apa_pun_dan_prioritas_tinggi_hanya_ke_manager(): void
    {
        // Prioritas Sedang, kamar tetap bisa dipakai: cukup terlihat di Penanganan
        $this->pesan('RSV-0012', '204', '2026-10-05', '2026-10-08', 'checked_in');
        $this->lapor('hk', ['lokasi' => 'kamar', 'room_id' => $this->kamar('204')->id])->assertOk();
        Http::assertNothingSent();
        Notification::assertNothingSent();

        // Prioritas Tinggi di area umum: Manager saja
        DB::table('facility_units')->insert(['facility_id' => 1, 'area_label' => 'Aula', 'unit_label' => 'AC Aula 1', 'is_active' => 1]);
        $this->lapor('hk', ['lokasi' => 'area', 'area_label' => 'Aula', 'priority' => 'high', 'deskripsi' => 'Proyektor mati'])->assertOk()
            ->assertJsonPath('peringatan', null);
        $this->assertSame(['081200000002'], $this->tujuanWa());
        Http::assertSent(fn (Request $r) => str_contains($r['message'], 'Laporan kerusakan MNT-0002 (prioritas Tinggi), belum dikerjakan')
            && str_contains($r['message'], 'Kerusakan: Area Aula: Proyektor mati'));
    }

    public function test_pelapor_tidak_dikirimi_dan_tanpa_fo_lain_diminta_koordinasi(): void
    {
        $this->pesan('RSV-0012', '204', '2026-10-05', '2026-10-08', 'checked_in');
        User::whereKey(13)->update(['is_active' => false]);   // tersisa satu FO: Yusuf, yang melapor sendiri

        $this->lapor('fo', ['lokasi' => 'kamar', 'room_id' => $this->kamar('204')->id, 'tidak_bisa_dipakai' => '1'])->assertOk()
            ->assertJsonPath('peringatan', 'Kamar sedang terisi. Status kamar tidak diubah. Koordinasikan pindah kamar dengan Front Office.');

        $this->assertSame(['081200000002'], $this->tujuanWa());
        Notification::assertNotSentTo(User::find(3), PesanReservasi::class);
    }

    public function test_kirim_gagal_tidak_menggagalkan_laporan(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response('galat', 500)]);

        $this->lapor('hk', ['lokasi' => 'kamar', 'room_id' => $this->kamar('105')->id, 'priority' => 'high'])->assertOk();

        $this->assertDatabaseHas('maintenance_requests', ['request_code' => 'MNT-0001']);
        Notification::assertSentTo(User::find(2), PesanReservasi::class);
    }
}
