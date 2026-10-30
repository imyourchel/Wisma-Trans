<?php

/*
 * Menyiapkan data pemeriksaan tampilan.
 *
 *   php tools/responsive/siapkan.php [--sumber=ta_3]
 *
 * 1. Menyalin semua tabel database sumber (hanya dibaca) ke database tes wisma_trans_test, supaya halaman
 *    berisi data contoh yang sudah ada. Database tes dikosongkan lagi oleh RefreshDatabase saat php artisan test.
 * 2. Mengganti sandi semua akun DI SALINAN menjadi "password" agar pemeriksa bisa masuk sebagai tiap peran.
 * 3. Menulis storage/app/responsive/rute.json: daftar halaman GET tamu dan staf (route berparameter memakai
 *    data contoh) beserta akun contoh tiap peran.
 */

require __DIR__.'/env.php';
require __DIR__.'/../../vendor/autoload.php';

use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\ServiceOrder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sumber = 'ta_3';
foreach ($argv as $a) {
    if (str_starts_with($a, '--sumber=')) {
        $sumber = substr($a, 9);
    }
}
$tujuan = DB::connection()->getDatabaseName();
// Hanya menulis ke database berakhiran _test; database sumber (ta_3) hanya dibaca (SHOW TABLES dan SELECT)
if ($tujuan !== DB_PEMERIKSAAN || ! str_ends_with($tujuan, '_test') || $tujuan === $sumber || ! preg_match('/^\w+$/', $sumber)) {
    fwrite(STDERR, 'Berhenti: database tujuan harus '.DB_PEMERIKSAAN." (berakhiran _test) dan berbeda dari sumber.\n");
    exit(1);
}

// 1. Salin tabel: buang semua tabel tujuan, lalu CREATE TABLE ... LIKE dan INSERT ... SELECT dari sumber
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
foreach (DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $t) {
    DB::statement('DROP TABLE `'.array_values((array) $t)[0].'`');
}
$tabel = array_map(fn ($t) => array_values((array) $t)[0], DB::select("SHOW FULL TABLES FROM `$sumber` WHERE Table_type = 'BASE TABLE'"));
foreach ($tabel as $t) {
    DB::statement("CREATE TABLE `$t` LIKE `$sumber`.`$t`");
    DB::statement("INSERT INTO `$t` SELECT * FROM `$sumber`.`$t`");
}
DB::statement('SET FOREIGN_KEY_CHECKS = 1');
// migrate:fresh (RefreshDatabase di php artisan test) hanya mengosongkan database bila tabel migrations ada;
// database sumber tidak memilikinya, jadi dibuat kosong di salinan agar tes tetap bisa memulai dari nol
if (! in_array('migrations', $tabel)) {
    DB::statement('CREATE TABLE `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `migration` varchar(255) NOT NULL, `batch` int NOT NULL)');
}
echo count($tabel)." tabel disalin dari $sumber ke $tujuan.\n";

// 2. Sandi akun di salinan
DB::table('users')->update(['password' => Hash::make('password'), 'is_active' => 1]);

// Akun contoh per peran: utamakan pengguna dengan satu peran saja
$akun = [];
foreach (['admin', 'manager', 'front_office', 'fnb', 'housekeeping', 'customer'] as $kode) {
    $email = DB::table('users')->join('user_roles', 'users.id', '=', 'user_roles.user_id')->join('roles', 'roles.id', '=', 'user_roles.role_id')
        ->where('roles.code', $kode)->whereNotNull('users.email')
        ->orderByRaw('(SELECT COUNT(*) FROM user_roles ur WHERE ur.user_id = users.id)')->orderBy('users.id')
        ->value('users.email');
    if ($email) {
        $akun[$kode] = $email;
    }
}

// 3. Daftar halaman
$lewati = ['auth.google', 'auth.google.callback', 'auth.google.lengkapi', 'logout', 'nota.unduh', 'tagihan-bulanan.unduh', 'bukti-instansi.unduh',
    'reservasi.cek', 'pesanan.cek', 'cek.perangkat.buka', 'cek.lihat', 'staf.segera', 'staf.keuangan.tagihan-bulanan.pdf', 'staf.keuangan.surat-tagihan',
    'storage.local', 'storage.local.upload'];
$besok = now()->addDays(7);
$tipe = RoomType::whereHas('rooms', fn ($q) => $q->where('is_active', true))->orderBy('id')->value('code');
$isian = [
    'kamar.tipe' => fn () => route('kamar.tipe', ['kode' => $tipe]),
    'kamar.pesan' => fn () => route('kamar.pesan', ['kode' => $tipe, 'masuk' => $besok->toDateString(), 'keluar' => $besok->addDays(2)->toDateString(), 'tamu' => '2']),
    'reservasi.status' => fn () => Reservation::whereNotNull('access_token')->latest('id')->first()?->urlStatus(),
    'pesanan.status' => fn () => ServiceOrder::whereNotNull('access_token')->latest('id')->get()->first(fn ($o) => $o->jenisLayanan() === 'makanan')?->urlStatus(),
    'laundry.status' => fn () => ServiceOrder::whereNotNull('access_token')->latest('id')->get()->first(fn ($o) => $o->jenisLayanan() === 'laundry')?->urlStatus(),
    'password.reset' => fn () => route('password.reset', ['token' => 'contoh', 'email' => 'citra@contoh.id']),
];

$halaman = [];
foreach (Route::getRoutes() as $r) {
    $nama = $r->getName();
    if (! $nama || ! in_array('GET', $r->methods()) || in_array($nama, $lewati) || str_starts_with($nama, 'livewire') || str_starts_with($nama, 'ignition')) {
        continue;
    }
    if (isset($isian[$nama])) {
        $url = $isian[$nama]();
    } elseif ($r->parameterNames()) {
        echo "Dilewati (parameter tanpa data contoh): $nama\n";

        continue;
    } else {
        $url = route($nama);
    }
    if (! $url) {
        echo "Dilewati (data contoh tidak ada): $nama\n";

        continue;
    }
    $area = str_starts_with($nama, 'staf.') ? 'staf' : (in_array('auth', $r->gatherMiddleware()) ? 'akun' : 'tamu');
    $halaman[] = ['nama' => $nama, 'url' => preg_replace('#^https?://[^/]+#', '', $url) ?: '/', 'area' => $area];
}
// Halaman galat memakai tata letak sendiri
$halaman[] = ['nama' => 'galat.404', 'url' => '/halaman-tidak-ada', 'area' => 'tamu', 'status' => 404];
$halaman[] = ['nama' => 'galat.403', 'url' => '/staf', 'area' => 'akun', 'status' => 403];

@mkdir(storage_path('app/responsive'), 0777, true);
file_put_contents(storage_path('app/responsive/rute.json'), json_encode(['akun' => $akun, 'halaman' => $halaman], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo count($halaman).' halaman, akun: '.implode(', ', array_keys($akun)).".\n";
