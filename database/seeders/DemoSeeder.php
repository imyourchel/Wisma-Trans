<?php

namespace Database\Seeders;

use App\Enums\RoomStatus;
use App\Models\BillingReminder;
use App\Models\BillingStatement;
use App\Models\Customer;
use App\Models\FacilityUnit;
use App\Models\Hall;
use App\Models\HousekeepingTask;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\MaintenanceSchedule;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Kamar\RoomStatusService;
use App\Services\Keuangan\NotaPdf;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Keuangan\PembayaranService;
use App\Services\Keuangan\PengingatPenagihan;
use App\Services\Keuangan\PiutangService;
use App\Services\Keuangan\TagihanBulananService;
use App\Services\Layanan\AulaService;
use App\Services\Layanan\LaundryService;
use App\Services\Layanan\PesananMakananService;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Notifikasi\WhatsAppService;
use App\Services\Reservasi\BuktiInstansi;
use App\Services\Reservasi\CheckOutService;
use App\Services\Reservasi\FolioService;
use App\Services\Reservasi\ReservasiService;
use App\Services\SmartCleaning\PembagianTugasService;
use App\Services\SmartCleaning\PrioritasPembersihanService;
use App\Services\SmartCleaning\TugasHousekeepingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SplPriorityQueue;
use stdClass;
use Throwable;

