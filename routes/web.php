<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LupaSandiController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CekPesananController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LaundryController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\PengajuanInstansiController;
use App\Http\Controllers\PesananMakananController;
use App\Http\Controllers\PesananSayaController;
use App\Http\Controllers\ReservasiTamuController;
use App\Http\Controllers\RestoController;
use App\Http\Controllers\Staf\Admin\AulaController;
use App\Http\Controllers\Staf\Admin\FasilitasController;
use App\Http\Controllers\Staf\Admin\KamarController;
use App\Http\Controllers\Staf\Admin\KriteriaController;
use App\Http\Controllers\Staf\Admin\LayananController;
use App\Http\Controllers\Staf\Admin\PengaturanController;
use App\Http\Controllers\Staf\Admin\PenggunaController;
use App\Http\Controllers\Staf\Admin\PeranController;
use App\Http\Controllers\Staf\Admin\TipeKamarController;
use App\Http\Controllers\Staf\Admin\UnitFasilitasController;
use App\Http\Controllers\Staf\DashboardController;
use App\Http\Controllers\Staf\Maintenance\JadwalController;
use App\Http\Controllers\Staf\Maintenance\LaporController;
use App\Http\Controllers\Staf\Maintenance\PenangananController;
use App\Http\Controllers\Staf\Maintenance\RiwayatController;
use App\Http\Controllers\Staf\SegeraController;
use App\Livewire\Profil;
use App\Livewire\Staf\Aula\KalenderAula;
use App\Livewire\Staf\Aula\PemesananAula;
use App\Livewire\Staf\Layanan\PesananLaundry;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Livewire\Staf\RoomStatusBoard;
use App\Livewire\Tamu\DaftarKamar;
use App\Livewire\Tamu\DetailKamar;
use App\Livewire\Tamu\PesanKamar;
use App\Livewire\Tamu\PesanLaundry;
use App\Livewire\Tamu\SewaAula;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Masuk dan daftar tampil sebagai modal di atas beranda
Route::middleware('guest')->group(function () {
    Route::get('/masuk', HomeController::class)->name('login')->defaults('authModal', 'masuk');
    Route::get('/daftar', HomeController::class)->name('register')->defaults('authModal', 'daftar');
    Route::post('/masuk', [LoginController::class, 'store'])->name('login.store');
    Route::post('/daftar', [RegisterController::class, 'store'])->name('register.store');

    // Masuk dengan Google; akun baru melengkapi nomor telepon sebelum dibuat
    Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
    Route::get('/daftar/google', [GoogleController::class, 'create'])->name('auth.google.lengkapi');
    Route::post('/daftar/google', [GoogleController::class, 'store'])->name('auth.google.store');
    Route::post('/daftar/google/batal', [GoogleController::class, 'cancel'])->name('auth.google.batal');

    // Lupa kata sandi: tautan dikirim ke email, halaman atur ulang tampil sebagai modal di atas beranda
    Route::post('/lupa-sandi', [LupaSandiController::class, 'kirim'])->name('password.email');
    Route::get('/atur-ulang-sandi/{token}', [LupaSandiController::class, 'create'])->name('password.reset');
    Route::post('/atur-ulang-sandi', [LupaSandiController::class, 'update'])->name('password.update');
});

