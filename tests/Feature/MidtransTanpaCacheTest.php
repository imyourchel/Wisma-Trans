<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\ReservasiService;
use App\Support\Pelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/**
 * Pembayaran Midtrans tanpa bergantung pada cache: setiap percobaan bayar tercatat di payments, sehingga sinkron,
 * ganti metode, perintah midtrans:sinkron, dan notifikasi tetap benar setelah cache dihapus. Midtrans tidak pernah
 * dipanggil sungguhan: charge, status, dan expire diganti di subkelas.
 * Juga memastikan aplikasi berjalan dengan session dan cache file tanpa tabel sessions/cache/cache_locks.
 */
class MidtransTanpaCacheTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private PembayaranMidtrans $midtrans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->withoutVite();
        Notification::fake();
        Http::fake();
        config(['services.midtrans.server_key' => 'uji-server', 'services.midtrans.client_key' => 'uji-client', 'services.fonnte.token' => null]);
        $this->midtrans = new class extends PembayaranMidtrans
        {
            /** order_id => jawaban status Midtrans */
            public array $status = [];

            public array $dicek = [];

            public array $diakhiri = [];

            protected function chargeDiMidtrans(array $parameter): array
            {
                return ['va_numbers' => [['bank' => 'bca', 'va_number' => '8277000011112222']],
                    'actions' => [['name' => 'generate-qr-code', 'url' => 'https://contoh.test/qr.png']]];
            }

            protected function statusDiMidtrans(string $idMidtrans): array
            {
                $this->dicek[] = $idMidtrans;

                return $this->status[$idMidtrans] ?? throw new RuntimeException('404 Transaction does not exist');
            }

            protected function akhiriDiMidtrans(string $idMidtrans): void
            {
                $this->diakhiri[] = $idMidtrans;
            }
        };
        $this->app->instance(PembayaranMidtrans::class, $this->midtrans);
    }

    private function reservasi(string $hp = '081299110022'): Reservation
    {
        $tamu = Pelanggan::dariIsian(['name' => 'Rizky Pratama', 'phone' => $hp], null, 'hp');

        return app(ReservasiService::class)->buatOnline($tamu, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-22'), [$this->tipe('STD')->id => 1]);
    }

    private function jawaban(string $id, int $jumlah, string $status = 'settlement'): array
    {
        return ['order_id' => $id, 'transaction_status' => $status, 'status_code' => $status === 'settlement' ? '200' : '201',
            'gross_amount' => $jumlah.'.00', 'payment_type' => 'bank_transfer', 'transaction_id' => 'trx-'.$id,
            'transaction_time' => '2026-10-06 10:05:00', 'settlement_time' => '2026-10-06 10:06:00'];
    }

    public function test_MC01_tagihan_baru_tercatat_pending_di_payments_tanpa_baris_ganda(): void
    {
        $r = $this->reservasi();
        $t = $this->midtrans->buatTagihan($r, 'bca');

        $b = Payment::where('midtrans_order_id', $t['order_id'])->sole();
        $this->assertStringStartsWith('WT-RSV', $t['order_id']);
        $this->assertSame(['pending', 'online', 'payment', 'down_payment', 'virtual_account', (float) $r->jumlahBayar(), $r->stayInvoice->id],
            [$b->status, $b->channel, $b->transaction_type, $b->payment_purpose, $b->method, (float) $b->amount, $b->invoice_id]);

        // Metode yang sama dipakai ulang: tidak ada baris baru
        $this->midtrans->buatTagihan($r->fresh(), 'bca');
        $this->assertSame(1, Payment::count());
        $this->assertSame('qris', Payment::where('midtrans_order_id', $this->midtrans->buatTagihan($r->fresh(), 'qris')['order_id'])->value('method'));
    }

    public function test_MC02_cache_dihapus_sinkronkan_tetap_menemukan_order_id_dan_menandai_lunas(): void
    {
        $r = $this->reservasi();
        $t = $this->midtrans->buatTagihan($r, 'qris');
        Cache::flush();
        $this->midtrans->status[$t['order_id']] = $this->jawaban($t['order_id'], $t['jumlah']);

        $this->midtrans->sinkronkan($r->fresh());

        $this->assertSame([$t['order_id']], $this->midtrans->dicek);
        $this->assertSame('success', Payment::sole()->status);
        $this->assertSame('confirmed', $r->fresh()->status);
        $this->assertSame((float) $t['jumlah'], (float) $r->fresh()->stayInvoice->paid_amount);
    }

    public function test_MC03_ganti_metode_dan_batal_mengakhiri_percobaan_lama(): void
    {
        $r = $this->reservasi();
        $a = $this->midtrans->buatTagihan($r, 'bca');
        $b = $this->midtrans->buatTagihan($r->fresh(), 'qris');
        $this->assertSame(['expired', 'pending'], [Payment::where('midtrans_order_id', $a['order_id'])->value('status'), Payment::where('midtrans_order_id', $b['order_id'])->value('status')]);
        $this->assertSame([$a['order_id']], $this->midtrans->diakhiri);

        // Cache hilang: percobaan pending tetap diketahui dari payments dan diakhiri saat ganti metode
        Cache::flush();
        $c = $this->midtrans->buatTagihan($r->fresh(), 'bni');
        $this->assertSame('expired', Payment::where('midtrans_order_id', $b['order_id'])->value('status'));
        $this->assertSame([$c['order_id']], Payment::where('status', 'pending')->pluck('midtrans_order_id')->all());

        // Batal (cache juga hilang): percobaan terakhir ikut diakhiri
        Cache::flush();
        $this->midtrans->akhiriTagihan($r->fresh());
        $this->assertSame([$a['order_id'], $b['order_id'], $c['order_id']], $this->midtrans->diakhiri);
        $this->assertSame(0, Payment::where('status', 'pending')->count());
        $this->assertSame(3, Payment::count());
    }

    public function test_MC04_perintah_midtrans_sinkron_menandai_pending_yang_sudah_settlement(): void
    {
        $r = $this->reservasi();
        $t = $this->midtrans->buatTagihan($r, 'bca');
        $lama = $this->midtrans->buatTagihan($this->reservasi('081299110033'), 'bca');
        Payment::where('midtrans_order_id', $lama['order_id'])->update(['created_at' => now()->subDays(3)]);
        Cache::flush();
        $this->midtrans->status[$t['order_id']] = $this->jawaban($t['order_id'], $t['jumlah']);
        $this->midtrans->status[$lama['order_id']] = $this->jawaban($lama['order_id'], $lama['jumlah']);

        $this->artisan('midtrans:sinkron')->expectsOutput('1 pembayaran online diperbarui dari Midtrans.')->assertExitCode(0);

        $this->assertSame('success', Payment::where('midtrans_order_id', $t['order_id'])->value('status'));
        $this->assertSame('confirmed', $r->fresh()->status);
        // Lebih dari 2 hari: tidak dicek
        $this->assertSame([$t['order_id']], $this->midtrans->dicek);
        $this->assertSame('pending', Payment::where('midtrans_order_id', $lama['order_id'])->value('status'));

        // Kunci Midtrans kosong: dilewati tanpa memanggil Midtrans
        config(['services.midtrans.server_key' => null, 'services.midtrans.client_key' => null]);
        $this->artisan('midtrans:sinkron')->expectsOutput('Kunci Midtrans kosong, sinkron dilewati.')->assertExitCode(0);
        $this->assertSame([$t['order_id']], $this->midtrans->dicek);
    }

    public function test_MC05_notifikasi_untuk_order_id_yang_sama_tidak_membuat_baris_ganda(): void
    {
        $r = $this->reservasi();
        $t = $this->midtrans->buatTagihan($r, 'bca');
        $kirim = fn (array $st) => $this->postJson(route('midtrans.notifikasi'),
            $st + ['signature_key' => hash('sha512', $st['order_id'].$st['status_code'].$st['gross_amount'].'uji-server')])->assertOk();

        $kirim($this->jawaban($t['order_id'], $t['jumlah'], 'pending'));
        $kirim($this->jawaban($t['order_id'], $t['jumlah']));
        $kirim($this->jawaban($t['order_id'], $t['jumlah']));

        $this->assertSame(1, Payment::where('midtrans_order_id', $t['order_id'])->count());
        $this->assertSame('success', Payment::sole()->status);
        $this->assertSame((float) $t['jumlah'], (float) $r->fresh()->stayInvoice->paid_amount);
    }

    public function test_MC06_aplikasi_berjalan_dengan_session_dan_cache_file_tanpa_tabelnya(): void
    {
        foreach (['sessions', 'cache', 'cache_locks'] as $tabel) {
            $this->assertFalse(Schema::hasTable($tabel), "Tabel {$tabel} tidak boleh ada");
        }
        $dir = storage_path('framework/testing/tanpa-tabel-'.Str::lower(Str::random(6)));
        File::ensureDirectoryExists($dir.'/sesi');
        File::ensureDirectoryExists($dir.'/cache');
        config(['session.driver' => 'file', 'session.files' => $dir.'/sesi', 'cache.default' => 'file', 'cache.stores.file.path' => $dir.'/cache']);
        $this->app->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstances();

        try {
            // Rate limiter masuk: tersimpan di cache file
            for ($i = 0; $i < 5; $i++) {
                $this->postJson(route('login.store'), ['login' => 'yusuf@wismatrans.id', 'password' => 'salah'])->assertStatus(422);
            }
            $this->postJson(route('login.store'), ['login' => 'yusuf@wismatrans.id', 'password' => 'password'])
                ->assertStatus(422)->assertJsonFragment(['Terlalu banyak percobaan masuk. Silakan coba lagi dalam 60 detik.']);
            $this->assertGuest();

            // Masuk dan keluar dengan session file
            $this->postJson(route('login.store'), ['login' => 'citra@contoh.id', 'password' => 'password'])->assertOk();
            $this->assertAuthenticatedAs($this->akun('customer'));
            $this->post(route('logout'))->assertRedirect();
            $this->assertGuest();

            // Cek Pesanan
            $this->get(route('cek'))->assertOk();

            $this->assertNotEmpty(File::allFiles($dir.'/cache'));
            $this->assertNotEmpty(File::allFiles($dir.'/sesi'));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