/**
 * Data demo SIM Wisma Trans: riwayat sekitar 75 hari terakhir dan keadaan "hari ini" yang siap didemokan.
 *
 * Semua data dibuat lewat service aplikasi dengan waktu yang dimundurkan (Carbon::setTestNow), jadi nota, pembayaran,
 * status kamar, log, tagihan bulanan, dan pengingat konsisten seperti hasil pemakaian sungguhan.
 * Tanggal selalu relatif terhadap hari seeder dijalankan: jalankan di pagi hari demo.
 *
 * Pengaman: hanya berjalan di database yang namanya berakhiran _demo (atau _test untuk tes otomatis).
 * Data transaksi lama di database itu DIHAPUS; data master (pengguna, kamar, menu, aula, pengaturan, bobot AHP) dipakai apa adanya.
 * Tidak ada WhatsApp, email, atau Midtrans sungguhan yang terkirim selama seeding.
 *
 * Cara pakai:
 *   1. Salin database utama menjadi wisma_trans_demo (phpMyAdmin > Operations > Copy database, struktur + data).
 *   2. Di .env: DB_DATABASE=wisma_trans_demo, lalu php artisan config:clear
 *   3. php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /** Panjang riwayat dalam hari. */
    private const RIWAYAT = 75;

    /** Riwayat acak berakhir paling lambat H-5; hari-hari terakhir diisi skenario tetap. */
    private const AKHIR_ACAK = 5;

    private Carbon $hari;

    private Carbon $sekarang;

    private SplPriorityQueue $antrean;

    private int $urut = 0;

    /** @var list<string> */
    private array $gagal = [];

    private int $jalan = 0;

    private string $cacheAsli = 'file';

    private array $u = [];

    private array $kamar = [];

    private array $menu = [];

    private array $instansi = [];

    private array $pelanggan = [];

    /** Pelanggan pool acak (B2C) dan tamu restoran. */
    private array $poolTamu = [];

    private array $poolResto = [];

    /** Kamar yang tidak dipakai riwayat acak (disimpan untuk skenario). */
    private const KAMAR_SKENARIO = ['116', '203', '205'];

    public function run(): void
    {
        $this->pastikanAman();
        $this->siapkanLingkungan();
        mt_srand(20261012);

        $this->sekarang = Carbon::now();
        $this->hari = $this->sekarang->copy()->startOfDay();
        $this->antrean = new SplPriorityQueue;

        $this->info('Mengosongkan data transaksi lama…');
        $this->kosongkan();
        $this->siapkanMaster();

        $this->info('Menyusun skenario…');
        $this->jadwalHarian();
        $this->riwayatAcak();
        $this->skenarioPelangganTetap();
        $this->skenarioInstansi();
        $this->skenarioHariIni();
        $this->skenarioAula();
        $this->skenarioMaintenance();
        $this->skenarioRestoran();
        $this->skenarioLaundryLuar();

        $this->info('Menjalankan '.count($this->antrean).' kejadian (bisa 1–3 menit)…');
        $this->jalankan();

        Carbon::setTestNow();
        config(['cache.default' => $this->cacheAsli]);
        try {
            Cache::store($this->cacheAsli)->flush();
        } catch (Throwable) {
            // Penyimpanan cache tidak tersedia (misalnya tabel cache belum dibuat): cukup dilewati
        }
        $this->ringkasan();
    }

    /* =================================================================== Persiapan */

    private function pastikanAman(): void
    {
        $db = (string) DB::connection()->getDatabaseName();
        if (! str_ends_with($db, '_demo') && ! str_ends_with($db, '_test')) {
            throw new RuntimeException("DemoSeeder dihentikan: database [{$db}] bukan database demo. Salin database utama menjadi nama berakhiran _demo, lalu atur DB_DATABASE.");
        }
    }

    private function siapkanLingkungan(): void
    {
        $this->cacheAsli = (string) config('cache.default');
        config([
            // Cache sementara di memori selama seeding; cache aplikasi dikosongkan di akhir
            'cache.default' => 'array',
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'services.fonnte.token' => 'demo-seeder',
            'services.midtrans.server_key' => null,
            'services.midtrans.client_key' => null,
        ]);
        // WhatsApp dianggap terkirim tanpa menghubungi Fonnte; permintaan HTTP lain juga tidak keluar
        Http::fake(['*' => Http::response(['status' => true], 200)]);
        // Notifikasi per reservasi (uang muka diterima, terima kasih check-out) dilewati supaya seeding cepat
        app()->instance(NotifikasiReservasi::class, new class(app(WhatsAppService::class), app(NotaPdf::class), app(BuktiInstansi::class)) extends NotifikasiReservasi
        {
            public function uangMukaDiterima(Reservation $r): void {}

            public function terimaKasih(Reservation $r): void {}
        });
    }

    private function kosongkan(): void
    {
        $tabel = [
            'billing_reminders', 'payments', 'invoice_items', 'housekeeping_task_assignees', 'housekeeping_tasks',
            'cleaning_priority_scores', 'cleaning_priority_items', 'cleaning_priority_batches', 'staff_availabilities',
            'service_order_items', 'service_orders', 'hall_bookings', 'maintenance_requests', 'room_status_logs',
            'reservation_logs', 'reservation_requests', 'reservation_rooms', 'invoices', 'billing_statements', 'reservations',
        ];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tabel as $t) {
            DB::table($t)->truncate();
        }
        foreach (['jobs', 'failed_jobs'] as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('rooms')->update(['status' => 'available', 'dirty_since' => null]);
        DB::table('services')->update(['is_sold_out' => 0]);
    }

    private function siapkanMaster(): void
    {
        $peran = fn (string $kode) => User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', $kode))->orderBy('id')->get();
        $this->u = [
            'manager' => $peran('manager')->first(),
            'fo' => $peran('front_office')->first(),
            'fo2' => $peran('front_office')->skip(1)->first() ?? $peran('front_office')->first(),
            'fnb' => $peran('fnb')->first(),
            'hk' => $peran('housekeeping')->values()->all(),
        ];
        if (! $this->u['manager'] || ! $this->u['fo'] || ! $this->u['fnb'] || ! $this->u['hk']) {
            throw new RuntimeException('Data master pengguna belum lengkap (butuh Manager, Front Office, F&B, Housekeeping).');
        }

        $this->kamar = Room::with('roomType')->where('is_active', true)->get()->keyBy('room_number')->all();
        $this->menu = Service::all()->keyBy('code')->all();
        foreach (['TEH02', 'MKN04', 'PKT02', 'KOP02', 'LDR01'] as $kode) {
            if (! isset($this->menu[$kode])) {
                throw new RuntimeException("Menu {$kode} tidak ditemukan; DemoSeeder memakai data master bawaan SIM Wisma Trans.");
            }
        }

        // Instansi dan narahubungnya (dipakai ulang bila sudah ada)
        $inst = [
            'VALE' => ['PT Vale Indonesia Tbk', 'monthly_fixed_date', 25, 30, [['Siti Rahma', '081255500003', 'siti.rahma@vale.contoh.id'], ['Andi Saputra', '081255500001', 'andi.saputra@vale.contoh.id']]],
            'DINKES' => ['Dinas Kesehatan Luwu Timur', 'due_after_checkout', null, 14, [['Rudi Hartawan', '081355500004', 'rudi@luwutimur.contoh.id']]],
            'PLN' => ['PT PLN (Persero) UP3 Palopo', 'due_after_checkout', null, 7, [['Fajar Nugroho', '081455500006', 'fajar@pln.contoh.id']]],
        ];
        foreach ($inst as $kode => [$nama, $skema, $hariTagih, $tempo, $kontak]) {
            $i = Institution::firstOrNew(['code' => $kode]);
            $i->fill(['name' => $i->name ?: $nama, 'default_billing_scheme' => $skema, 'billing_day' => $hariTagih, 'due_days' => $tempo])->save();
            foreach ($kontak as $n => [$nm, $hp, $email]) {
                $c = Customer::withTrashed()->firstOrNew(['phone' => $hp]);
                $c->fill(['full_name' => $c->full_name ?: $nm, 'email' => $c->email ?: $email, 'institution_id' => $i->id, 'is_active' => true])->save();
                if ($n === 0) {
                    $i->update(['primary_contact_id' => $c->id]);
                }
            }
            $this->instansi[$kode] = $i->fresh();
        }

        // Pelanggan perorangan; dua pertama tertaut ke akun customer (Citra, Budi) bila ada
        $akun = fn (string $email) => User::where('email', $email)->value('id');
        $orang = [
            'citra' => ['Citra Lestari', '081233004455', 'citra@contoh.id', $akun('citra@contoh.id')],
            'budi' => ['Budi Santoso', '081144001122', 'budi@contoh.id', $akun('budi@contoh.id')],
            'hasan' => ['Hasan Basri', '081322006677', null, null],
            'lukas' => ['Lukas Wibowo', '085211008899', null, null],
            'agus' => ['Agus Salim', '081277003344', null, null],
            'rizky' => ['Rizky Pratama', '081299110022', 'rizky@contoh.id', null],
            'dian' => ['Dian Permata', '081388776655', 'dian@contoh.id', null],
            'abdul' => ['Abdul Rahman', '081244556677', null, null],
            'yudi' => ['Yudi Kurniawan', '082199887766', null, null],
            'rahmawati' => ['Rahmawati Said', '081342110201', null, null],
            'wahyu' => ['Wahyu Hidayat', '081342110202', null, null],
            'fitri' => ['Fitri Handayani', '081342110203', 'fitri@contoh.id', null],
            'gilang' => ['Gilang Ramadhan', '081342110204', null, null],
            'wahyuni' => ['Wahyuni Lestari', '081342110205', 'wahyuni@contoh.id', null],
            'fikri' => ['Fikri Maulana', '081342110206', null, null],
            'nurul' => ['Nurul Aini', '081342110207', 'nurul@contoh.id', null],
            'andi' => ['Andi Pratama', '081342110208', null, null],
            'rosa' => ['Rosa Amelia', '081342110209', null, null],
            'marlina' => ['Marlina Pasau', '081342110216', null, null],
            'yohanes' => ['Yohanes Tandi', '081342110215', 'yohanes@contoh.id', null],
        ];
        $acak = [
            ['Kurniawan Saleh', '081342110210'], ['Indah Permatasari', '081342110211'], ['Bayu Aditya', '081342110212'],
            ['Siti Nurhaliza', '081342110213'], ['Hendra Gunawan', '081342110214'], ['Irfan Hakim', '081342110217'],
            ['Dewi Kartika', '081342110218'], ['Taufik Hidayat', '081342110219'], ['Lisa Anggraini', '081342110220'],
            ['Muh. Ilham', '081342110221'], ['Yusran Baso', '081342110222'], ['Nadia Putri', '081342110223'],
            ['Arif Rahman', '081342110224'], ['Sulastri Toding', '081342110225'], ['Ronald Palinggi', '081342110226'],
            ['Musdalifah', '081342110227'], ['Erwin Sampe', '081342110228'], ['Rini Wulandari', '081342110229'],
            ['Darmawan', '081342110230'], ['Sri Mulyani', '081342110231'], ['Petrus Lolo', '081342110232'],
            ['Haerul Amri', '081342110233'], ['Anisa Rahma', '081342110234'], ['Joko Susilo', '081342110235'],
        ];
        $resto = [
            ['Ahmad Fauzi', '085299001101'], ['Linda Sari', '085299001102'], ['Rudi Hermawan', '085299001103'],
            ['Wulan Dari', '085299001104'], ['Samsul Bahri', '085299001105'], ['Mega Utami', '085299001106'],
            ['Ikhsan', '085299001107'], ['Novi Andriani', '085299001108'], ['Fadli Akbar', '085299001109'],
            ['Ratna Dewi', '085299001110'], ['Teguh Prakoso', '085299001111'], ['Yanti Paembonan', '085299001112'],
        ];
        $buat = function (string $nama, string $hp, ?string $email = null, ?int $userId = null): Customer {
            $c = Customer::withTrashed()->firstOrNew(['phone' => $hp]);
            $c->fill(['full_name' => $c->full_name ?: $nama, 'email' => $c->email ?: $email, 'is_active' => true]);
            if ($userId && ! $c->user_id && ! Customer::where('user_id', $userId)->exists()) {
                $c->user_id = $userId;
            }
            $c->save();
            if ($c->trashed()) {
                $c->restore();
            }

            return $c;
        };
        foreach ($orang as $k => [$n, $hp, $e, $uid]) {
            $this->pelanggan[$k] = $buat($n, $hp, $e, $uid);
        }
        foreach ($acak as [$n, $hp]) {
            $this->poolTamu[] = $buat($n, $hp);
        }
        foreach ($resto as [$n, $hp]) {
            $this->poolResto[] = $buat($n, $hp);
        }
        // Beberapa tamu tetap juga mampir menginap di riwayat acak
        $this->poolTamu[] = $this->pelanggan['hasan'];
        $this->poolTamu[] = $this->pelanggan['agus'];

        // Jadwal perawatan rutin relatif terhadap hari ini: satu terlambat, satu segera
        $ac = DB::table('facilities')->where('name', 'AC')->value('id');
        if ($ac) {
            MaintenanceSchedule::where('facility_id', $ac)->where('area_label', 'Lantai 2')
                ->update(['last_maintenance_date' => $this->h(-193)->toDateString(), 'next_due_date' => $this->h(-10)->toDateString()]);
            MaintenanceSchedule::where('facility_id', $ac)->where('area_label', 'Lantai 1')
                ->update(['last_maintenance_date' => $this->h(-178)->toDateString(), 'next_due_date' => $this->h(5)->toDateString()]);
            MaintenanceSchedule::where('facility_id', $ac)->where('area_label', 'Aula')
                ->update(['last_maintenance_date' => $this->h(-110)->toDateString(), 'next_due_date' => $this->h(-20)->toDateString()]);
        }
    }

    /* =================================================================== Penjadwal kejadian */

    /** Tanggal relatif: h(-3, '15:00') = tiga hari lalu pukul 15.00. */
    private function h(int $hari, string $jam = '00:00'): Carbon
    {
        return $this->hari->copy()->addDays($hari)->setTimeFromTimeString($jam);
    }

    /** Waktu hari ini $menit sebelum seeder dijalankan (tidak sebelum pukul 00.20). */
    private function tadi(int $menit): Carbon
    {
        return $this->sekarang->copy()->subMinutes($menit)->max($this->hari->copy()->setTime(0, 20));
    }

    /** Majukan jam simulasi di dalam satu kejadian, tidak melewati saat seeder dijalankan. */
    private function majuKe(Carbon $t): void
    {
        Carbon::setTestNow($t->copy()->min($this->sekarang));
    }

    private function pada(Carbon $t, string $label, callable $aksi): void
    {
        $this->antrean->insert([$t->copy(), $label, $aksi], [-$t->getTimestamp(), -$this->urut++]);
    }

    private function jalankan(): void
    {
        while (! $this->antrean->isEmpty()) {
            [$t, $label, $aksi] = $this->antrean->extract();
            if ($t->gt($this->sekarang)) {
                continue;
            }
            Carbon::setTestNow($t);
            try {
                DB::transaction(fn () => $aksi());
                $this->jalan++;
            } catch (Throwable $e) {
                $this->gagal[] = $t->format('d M H:i').' '.$label.': '.Str::limit($e->getMessage(), 160);
            }
        }
    }

    private function info(string $pesan): void
    {
        $this->command?->info($pesan);
    }

    private function acak(array $pilihan): mixed
    {
        return $pilihan[mt_rand(0, count($pilihan) - 1)];
    }

    private function jamAcak(string $dari, string $sampai): string
    {
        [$h1, $m1] = array_map('intval', explode(':', $dari));
        [$h2, $m2] = array_map('intval', explode(':', $sampai));
        $menit = mt_rand($h1 * 60 + $m1, $h2 * 60 + $m2);

        return sprintf('%02d:%02d', intdiv($menit, 60), $menit % 60);
    }

    /* =================================================================== Kegiatan harian rutin */

    private function jadwalHarian(): void
    {
        for ($d = -self::RIWAYAT; $d <= 0; $d++) {
            $hari = $d;
            // 00.05 penjadwal reservasi (tidak dijalankan hari ini, supaya tamu kemarin yang belum datang tetap tampil di kartu FO)
            if ($d < 0) {
                $this->pada($this->h($d, '00:05'), 'penjadwal reservasi', function () {
                    app(ReservasiService::class)->batalkanKedaluwarsa();
                    app(ReservasiService::class)->tandaiTidakDatang();
                });
            }
            // 00.10 penagihan harian (nota aula instansi, nota periode, draft tagihan bulanan, overdue)
            $this->pada($this->h($d, '00:10'), 'penagihan harian', fn () => app(PiutangService::class)->harian());
            // 07.30 pengingat penagihan (20 hari terakhir)
            if ($d >= -20) {
                $this->pada($this->h($d, '07:30'), 'pengingat penagihan', fn () => app(PengingatPenagihan::class)->jalankan());
            }
            // 09.00 Manager menerbitkan draft tagihan bulanan
            $this->pada($this->h($d, '09:00'), 'terbitkan tagihan bulanan', function () {
                BillingStatement::where('status', 'draft')->get()
                    ->each(fn ($s) => app(TagihanBulananService::class)->terbitkan($s, $this->u['manager']));
            });
            // 10.00 instansi membayar tagihan bulanan 18 hari setelah terbit (kecuali yang terbit dalam 20 hari terakhir)
            $this->pada($this->h($d, '10:00'), 'instansi membayar tagihan bulanan', function () {
                BillingStatement::whereIn('status', ['issued', 'overdue', 'partially_paid'])
                    ->whereDate('issue_date', '<=', today()->subDays(18))
                    ->whereDate('issue_date', '<', $this->h(-20)->toDateString())
                    ->get()->each(function (BillingStatement $s) {
                        $sisa = (float) $s->total_amount - (float) $s->paid_amount;
                        if ($sisa > 0) {
                            app(PembayaranService::class)->catatTagihanBulanan($s, $sisa, 'bank_transfer', $this->u['manager'],
                                'Transfer Bank Mandiri ref '.strtoupper(Str::random(8)));
                        }
                    });
            });
            // 12.00 pembersihan kamar kotor (7 hari terakhir lewat pembagian tugas Greedy)
            if ($d < 0) {
                $this->pada($this->h($d, '12:00'), 'pembersihan kamar', fn () => $d >= -7 ? $this->bersihkanDenganTugas() : $this->bersihkanLangsung());
            }
        }
    }

    /** Kamar kosong kotor dibersihkan langsung oleh staf HK (riwayat lama). */
    private function bersihkanLangsung(): void
    {
        $rs = app(RoomStatusService::class);
        $hk = $this->u['hk'];
        Room::where('status', RoomStatus::VacantDirty->value)->orderBy('dirty_since')->get()->each(function (Room $r, $i) use ($rs, $hk) {
            $staf = $hk[$i % count($hk)];
            $rs->change($r, RoomStatus::Cleaning, null, $staf);
            $rs->change($r, RoomStatus::Available, null, $staf);
        });
    }

    /** Smart Cleaning lengkap: hitung prioritas, bagi tugas Greedy, staf mengerjakan, Manager memverifikasi. */
    private function bersihkanDenganTugas(): void
    {
        if (Room::whereIn('status', [RoomStatus::VacantDirty->value, RoomStatus::OccupiedDirty->value])->doesntExist()) {
            return;
        }
        $mulai = now()->copy();
        app(PrioritasPembersihanService::class)->hitungBatch($this->u['manager']);
        app(PembagianTugasService::class)->simpan($this->u['manager'], today());

        $tugas = HousekeepingTask::with('assignees', 'room')->whereDate('task_date', today())->where('status', 'assigned')->get()
            ->groupBy(fn ($t) => $t->assignees->first()?->user_id);
        $pakai = app(TugasHousekeepingService::class);
        foreach ($tugas as $userId => $daftar) {
            $staf = User::find($userId);
            $t0 = $mulai->copy()->addMinutes(10);
            foreach ($daftar as $t) {
                $this->majuKe($t0);
                $pakai->mulai($t, $staf);
                $this->majuKe($t0->addMinutes(mt_rand(20, 35)));
                $pakai->selesai($t->fresh(), $staf);
                $t0->addMinutes(5);
            }
        }
        $this->majuKe($mulai->copy()->addHours(3));
        HousekeepingTask::whereDate('task_date', today())->where('status', 'completed')->get()
            ->each(fn ($t) => app(PembagianTugasService::class)->verifikasi($t, $this->u['manager']));
    }

    /* =================================================================== Pembantu menginap */

    private function tipe(string $kode): int
    {
        return (int) collect($this->kamar)->first(fn (Room $r) => $r->roomType->code === $kode)->room_type_id;
    }

    /** Kamar Siap bertipe sama yang bebas untuk rentang itu; kamar yang disukai didahulukan. */
    private function pilihKamar(int $tipeId, Carbon $masuk, Carbon $keluar, array $suka = [], ?int $kecuali = null, bool $acak = true): ?Room
    {
        $bebas = app(KetersediaanKamar::class);
        $calon = Room::where('room_type_id', $tipeId)->where('is_active', true)->where('status', RoomStatus::Available->value)->get()
            ->filter(fn (Room $r) => ! ($acak && in_array($r->room_number, self::KAMAR_SKENARIO, true)) || in_array($r->room_number, $suka, true))
            ->filter(fn (Room $r) => $bebas->bebas($r->id, $masuk, $keluar, $kecuali));
        foreach ($suka as $nomor) {
            if ($k = $calon->firstWhere('room_number', $nomor)) {
                return $k;
            }
        }

        return $calon->isEmpty() ? null : $calon->values()[mt_rand(0, $calon->count() - 1)];
    }

    /** Tamu datang langsung: reservasi walk-in lalu check-in. */
    private function walkIn(stdClass $s): void
    {
        $masuk = today();
        $keluar = today()->addDays($s->malam);
        $room = $s->nomor ? Room::where('room_number', $s->nomor)->first() : $this->pilihKamar($s->tipe, $masuk, $keluar, $s->suka ?? []);
        if (! $room || $room->status !== RoomStatus::Available) {
            throw new RuntimeException('tidak ada kamar siap');
        }
        $svc = app(ReservasiService::class);
        $s->r = $svc->buatLangsung($s->tamu, $masuk, $keluar, [$room->id], $this->u['fo']);
        $svc->checkIn($s->r->fresh(), [], true, $s->tamu->full_name, $this->u['fo']);
    }

    private function pesanOnline(stdClass $s): void
    {
        $s->r = app(ReservasiService::class)->buatOnline($s->tamu, $s->masuk, $s->keluar, [$s->tipe => 1], $s->permintaan ?? null);
    }

    /** Uang muka dibayar lewat Midtrans (status settlement disimulasikan). */
    private function bayarDp(stdClass $s): void
    {
        $r = $s->r?->fresh();
        if (! $r || $r->status !== 'pending_payment') {
            return;
        }
        app(PembayaranMidtrans::class)->terapkan([
            'order_id' => $r->awalanMidtrans().'-'.strtoupper(Str::random(4)),
            'transaction_id' => (string) Str::uuid(),
            'transaction_status' => 'settlement',
            'gross_amount' => (string) $r->jumlahBayar(),
            'payment_type' => $this->acak(['qris', 'bank_transfer', 'echannel', 'qris']),
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function checkIn(stdClass $s): void
    {
        $r = $s->r?->fresh(['rooms']);
        if (! $r || $r->status !== 'confirmed') {
            return;
        }
        // Kamar yang sudah ditetapkan tetapi masih kotor (tamu sebelumnya keluar terlambat) dibersihkan dulu
        foreach ($r->rooms->where('status', 'booked')->whereNotNull('room_id') as $b) {
            $room = Room::find($b->room_id);
            if ($room->status === RoomStatus::VacantDirty) {
                app(RoomStatusService::class)->change($room, RoomStatus::Cleaning, null, $this->u['hk'][0]);
                app(RoomStatusService::class)->change($room, RoomStatus::Available, null, $this->u['hk'][0]);
            }
        }
        $penempatan = [];
        $dipakai = [];
        foreach ($r->rooms->where('status', 'booked') as $b) {
            if ($b->room_id) {
                continue;
            }
            $room = $this->pilihKamar($b->room_type_id, $b->check_in_date, $b->check_out_date, $s->suka ?? [], $r->id);
            if ($room && in_array($room->id, $dipakai, true)) {
                $room = null;
            }
            if (! $room) {
                throw new RuntimeException('tidak ada kamar siap untuk '.$r->reservation_code);
            }
            $penempatan[$b->id] = $room->id;
            $dipakai[] = $room->id;
        }
        $instansi = $r->instansi();
        app(ReservasiService::class)->checkIn($r, $penempatan, ! $instansi, $instansi ? null : $r->customer->full_name, $this->u['fo'], $instansi ? $r->access_token : null);
    }

    /** Check-out semua kamar; tamu perorangan melunasi sisa tagihan lebih dulu. */
    private function checkOut(stdClass $s, array $opsi = []): void
    {
        $r = $s->r?->fresh();
        if (! $r || $r->status !== 'checked_in') {
            return;
        }
        $opsi += ['ktp_kembali' => true, 'keluar_awal' => 'dipakai'];
        $folio = app(FolioService::class);
        if (! $r->instansi()) {
            $nota = $folio->notaMenginap($r);
            if ($nota) {
                // Layar check-out memberi folio daftar id baris yang dibebaskan, sedangkan proses() menerima id => alasan
                $opsiFolio = ['bebas_terlambat' => array_keys($opsi['bebas_terlambat'] ?? [])] + $opsi;
                $sisa = app(PembayaranService::class)->sisaTagihan($nota, $opsiFolio);
                if ($sisa > 0) {
                    app(PembayaranService::class)->catatManual($nota, $sisa, $s->metode ?? $this->metodeAcak(), 'settlement', $this->u['fo'], 'Pelunasan saat check-out');
                }
            }
        }
        $ids = ReservationRoom::where('reservation_id', $r->id)->where('status', 'checked_in')->pluck('id')->all();
        app(CheckOutService::class)->proses($r, $ids, $opsi, $this->u['fo']);
    }

    private function metodeAcak(): string
    {
        return $this->acak(['cash', 'cash', 'qris', 'qris', 'debit_card', 'bank_transfer']);
    }

    /** Pesanan makanan dari kamar (masuk tagihan kamar), lalu dimasak, siap, dan diantar. */
    private function pesanKamar(stdClass $s, array $item, ?string $nomor = null, string $tahap = 'delivered'): void
    {
        $r = $s->r?->fresh();
        if (! $r || $r->status !== 'checked_in') {
            return;
        }
        $kamar = ReservationRoom::where('reservation_id', $r->id)->where('status', 'checked_in')
            ->when($nomor, fn ($q) => $q->whereHas('room', fn ($k) => $k->where('room_number', $nomor)))->first();
        if (! $kamar) {
            return;
        }
        $o = app(PesananMakananService::class)->buatDariKasir($this->baris($item), $kamar, null, 'room_service', true, null, null, $this->u['fnb']);
        $this->majukan($o, $tahap);
    }

    private function baris(array $item): array
    {
        return collect($item)->map(fn ($qty, $kode) => ['id' => $this->menu[$kode]->id, 'qty' => $qty])->values()->all();
    }

    /** Pesanan berjalan sampai tahap tertentu dengan jeda waktu yang wajar. */
    private function majukan(ServiceOrder $o, string $tahap): void
    {
        $svc = app(PesananMakananService::class);
        $t = now()->copy();
        $urutan = ['in_progress' => 'mulai', 'ready' => 'siap', 'delivered' => 'serahkan'];
        $jeda = ['in_progress' => 4, 'ready' => mt_rand(12, 22), 'delivered' => 6];
        foreach ($urutan as $status => $metode) {
            $this->majuKe($t->addMinutes($jeda[$status]));
            $svc->{$metode}($o->fresh());
            if ($status === $tahap) {
                break;
            }
        }
    }

    /** Laundry tamu menginap (masuk tagihan kamar): dijemput, ditimbang, siap, diantar. */
    private function laundryKamar(stdClass $s, float $berat, string $tahap = 'delivered'): void
    {
        $r = $s->r?->fresh();
        if (! $r || $r->status !== 'checked_in') {
            return;
        }
        $kamar = ReservationRoom::where('reservation_id', $r->id)->where('status', 'checked_in')->first();
        $svc = app(LaundryService::class);
        $o = $svc->terima($r->customer, $kamar, true, $berat, null, $this->u['fo']);
        if ($tahap === 'pending') {
            return;
        }
        $this->majuKe(now()->addMinutes(90));
        $o = $svc->timbang($o->fresh(), $berat, $this->acak($this->u['hk']));
        if ($tahap === 'in_progress') {
            return;
        }
        $this->majuKe(now()->addHours(6));
        $svc->siap($o->fresh());
        if ($tahap === 'ready') {
            return;
        }
        $this->majuKe(now()->addMinutes(40));
        $svc->serahkan($o->fresh());
    }

    /* =================================================================== Riwayat acak */

    private function riwayatAcak(): void
    {
        $tipe = fn () => $this->acak(['STD', 'STD', 'STD', 'STD', 'DLX', 'DLX', 'DLX', 'FAM', 'VIP']);
        for ($d = -self::RIWAYAT; $d <= -self::AKHIR_ACAK - 1; $d++) {
            // Tamu datang langsung: 1–4 per hari, lebih ramai di akhir pekan
            $n = mt_rand(1, 3) + ($this->h($d)->isWeekend() ? 1 : 0);
            for ($i = 0; $i < $n; $i++) {
                $malam = mt_rand(1, 3);
                if ($d + $malam > -self::AKHIR_ACAK) {
                    continue;
                }
                $s = (object) ['tamu' => $this->acak($this->poolTamu), 'tipe' => $this->tipe($tipe()), 'malam' => $malam, 'nomor' => null, 'r' => null];
                $this->stay($s, $d, 'walkin');
            }
            // Pesan online: 0–2 per hari, dipesan 2–14 hari sebelumnya
            $n = mt_rand(0, 2);
            for ($i = 0; $i < $n; $i++) {
                $malam = mt_rand(1, 4);
                if ($d + $malam > -self::AKHIR_ACAK) {
                    continue;
                }
                $s = (object) ['tamu' => $this->acak($this->poolTamu), 'tipe' => $this->tipe($tipe()), 'malam' => $malam, 'r' => null,
                    'masuk' => $this->h($d), 'keluar' => $this->h($d + $malam), 'pesan' => $this->h($d - mt_rand(2, 14), $this->jamAcak('08:00', '21:30'))];
                $nasib = mt_rand(1, 100);
                $this->stay($s, $d, $nasib <= 8 ? 'tidak_bayar' : ($nasib <= 12 ? 'tidak_datang' : 'online'));
            }
        }
    }

    /**
     * Jadwalkan satu masa menginap perorangan beserta pesanan makanan, laundry, dan check-out.
     *
     * @param  string  $jenis  walkin | online | tidak_bayar | tidak_datang
     */
    private function stay(stdClass $s, int $d, string $jenis, array $opsi = []): void
    {
        $malam = $s->malam;
        if ($jenis === 'walkin') {
            $this->pada($this->h($d, $opsi['jam_masuk'] ?? $this->jamAcak('14:05', '21:00')), 'walk-in', fn () => $this->walkIn($s));
        } else {
            $this->pada($s->pesan, 'pesan online', fn () => $this->pesanOnline($s));
            if ($jenis !== 'tidak_bayar') {
                $this->pada($s->pesan->copy()->addMinutes(mt_rand(15, 180)), 'uang muka', fn () => $this->bayarDp($s));
            }
            if ($jenis !== 'online') {
                return;
            }
            $this->pada($this->h($d, $opsi['jam_masuk'] ?? $this->jamAcak('14:00', '20:30')), 'check-in online', fn () => $this->checkIn($s));
        }

        // Pesanan makanan malam pertama (60%), laundry hari kedua (30% bila menginap ≥ 2 malam)
        $makan = $opsi['makan'] ?? (mt_rand(1, 100) <= 60 ? [$this->acak(['MKN03', 'MKN04', 'IDM05', 'MKN09', 'PKT02']) => 1, $this->acak(['TEH02', 'KOP02', 'JUS01', 'KOP01']) => 1] : null);
        if ($makan) {
            $this->pada($this->h($d, $this->jamAcak('19:00', '21:30')), 'pesan makanan kamar', fn () => $this->pesanKamar($s, $makan));
        }
        if ($malam >= 2 && ($opsi['laundry'] ?? (mt_rand(1, 100) <= 30))) {
            $this->pada($this->h($d + 1, '08:15'), 'laundry kamar', fn () => $this->laundryKamar($s, round(mt_rand(20, 55) / 10, 1)));
        }
        if (! ($opsi['tanpa_keluar'] ?? false)) {
            $this->pada($this->h($d + $malam, $opsi['jam_keluar'] ?? (mt_rand(1, 100) <= 6 ? $this->jamAcak('12:20', '13:30') : $this->jamAcak('08:30', '11:45'))),
                'check-out', fn () => $this->checkOut($s, $opsi['opsi_keluar'] ?? []));
        }
    }

    /* =================================================================== Pelanggan tetap (CRM) */

    private function skenarioPelangganTetap(): void
    {
        // Citra: 3 dari 4 kunjungan di Kamar 205, selalu Teh Manis + Nasi Goreng Spesial, laundry di 3 kunjungan
        foreach ([[-70, 2, ['205'], true], [-48, 3, ['205'], true], [-26, 2, ['207'], false], [-9, 2, ['205'], true]] as [$d, $malam, $suka, $laundry]) {
            $s = (object) ['tamu' => $this->pelanggan['citra'], 'tipe' => $this->tipe('DLX'), 'malam' => $malam, 'suka' => $suka, 'r' => null,
                'masuk' => $this->h($d), 'keluar' => $this->h($d + $malam), 'pesan' => $this->h($d - 6, '20:15'), 'metode' => 'qris'];
            $this->stay($s, $d, 'online', ['makan' => ['TEH02' => 2, 'MKN04' => 1], 'laundry' => $laundry, 'jam_masuk' => '15:10', 'jam_keluar' => '10:30']);
        }
        // Budi: tiga kunjungan di Kamar 203
        foreach ([-60, -35, -14] as $d) {
            $s = (object) ['tamu' => $this->pelanggan['budi'], 'tipe' => $this->tipe('DLX'), 'malam' => 2, 'suka' => ['203'], 'r' => null,
                'masuk' => $this->h($d), 'keluar' => $this->h($d + 2), 'pesan' => $this->h($d - 4, '19:40'), 'metode' => 'debit_card'];
            $this->stay($s, $d, 'online', ['makan' => ['KOP02' => 1, 'MKN03' => 1], 'laundry' => false]);
        }
        // Reservasi online yang dibatalkan tamu dan dananya sudah dikembalikan
        $s = (object) ['tamu' => $this->poolTamu[3], 'tipe' => $this->tipe('DLX'), 'malam' => 2, 'r' => null,
            'masuk' => $this->h(-38), 'keluar' => $this->h(-36), 'pesan' => $this->h(-50, '10:00')];
        $this->stay($s, -38, 'tidak_datang');
        $this->pada($this->h(-45, '09:30'), 'tamu membatalkan reservasi', fn () => $s->r && app(ReservasiService::class)->batalkan($s->r->fresh(), 'Jadwal dinas berubah', $this->u['fo']));
        $this->pada($this->h(-44, '11:00'), 'dana dikembalikan', function () use ($s) {
            $p = Payment::where('transaction_type', 'refund')->where('status', 'pending')
                ->whereIn('invoice_id', $s->r->invoices()->select('id'))->first();
            $p && app(PembayaranService::class)->selesaikanRefund($p, 'bank_transfer', $this->u['manager'], 'Transfer ke rekening tamu');
        });
    }

    /* =================================================================== Instansi */

    private function skenarioInstansi(): void
    {
        // [kode, PIC, hari masuk, malam, kamar, harga per malam, kegiatan, menu rombongan]
        $rombongan = [
            ['VALE', -65, 3, ['101', '102', '104', '106', '107'], 330000, 'Audit lingkungan tambang'],
            ['DINKES', -55, 2, ['108', '109', '110'], 340000, 'Pelatihan kader posyandu'],
            ['PLN', -45, 2, ['111', '112'], 420000, 'Inspeksi gardu induk'],
            ['VALE', -38, 4, ['101', '102', '104', '106', '107', '108'], 330000, 'Pelatihan K3 kontraktor'],
            ['DINKES', -30, 3, ['113', '114'], 420000, 'Supervisi puskesmas'],
            ['VALE', -17, 2, ['201', '202', '204', '206'], 410000, 'Kunjungan direksi'],
            ['PLN', -6, 3, ['111', '115'], 420000, 'Pemeliharaan jaringan Malili'],
        ];
        foreach ($rombongan as [$kode, $d, $malam, $nomor, $harga, $kegiatan]) {
            $this->rombongan($kode, $d, $malam, $nomor, $harga, $kegiatan);
        }

        // Pembayaran nota kredit instansi (jatuh tempo setelah check-out)
        $bayarNota = function (string $kode, int $hariMasuk) {
            $nota = Invoice::where('invoice_type', 'stay')->where('payment_term', 'credit')
                ->whereHas('reservation', fn ($q) => $q->where('institution_id', $this->instansi[$kode]->id)
                    ->whereHas('rooms', fn ($k) => $k->whereDate('check_in_date', $this->h($hariMasuk)->toDateString())))->first();
            if ($nota && $nota->sisa() > 0) {
                app(PembayaranService::class)->catatManual($nota, $nota->sisa(), 'bank_transfer', 'settlement', $this->u['manager'], 'Transfer dari '.$this->instansi[$kode]->name);
            }
        };
        $this->pada($this->h(-38, '10:20'), 'PLN membayar nota', fn () => $bayarNota('PLN', -45));
        $this->pada($this->h(-15, '13:40'), 'Dinkes membayar nota', fn () => $bayarNota('DINKES', -30));
        // Nota Dinkes -55 sengaja belum dibayar (lewat jatuh tempo); nota PLN -6 jatuh tempo beberapa hari lagi

        // Manager memeriksa dan mengirim pengingat ke instansi pada H-10
        $this->pada($this->h(-10, '10:30'), 'manager kirim pengingat instansi', function () {
            BillingReminder::where('status', 'pending')->where('reminder_stage', 'to_institution')->get()->each(function (BillingReminder $p) {
                $t = $p->tagihan();
                $i = $t instanceof BillingStatement ? $t->institution : $t->institution;
                $kontak = $i?->primaryContact;
                app(PengingatPenagihan::class)->periksaDanKirim($p, ['email', 'whatsapp'], $kontak?->email ?? $i?->email, $kontak?->phone, $this->u['manager']);
            });
        });
    }

    /** Pengajuan instansi → disetujui Manager → check-in dengan kode bukti → pesanan rombongan → check-out. */
    private function rombongan(string $kode, int $d, int $malam, array $nomor, int $harga, string $kegiatan, bool $keluar = true): stdClass
    {
        $s = (object) ['r' => null];
        $i = $this->instansi[$kode];
        $this->pada($this->h($d - 21, '09:15'), "pengajuan {$kode}", function () use ($s, $i, $d, $malam, $nomor, $kegiatan) {
            $s->r = app(ReservasiService::class)->ajukanInstansi($i->primaryContact, $i, $this->h($d), $this->h($d + $malam), count($nomor), $kegiatan, null);
        });
        $this->pada($this->h($d - 20, '10:00'), "persetujuan {$kode}", function () use ($s, $i, $nomor, $harga) {
            $kamar = collect($nomor)->map(fn ($n) => ['room_id' => $this->kamar[$n]->id, 'price' => $harga])->all();
            app(ReservasiService::class)->setujui($s->r->fresh(), $i, $kamar, null, 'satu_nota', 'Harga sesuai kesepakatan kerja sama', $this->u['manager']);
        });
        $this->pada($this->h($d, '15:30'), "check-in {$kode}", fn () => $this->checkIn($s));
        for ($n = 0; $n < $malam; $n++) {
            foreach (array_slice($nomor, 0, (int) ceil(count($nomor) / 2)) as $no) {
                $this->pada($this->h($d + $n, $this->jamAcak('18:30', '19:30')), "makan malam {$kode}",
                    fn () => $this->pesanKamar($s, [$this->acak(['PKT02', 'PKT02', 'PKT01']) => 2, 'TEH02' => 2], $no));
            }
        }
        if ($malam >= 2) {
            $this->pada($this->h($d + 1, '08:30'), "laundry {$kode}", fn () => $this->laundryKamar($s, round(mt_rand(30, 60) / 10, 1)));
        }
        if ($keluar) {
            $this->pada($this->h($d + $malam, '10:45'), "check-out {$kode}", fn () => $this->checkOut($s));
        }

        return $s;
    }

    /* =================================================================== Keadaan hari ini */

    private function skenarioHariIni(): void
    {
        $p = $this->pelanggan;
        $online = fn (string $k, string $tipe, int $d, int $malam, int $pesan, array $suka = []) => (object) [
            'tamu' => $p[$k], 'tipe' => $this->tipe($tipe), 'malam' => $malam, 'suka' => $suka, 'r' => null,
            'masuk' => $this->h($d), 'keluar' => $this->h($d + $malam), 'pesan' => $this->h($pesan, $this->jamAcak('08:00', '21:00')),
        ];

        // Rombongan PT Vale sedang menginap (H-2 s.d. H+3), tagihan bulanan
        $vale = $this->rombongan('VALE', -2, 5, ['101', '102', '104', '106', '107', '108'], 330000, 'Rapat koordinasi CSR', false);
        $this->pada($this->tadi(150), 'kamar Vale minta dibersihkan', function () {
            foreach (['101', '102'] as $n) {
                app(RoomStatusService::class)->change(Room::where('room_number', $n)->first(), RoomStatus::OccupiedDirty, null, $this->u['hk'][0]);
            }
        });
        $this->pada($this->tadi(28), 'pesanan Vale sedang dimasak', fn () => $this->pesanKamar($vale, ['MKN04' => 2, 'KOP02' => 2], '101', 'in_progress'));

        // Tamu perorangan yang menginap
        $hasan = (object) ['tamu' => $p['hasan'], 'tipe' => $this->tipe('DLX'), 'malam' => 1, 'nomor' => '111', 'r' => null];
        $this->stay($hasan, -1, 'walkin', ['jam_masuk' => '16:20', 'makan' => ['KOP02' => 1, 'IDM05' => 1], 'laundry' => false, 'tanpa_keluar' => true]);
        $this->pada($this->h(-1, '18:00'), 'laundry Hasan', fn () => $this->laundryKamar($hasan, 3.2, 'ready'));

        $lukas = $online('lukas', 'DLX', -2, 2, -10, ['112']);
        $this->stay($lukas, -2, 'online', ['makan' => ['MKN09' => 1, 'JUS01' => 1], 'laundry' => false, 'tanpa_keluar' => true]);

        $rizky = $online('rizky', 'STD', -2, 4, -6, ['105']);
        $this->stay($rizky, -2, 'online', ['makan' => null, 'laundry' => false, 'tanpa_keluar' => true]);
        $this->pada($this->tadi(70), 'laundry Rizky dijemput', fn () => $this->laundryKamar($rizky, 4.0, 'pending'));

        $budi = $online('budi', 'DLX', -1, 2, -8, ['203']);
        $this->stay($budi, -1, 'online', ['makan' => ['KOP02' => 1, 'MKN03' => 1], 'laundry' => false, 'tanpa_keluar' => true]);
        $this->pada($this->tadi(22), 'pesanan Budi siap diantar', fn () => $this->pesanKamar($budi, ['KOP02' => 1, 'CML09' => 1], null, 'ready'));

        $agus = (object) ['tamu' => $p['agus'], 'tipe' => $this->tipe('FAM'), 'malam' => 4, 'nomor' => '210', 'r' => null];
        $this->stay($agus, -3, 'walkin', ['jam_masuk' => '17:45', 'makan' => ['PKT01' => 2, 'TEH02' => 2], 'laundry' => true, 'tanpa_keluar' => true]);

        // Check-out kemarin melewati jam 12.00: kamarnya masih kotor pagi ini (bahan Smart Cleaning)
        $telat = [
            ['rahmawati', 'STD', '109', -3, '13:15', []],
            ['wahyu', 'DLX', '114', -2, '13:40', ['bebas' => 'Menunggu jemputan bus ke Makassar']],
            ['fitri', 'DLX', '207', -2, '13:20', []],
            ['gilang', 'FAM', '214', -3, '14:05', []],
        ];
        foreach ($telat as [$k, $tipe, $nomor, $d, $jam, $x]) {
            $s = (object) ['tamu' => $p[$k], 'tipe' => $this->tipe($tipe), 'malam' => -1 - $d, 'nomor' => $nomor, 'r' => null];
            $opsiKeluar = [];
            if (isset($x['bebas'])) {
                $opsiKeluar = ['bebas_terlambat' => ['__semua__' => $x['bebas']]];
            }
            $this->stay($s, $d, 'walkin', ['jam_masuk' => '15:00', 'laundry' => false, 'tanpa_keluar' => true]);
            $this->pada($this->h(-1, $jam), 'check-out terlambat', function () use ($s, $opsiKeluar) {
                if (isset($opsiKeluar['bebas_terlambat']['__semua__'])) {
                    $alasan = $opsiKeluar['bebas_terlambat']['__semua__'];
                    $opsiKeluar['bebas_terlambat'] = ReservationRoom::where('reservation_id', $s->r?->id)->pluck('id')
                        ->mapWithKeys(fn ($id) => [$id => $alasan])->all();
                }
                $this->checkOut($s, $opsiKeluar);
            });
        }
        $this->pada($this->tadi(18), 'HK mulai membersihkan 214', function () {
            app(RoomStatusService::class)->change(Room::where('room_number', '214')->first(), RoomStatus::Cleaning, null, $this->u['hk'][0]);
        });

        // Kedatangan hari ini
        $citra = $online('citra', 'DLX', 0, 2, -5, []);
        $this->stay($citra, 0, 'tidak_datang');
        $this->rombonganDatangHariIni();

        // Kemarin dijadwalkan datang, belum muncul (kartu awas FO)
        $yudi = $online('yudi', 'STD', -1, 2, -4);
        $this->stay($yudi, -1, 'tidak_datang');

        // Menunggu uang muka
        $dian = $online('dian', 'STD', 3, 2, 0);
        $dian->pesan = $this->tadi(120);
        $this->stay($dian, 3, 'tidak_bayar');

        // Dibatalkan tamu H-2: dana 50% uang muka menunggu dikembalikan
        $abdul = $online('abdul', 'DLX', 4, 2, -6);
        $this->stay($abdul, 4, 'tidak_datang');
        $this->pada($this->h(-2, '11:10'), 'Abdul membatalkan', fn () => $abdul->r && app(ReservasiService::class)->batalkan($abdul->r->fresh(), 'Acara keluarga ditunda', $this->u['fo']));

        // Reservasi mendatang (kalender)
        foreach ([['wahyuni', 'STD', 1, 2, -3], ['fikri', 'DLX', 3, 3, -2], ['nurul', 'FAM', 6, 2, -1], ['andi', 'VIP', 9, 2, -2], ['rosa', 'STD', 11, 1, -1]] as [$k, $t, $d, $m, $ps]) {
            $this->stay($online($k, $t, $d, $m, $ps), $d, 'tidak_datang');
        }

        // Pengajuan instansi menunggu persetujuan (PLN sudah dua kali datang: pola rombongan tampil)
        $this->pada($this->h(-1, '14:30'), 'pengajuan PLN baru', function () {
            $i = $this->instansi['PLN'];
            app(ReservasiService::class)->ajukanInstansi($i->primaryContact, $i, $this->h(7), $this->h(10), 4, 'Pemeliharaan gardu Wasuponda', 'Mohon kamar berdekatan');
        });

        // Ketersediaan staf housekeeping hari ini
        $this->pada($this->tadi(240), 'ketersediaan staf', function () {
            foreach ($this->u['hk'] as $i => $staf) {
                StaffAvailability::updateOrCreate(['user_id' => $staf->id, 'work_date' => today()->toDateString()], match (true) {
                    $i === 1 => ['is_available' => false, 'note' => 'Izin keperluan keluarga'],
                    $staf->roles->pluck('code')->contains('fnb') && $i === 2 => ['is_available' => true, 'note' => 'Bantu F&B sarapan'],
                    default => ['is_available' => true],
                });
            }
        });
    }

    /** Dinas Kesehatan datang hari ini; salah satu kamarnya masih kotor dari check-out kemarin. */
    private function rombonganDatangHariIni(): void
    {
        $s = (object) ['r' => null];
        $i = $this->instansi['DINKES'];
        $this->pada($this->h(-9, '09:40'), 'pengajuan Dinkes', function () use ($s, $i) {
            $s->r = app(ReservasiService::class)->ajukanInstansi($i->primaryContact, $i, $this->h(0), $this->h(2), 3, 'Monitoring program imunisasi', null);
        });
        $this->pada($this->h(-8, '11:00'), 'persetujuan Dinkes', function () use ($s, $i) {
            $kamar = collect(['207', '209', '213'])->map(fn ($n) => ['room_id' => $this->kamar[$n]->id, 'price' => $n === '213' ? 500000 : 420000])->all();
            app(ReservasiService::class)->setujui($s->r->fresh(), $i, $kamar, null, 'satu_nota', 'Harga instansi pemerintah', $this->u['manager']);
        });
    }

    /* =================================================================== Aula */

    private function skenarioAula(): void
    {
        $aula = Hall::where('is_active', true)->orderBy('id')->first();
        if (! $aula) {
            return;
        }
        $svc = fn () => app(AulaService::class);
        $p = $this->pelanggan;

        // Syukuran perorangan, sudah lunas dan selesai
        $a = (object) ['b' => null];
        $this->pada($this->h(-30, '10:00'), 'pengajuan aula Marlina', function () use ($a, $aula, $p, $svc) {
            $a->b = $svc()->ajukan($p['marlina'], $aula, $this->h(-21, '10:00'), $this->h(-21, '15:00'), 80, 'Syukuran khitanan', 'Susunan meja bundar', null, $this->u['fo']);
        });
        $this->pada($this->h(-29, '09:00'), 'aula Marlina disetujui', fn () => $svc()->setujui($a->b->fresh(), 1200000, null, 'Termasuk sound system', $this->u['manager']));
        $this->pada($this->h(-23, '14:00'), 'aula Marlina dibayar', fn () => app(PembayaranService::class)->catatManual($a->b->fresh()->invoice, 1200000, 'bank_transfer', PembayaranService::tujuan($a->b->fresh()->invoice), $this->u['fo'], 'Pelunasan sewa aula'));
        $this->pada($this->h(-21, '16:00'), 'aula Marlina selesai', fn () => $svc()->selesai($a->b->fresh()));

        // Rapat PT Vale bulan lalu: ditagihkan ke instansi lewat tagihan bulanan
        $v = (object) ['b' => null];
        $this->pada($this->h(-28, '11:00'), 'pengajuan aula Vale', function () use ($v, $aula, $svc) {
            $v->b = $svc()->ajukan($this->instansi['VALE']->primaryContact, $aula, $this->h(-25, '08:00'), $this->h(-25, '16:00'), 60, 'Rapat koordinasi kontraktor', null, null, $this->u['fo']);
        });
        $this->pada($this->h(-27, '09:30'), 'aula Vale disetujui', fn () => $svc()->setujui($v->b->fresh(), 1500000, $this->instansi['VALE'], 'Paket rapat sehari', $this->u['manager']));
        $this->pada($this->h(-25, '17:00'), 'aula Vale selesai', fn () => $svc()->selesai($v->b->fresh()));

        // Rapat PT Vale minggu depan (terkonfirmasi)
        $w = (object) ['b' => null];
        $this->pada($this->h(-4, '13:00'), 'pengajuan aula Vale berikutnya', function () use ($w, $aula, $svc) {
            $w->b = $svc()->ajukan($this->instansi['VALE']->primaryContact, $aula, $this->h(5, '08:00'), $this->h(5, '12:00'), 40, 'Sosialisasi program CSR', null, null, $this->u['fo']);
        });
        $this->pada($this->h(-3, '10:00'), 'aula Vale berikutnya disetujui', fn () => $svc()->setujui($w->b->fresh(), 1000000, $this->instansi['VALE'], null, $this->u['manager']));

        // Pengajuan dari halaman tamu, menunggu harga dan persetujuan
        $this->pada($this->h(-1, '20:10'), 'pengajuan aula Yohanes', fn () => $svc()->ajukan($p['yohanes'], $aula, $this->h(12, '18:00'), $this->h(12, '22:00'), 120, 'Syukuran pernikahan', 'Butuh panggung kecil'));
    }

    /* =================================================================== Maintenance */

    private function skenarioMaintenance(): void
    {
        $svc = fn () => app(MaintenanceService::class);
        $unit = fn (string $label) => FacilityUnit::where('unit_label', $label)->first();
        $room = fn (string $n) => Room::where('room_number', $n)->first();
        $hk = $this->u['hk'];

        // Riwayat: [hari lapor, pelapor, unit/kamar, deskripsi, prioritas, pengerja (vendor|null=staf), biaya, catatan]
        $riwayat = [
            [-60, $hk[1] ?? $hk[0], 'AC 204', null, 'AC tidak dingin, keluar air', 'high', 'CV Sejuk Abadi', 350000, 'Isi freon dan bersihkan saluran pembuangan'],
            [-44, $this->u['fo'], 'Kloset 112', null, 'Kloset mampet', 'medium', null, null, 'Dibersihkan staf, normal kembali'],
            [-33, $this->u['fo'], null, 'Lantai 2', 'WiFi lantai 2 sering putus', 'medium', 'Telkom IndiHome', null, 'Router diganti oleh teknisi penyedia'],
            [-20, $hk[0], 'Water Heater 113', null, 'Air panas tidak keluar', 'medium', 'CV Tirta Sanitasi', 450000, 'Elemen pemanas diganti'],
            [-12, $this->u['fo'], null, null, 'Lampu kamar mandi 206 mati', 'low', null, 25000, 'Bohlam diganti'],
        ];
        foreach ($riwayat as $n => [$d, $pelapor, $label, $area, $desk, $prio, $vendor, $biaya, $catatan]) {
            $l = (object) ['m' => null];
            $this->pada($this->h($d, '08:'.(10 + $n)), 'lapor kerusakan', function () use ($l, $svc, $pelapor, $label, $area, $desk, $prio, $unit, $room) {
                $u = $label ? $unit($label) : null;
                $kamar = (! $label && ! $area) ? $room('206') : null;
                [$l->m] = $svc()->lapor($pelapor, $u, $kamar, $area, $desk, $prio, false);
            });
            $this->pada($this->h($d, '13:00'), 'mulai perbaikan', fn () => $svc()->kerjakan($l->m->fresh(), $vendor ? null : $hk[0], $vendor));
            $this->pada($this->h($d + 1, '11:00'), 'perbaikan selesai', fn () => $svc()->selesaikan($l->m->fresh(), $catatan, $biaya ? (float) $biaya : null, 'vacant_dirty', $this->u['manager']));
        }
        // Laporan ganda dibatalkan
        $g = (object) ['m' => null];
        $this->pada($this->h(-20, '09:05'), 'lapor ganda', function () use ($g, $svc, $unit) {
            [$g->m] = $svc()->lapor($this->u['fnb'], $unit('Water Heater 113'), null, null, 'Shower air panas mati', 'low', false);
        });
        $this->pada($this->h(-20, '10:00'), 'batalkan laporan ganda', fn () => $svc()->batalkan($g->m->fresh(), 'Sama dengan laporan sebelumnya', $this->u['manager']));

        // Jalur cepat: AC 116 dilaporkan, besoknya FO membuka kamar dengan persetujuan Manager (biaya belum dilengkapi)
        $this->pada($this->h(-6, '09:00'), 'lapor AC 116', fn () => $svc()->lapor($hk[0], $unit('AC 116'), null, null, 'AC bocor menetes ke kasur', 'high', true));
        $this->pada($this->h(-5, '14:00'), 'jalur cepat 116', fn () => $svc()->selesaikanCepat($room('116'), $this->u['manager'], $this->u['fo']));
        $this->pada($this->h(-5, '14:30'), 'bersihkan 116', function () use ($room, $hk) {
            $r = $room('116');
            if ($r->status === RoomStatus::VacantDirty) {
                app(RoomStatusService::class)->change($r, RoomStatus::Cleaning, null, $hk[0]);
                app(RoomStatusService::class)->change($r, RoomStatus::Available, null, $hk[0]);
            }
        });

        // Perawatan rutin AC aula tercatat
        $this->pada($this->h(-20, '15:00'), 'perawatan rutin aula', function () {
            $ac = DB::table('facilities')->where('name', 'AC')->value('id');
            $j = MaintenanceSchedule::where('facility_id', $ac)->where('area_label', 'Aula')->first();
            $j && app(MaintenanceService::class)->catatPelaksanaan($j, today(), null, 'CV Sejuk Abadi', 1200000, 'Cuci 4 unit AC aula', $this->u['manager']);
        });

        // Terbuka sekarang
        $k = (object) ['m' => null];
        $this->pada($this->h(-1, '16:00'), 'lapor kloset 208', function () use ($k, $svc, $unit) {
            [$k->m] = $svc()->lapor($this->u['fo2'], $unit('Kloset 208'), null, null, 'Kloset bocor, lantai kamar mandi tergenang', 'high', true);
        });
        $this->pada($this->tadi(120), 'vendor mengerjakan 208', fn () => $svc()->kerjakan($k->m->fresh(), null, 'CV Tirta Sanitasi'));
        $this->pada($this->tadi(170), 'lapor AC 103', fn () => $svc()->lapor($hk[0], $unit('AC 103'), null, null, 'AC tidak dingin, remote tidak merespons', 'high', true));
        $this->pada($this->tadi(55), 'lapor kran dapur', fn () => $svc()->lapor($this->u['fnb'], null, null, 'Dapur restoran', 'Kran wastafel dapur bocor', 'medium', false));
    }

    /* =================================================================== Restoran dan laundry tamu luar */

    private function skenarioRestoran(): void
    {
        $makanan = ['MKN03', 'MKN04', 'MKN05', 'MKN09', 'IDM05', 'IDM08', 'PKT02', 'PKT01', 'MKN10', 'CML01', 'CML09', 'CML10'];
        $minum = ['TEH02', 'KOP02', 'REK02', 'JUS01', 'JUS05', 'KOP01', 'TEH07', 'NCF08'];
        for ($d = -self::RIWAYAT; $d <= -1; $d++) {
            $n = mt_rand(2, 4);
            for ($i = 0; $i < $n; $i++) {
                $item = [$this->acak($makanan) => mt_rand(1, 2), $this->acak($minum) => mt_rand(1, 2)];
                $tamu = $this->acak($this->poolResto);
                $this->pada($this->h($d, $this->jamAcak('10:00', '21:00')), 'pesanan kasir', function () use ($item, $tamu) {
                    $o = app(PesananMakananService::class)->buatDariKasir($this->baris($item), null, $tamu, $this->acak(['dine_in', 'dine_in', 'take_away']),
                        false, $this->acak(['cash', 'cash', 'qris', 'qris', 'debit_card']), null, $this->u['fnb']);
                    $this->majukan($o, 'delivered');
                });
            }
        }
        // Hari ini: satu baru masuk antrean, satu sudah diserahkan
        $this->pada($this->tadi(95), 'pesanan kasir pagi', function () {
            $o = app(PesananMakananService::class)->buatDariKasir($this->baris(['SRP01' => 2, 'KOP02' => 2]), null, $this->poolResto[0], 'dine_in', false, 'cash', null, $this->u['fnb']);
            $this->majukan($o, 'delivered');
        });
        $this->pada($this->tadi(6), 'pesanan kasir baru', fn () => app(PesananMakananService::class)->buatDariKasir($this->baris(['JUS05' => 1, 'CML09' => 1]), null, $this->poolResto[1], 'take_away', false, 'qris', null, $this->u['fnb']));
    }

    private function skenarioLaundryLuar(): void
    {
        for ($d = -self::RIWAYAT + 2; $d <= -2; $d += mt_rand(3, 6)) {
            $tamu = $this->acak($this->poolResto);
            $berat = round(mt_rand(20, 70) / 10, 1);
            $this->pada($this->h($d, $this->jamAcak('09:00', '16:00')), 'laundry tamu luar', function () use ($tamu, $berat) {
                $svc = app(LaundryService::class);
                $o = $svc->terima($tamu, null, false, $berat, null, $this->u['fo']);
                $this->majuKe(now()->addMinutes(30));
                $o = $svc->timbang($o->fresh(), $berat, $this->u['hk'][0]);
                $this->majuKe(now()->addDay()->setTime(10, 0));
                $svc->siap($o->fresh());
                $this->majuKe(now()->addHours(4));
                $svc->bayarTunai($o->fresh(), $this->u['fo']);
                $svc->serahkan($o->fresh());
            });
        }
        // Hari ini: sudah ditimbang, menunggu dibayar saat diambil
        $this->pada($this->tadi(80), 'laundry tamu luar hari ini', function () {
            $svc = app(LaundryService::class);
            $o = $svc->terima($this->poolResto[2], null, false, 5.0, 'Pisahkan baju putih', $this->u['fo']);
            $this->majuKe(now()->addMinutes(25));
            $svc->timbang($o->fresh(), 5.4, $this->u['hk'][0]);
        });
    }

    /* =================================================================== Ringkasan */

    private function ringkasan(): void
    {
        $hitung = fn (string $t) => DB::table($t)->count();
        $this->info('');
        $this->info('Data demo selesai dibuat ('.$this->jalan.' kejadian).');
        $this->command?->table(['Data', 'Jumlah'], [
            ['Reservasi', $hitung('reservations')],
            ['Sedang menginap', Reservation::where('status', 'checked_in')->count()],
            ['Nota', $hitung('invoices')],
            ['Pembayaran', $hitung('payments')],
            ['Pesanan makanan dan laundry', $hitung('service_orders')],
            ['Tagihan bulanan', $hitung('billing_statements')],
            ['Pengingat penagihan', $hitung('billing_reminders')],
            ['Laporan kerusakan', $hitung('maintenance_requests')],
            ['Riwayat status kamar', $hitung('room_status_logs')],
            ['Tugas housekeeping', $hitung('housekeeping_tasks')],
        ]);
        if ($this->gagal) {
            $this->command?->warn(count($this->gagal).' kejadian dilewati (biasanya karena kamar penuh pada hari itu):');
            foreach (array_slice($this->gagal, 0, 25) as $g) {
                $this->command?->line('  - '.$g);
            }
        }
    }
}