Route::post('/keluar', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::livewire('/kamar', DaftarKamar::class)->name('kamar');
Route::livewire('/kamar/{kode}', DetailKamar::class)->name('kamar.tipe');
// Data pemesan, lalu uang muka dibayar di halaman reservasi (tautan berisi token akses)
Route::livewire('/kamar/{kode}/pesan', PesanKamar::class)->name('kamar.pesan');
Route::get('/reservasi/{kode}', [ReservasiTamuController::class, 'show'])->name('reservasi.status');
Route::post('/reservasi/{kode}/bayar', [ReservasiTamuController::class, 'bayar'])->middleware('throttle:10,1,bayar-reservasi')->name('reservasi.bayar');
Route::get('/reservasi/{kode}/cek', [ReservasiTamuController::class, 'cek'])->middleware('throttle:30,1,cek-reservasi')->name('reservasi.cek');
Route::post('/reservasi/{kode}/batal', [ReservasiTamuController::class, 'batal'])->middleware('throttle:5,1,batal-reservasi')->name('reservasi.batal');
Route::post('/reservasi/{kode}/ubah-tanggal', [ReservasiTamuController::class, 'ubahTanggal'])->middleware('throttle:5,1,ubah-reservasi')->name('reservasi.ubah');
Route::get('/resto', RestoController::class)->name('menu');

// Pesan makanan online (ambil / makan di tempat), dibayar lewat Midtrans. Tidak wajib masuk akun.
Route::post('/resto/pesan', [PesananMakananController::class, 'store'])->middleware('throttle:10,1,pesan-makanan')->name('resto.pesan');
Route::get('/pesanan-makanan/{kode}', [PesananMakananController::class, 'show'])->name('pesanan.status');
Route::post('/pesanan-makanan/{kode}/bayar', [PesananMakananController::class, 'bayar'])->middleware('throttle:10,1,bayar-makanan')->name('pesanan.bayar');
Route::get('/pesanan-makanan/{kode}/cek', [PesananMakananController::class, 'cek'])->middleware('throttle:30,1,cek-bayar')->name('pesanan.cek');
Route::post('/midtrans/notification', [PesananMakananController::class, 'notifikasi'])->name('midtrans.notifikasi');
// Laundry kiloan (tamu menginap dan tamu luar); pembayaran setelah ditimbang memakai route pesanan.bayar/cek
Route::livewire('/laundry', PesanLaundry::class)->name('laundry');
Route::get('/laundry/{kode}', [LaundryController::class, 'show'])->name('laundry.status');
Route::livewire('/aula', SewaAula::class)->name('aula');
Route::view('/kontak', 'kontak')->name('kontak');
// Nota PDF lewat tautan bertanda tangan, dikirim ke WhatsApp setelah pembayaran berhasil
Route::get('/nota/{nota}', [NotaController::class, 'unduh'])->middleware(['signed', 'throttle:30,1,unduh-nota'])->name('nota.unduh');
Route::get('/cek-pesanan', [CekPesananController::class, 'index'])->name('cek');
Route::post('/cek-pesanan', [CekPesananController::class, 'cari'])->middleware('throttle:10,1,cek-pesanan')->name('cek.cari');
Route::get('/instansi', [PengajuanInstansiController::class, 'create'])->name('instansi');
Route::post('/instansi', [PengajuanInstansiController::class, 'store'])->middleware('throttle:5,1,pengajuan-instansi')->name('instansi.store');

Route::middleware('auth')->group(function () {
    Route::get('/pesanan-saya', PesananSayaController::class)->name('pesanan');
    Route::livewire('/profil', Profil::class)->name('profil');
});

// Panel staf. Menu yang tampil di sidebar diatur App\Support\MenuStaf
Route::prefix('staf')->name('staf.')->middleware('staf')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Modul Master Kamar; hak akses lewat Gate di AppServiceProvider
    Route::middleware('can:manage-rooms')->group(function () {
        Route::get('tipe-kamar', [TipeKamarController::class, 'index'])->name('tipe-kamar.index');
        Route::post('tipe-kamar', [TipeKamarController::class, 'store'])->name('tipe-kamar.store');
        Route::put('tipe-kamar/{tipeKamar}', [TipeKamarController::class, 'update'])->name('tipe-kamar.update');
        Route::delete('tipe-kamar/{tipeKamar}', [TipeKamarController::class, 'destroy'])->name('tipe-kamar.destroy');

        // Tidak ada route hapus kamar, supaya riwayat reservasi tetap utuh
        Route::get('kamar', [KamarController::class, 'index'])->name('kamar.index');
        Route::post('kamar', [KamarController::class, 'store'])->name('kamar.store');
        Route::put('kamar/{kamar}', [KamarController::class, 'update'])->name('kamar.update');
        Route::patch('kamar/{kamar}/aktif', [KamarController::class, 'toggle'])->name('kamar.toggle');
    });
    Route::livewire('papan-status-kamar', RoomStatusBoard::class)->middleware('can:view-rooms')->name('papan-status');

    // Modul Reservasi; tombol aksi di tiap halaman diperiksa lagi dengan Gate
    Route::middleware('can:view-reservations')->group(function () {
        Route::livewire('reservasi', DaftarReservasi::class)->name('reservasi');
        Route::livewire('pengajuan-instansi', PengajuanInstansi::class)->name('pengajuan-instansi');
        Route::livewire('check-in', CheckIn::class)->name('check-in');
    });
    Route::livewire('reservasi-langsung', ReservasiLangsung::class)->middleware('can:manage-reservations')->name('reservasi-langsung');

    // Layanan: laundry (Housekeeping, FO) dan aula (FO, F&B; Manager menyetujui)
    Route::livewire('laundry', PesananLaundry::class)->middleware('can:view-laundry')->name('laundry');
    Route::middleware('can:view-halls')->group(function () {
        Route::livewire('pemesanan-aula', PemesananAula::class)->name('aula-pemesanan');
        Route::livewire('kalender-aula', KalenderAula::class)->name('aula-kalender');
    });

    // Maintenance: semua staf operasional melapor; penanganan, jadwal rutin, dan riwayat hanya Manager
    Route::prefix('maintenance')->name('maintenance.')->group(function () {
        Route::middleware('can:report-damage')->group(function () {
            Route::get('lapor', [LaporController::class, 'create'])->name('lapor');
            Route::post('lapor', [LaporController::class, 'store'])->name('lapor.store');
            Route::get('laporan-saya', [LaporController::class, 'saya'])->name('saya');
        });
        Route::middleware('can:manage-maintenance')->group(function () {
            Route::get('/', [PenangananController::class, 'index'])->name('index');
            Route::post('{laporan}/kerjakan', [PenangananController::class, 'kerjakan'])->whereNumber('laporan')->name('kerjakan');
            Route::post('{laporan}/selesai', [PenangananController::class, 'selesaikan'])->whereNumber('laporan')->name('selesaikan');
            Route::post('{laporan}/batal', [PenangananController::class, 'batalkan'])->whereNumber('laporan')->name('batalkan');

            Route::get('jadwal', [JadwalController::class, 'index'])->name('jadwal');
            Route::post('jadwal', [JadwalController::class, 'store'])->name('jadwal.store');
            Route::put('jadwal/{jadwal}', [JadwalController::class, 'update'])->name('jadwal.update');
            Route::patch('jadwal/{jadwal}/aktif', [JadwalController::class, 'toggle'])->name('jadwal.toggle');
            Route::post('jadwal/{jadwal}/pelaksanaan', [JadwalController::class, 'laksanakan'])->name('jadwal.laksanakan');

            Route::get('riwayat', [RiwayatController::class, 'index'])->name('riwayat');
        });
    });

    // Admin: data master lain, pengguna dan akses, pengaturan (BAB 3)
    Route::middleware('staf:admin')->group(function () {
        $master = [
            'pengguna' => [PenggunaController::class, 'pengguna', true],
            'fasilitas' => [FasilitasController::class, 'fasilitas', false],
            'unit-fasilitas' => [UnitFasilitasController::class, 'unit', true],
            'layanan' => [LayananController::class, 'layanan', true],
            'aula' => [AulaController::class, 'aula', true],
        ];
        foreach ($master as $uri => [$controller, $param, $bisaNonaktif]) {
            Route::get($uri, [$controller, 'index'])->name("$uri.index");
            Route::post($uri, [$controller, 'store'])->name("$uri.store");
            Route::put("$uri/{{$param}}", [$controller, 'update'])->name("$uri.update");
            if ($bisaNonaktif) {
                Route::patch("$uri/{{$param}}/aktif", [$controller, 'toggle'])->name("$uri.toggle");
            }
        }

        // Kriteria pembersihan dan bobot AHP sudah tetap: hanya dibaca
        Route::get('kriteria', [KriteriaController::class, 'index'])->name('kriteria.index');

        Route::get('peran', [PeranController::class, 'index'])->name('peran.index');
        Route::put('peran/{peran}', [PeranController::class, 'update'])->name('peran.update');

        Route::get('pengaturan', [PengaturanController::class, 'edit'])->name('pengaturan');
        Route::put('pengaturan', [PengaturanController::class, 'update'])->name('pengaturan.update');
    });

    // Menu peran lain yang belum dibangun
    Route::get('{slug}', SegeraController::class)->name('segera');
});

// Alamat yang tidak dikenal tetap melewati middleware web, supaya halaman 404 memakai sesi (navbar tahu siapa yang masuk)
Route::fallback(fn () => abort(404));
