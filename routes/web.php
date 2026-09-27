<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LupaSandiController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PesananMakananController;
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
use App\Http\Controllers\Staf\SegeraController;
use App\Livewire\Staf\RoomStatusBoard;
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

// Halaman yang dibangun pada tahap berikutnya
$segera = fn (string $judul) => fn () => view('segera', ['judul' => $judul]);

Route::get('/kamar', $segera('Pilih kamar'))->name('kamar');
Route::get('/kamar/{kode}', $segera('Detail tipe kamar'))->name('kamar.tipe');
Route::get('/resto', RestoController::class)->name('menu');

// Pesan makanan online (ambil / makan di tempat), dibayar lewat Midtrans. Tidak wajib masuk akun.
Route::post('/resto/pesan', [PesananMakananController::class, 'store'])->middleware('throttle:10,1')->name('resto.pesan');
Route::get('/pesanan-makanan/{kode}', [PesananMakananController::class, 'show'])->name('pesanan.status');
Route::post('/pesanan-makanan/{kode}/bayar', [PesananMakananController::class, 'bayar'])->middleware('throttle:10,1')->name('pesanan.bayar');
Route::post('/midtrans/notification', [PesananMakananController::class, 'notifikasi'])->name('midtrans.notifikasi');
Route::get('/laundry', $segera('Laundry'))->name('laundry');
Route::get('/aula', $segera('Pengajuan sewa aula'))->name('aula');
Route::get('/kontak', $segera('Kontak'))->name('kontak');
Route::get('/cek-pesanan', $segera('Cek pesanan'))->name('cek');
Route::get('/instansi', $segera('Pengajuan reservasi instansi'))->name('instansi');

Route::middleware('auth')->group(function () use ($segera) {
    Route::get('/pesanan-saya', $segera('Pesanan saya'))->name('pesanan');
    Route::get('/profil', $segera('Profil'))->name('profil');
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

    // Admin: data master lain, pengguna dan akses, pengaturan (BAB 3)
    Route::middleware('staf:admin')->group(function () {
        $master = [
            'pengguna' => [PenggunaController::class, 'pengguna', true],
            'fasilitas' => [FasilitasController::class, 'fasilitas', false],
            'unit-fasilitas' => [UnitFasilitasController::class, 'unit', true],
            'layanan' => [LayananController::class, 'layanan', true],
            'aula' => [AulaController::class, 'aula', true],
            'kriteria' => [KriteriaController::class, 'kriteria', true],
        ];
        foreach ($master as $uri => [$controller, $param, $bisaNonaktif]) {
            Route::get($uri, [$controller, 'index'])->name("$uri.index");
            Route::post($uri, [$controller, 'store'])->name("$uri.store");
            Route::put("$uri/{{$param}}", [$controller, 'update'])->name("$uri.update");
            if ($bisaNonaktif) {
                Route::patch("$uri/{{$param}}/aktif", [$controller, 'toggle'])->name("$uri.toggle");
            }
        }

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
