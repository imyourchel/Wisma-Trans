<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LupaSandiController;
use App\Http\Controllers\Auth\RegisterController;
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
use App\Http\Controllers\Staf\Housekeeping\BobotKriteriaController;
use App\Http\Controllers\Staf\Keuangan\DokumenTagihanController;
use App\Http\Controllers\Staf\Keuangan\RekapKasController;
use App\Http\Controllers\Staf\LaporanController;
use App\Http\Controllers\Staf\Maintenance\JadwalController;
use App\Http\Controllers\Staf\Maintenance\LaporController;
use App\Http\Controllers\Staf\Maintenance\PenangananController;
use App\Http\Controllers\Staf\Maintenance\RiwayatController;
use App\Http\Controllers\Staf\SegeraController;
use App\Http\Controllers\Tamu\BuktiInstansiController;
use App\Http\Controllers\Tamu\CekPesananController;
use App\Http\Controllers\Tamu\CekPesananLihatController;
use App\Http\Controllers\Tamu\HomeController;
use App\Http\Controllers\Tamu\InfoLayananController;
use App\Http\Controllers\Tamu\LaundryController;
use App\Http\Controllers\Tamu\NotaController;
use App\Http\Controllers\Tamu\PengajuanInstansiController;
use App\Http\Controllers\Tamu\PesananMakananController;
use App\Http\Controllers\Tamu\PesananSayaController;
use App\Http\Controllers\Tamu\ReservasiTamuController;
use App\Http\Controllers\Tamu\RestoController;
use App\Livewire\Staf\Aula\KalenderAula;
use App\Livewire\Staf\Aula\PemesananAula;
use App\Livewire\Staf\Housekeeping\KetersediaanStaf;
use App\Livewire\Staf\Housekeeping\PembagianTugas;
use App\Livewire\Staf\Housekeeping\PrioritasPembersihan;
use App\Livewire\Staf\Housekeeping\TugasSaya;
use App\Livewire\Staf\Kamar\RiwayatStatus;
use App\Livewire\Staf\Kamar\RoomStatusBoard;
use App\Livewire\Staf\Keuangan\Nota;
use App\Livewire\Staf\Keuangan\Pembayaran;
use App\Livewire\Staf\Keuangan\PengingatPenagihan as PengingatPenagihanPage;
use App\Livewire\Staf\Keuangan\PiutangInstansi;
use App\Livewire\Staf\Keuangan\TagihanBulanan;
use App\Livewire\Staf\Layanan\Kasir;
use App\Livewire\Staf\Layanan\KetersediaanMenu;
use App\Livewire\Staf\Layanan\PapanPesanan;
use App\Livewire\Staf\Layanan\PesananLaundry;
use App\Livewire\Staf\Layanan\RiwayatPesanan;
use App\Livewire\Staf\Pelanggan\DaftarPelanggan;
use App\Livewire\Staf\Pelanggan\Instansi as InstansiPelanggan;
use App\Livewire\Staf\Pelanggan\RiwayatTamu;
use App\Livewire\Staf\Reservasi\CheckIn;
use App\Livewire\Staf\Reservasi\CheckOut;
use App\Livewire\Staf\Reservasi\DaftarReservasi;
use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Livewire\Staf\Reservasi\ReservasiLangsung;
use App\Livewire\Tamu\DaftarKamar;
use App\Livewire\Tamu\DetailKamar;
use App\Livewire\Tamu\PesanKamar;
use App\Livewire\Tamu\PesanLaundry;
use App\Livewire\Tamu\Profil;
use App\Livewire\Tamu\SewaAula;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Masuk dan daftar tampil sebagai modal di atas beranda
Route::middleware('guest')->group(function () {
    Route::get('/login', HomeController::class)->name('login')->defaults('authModal', 'masuk');
    Route::get('/register', HomeController::class)->name('register')->defaults('authModal', 'daftar');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store');

    // Masuk dengan Google; akun baru melengkapi nomor telepon sebelum dibuat
    Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
    // Google One Tap (pop-up "Lanjutkan sebagai ..." di pojok halaman tamu)
    Route::post('/auth/google/one-tap', [GoogleController::class, 'oneTap'])->middleware('throttle:10,1')->name('auth.google.one-tap');
    Route::get('/register/google', [GoogleController::class, 'create'])->name('auth.google.lengkapi');
    Route::post('/register/google', [GoogleController::class, 'store'])->name('auth.google.store');
    Route::post('/register/google/batal', [GoogleController::class, 'cancel'])->name('auth.google.batal');

    // Lupa kata sandi: tautan dikirim ke email, halaman atur ulang tampil sebagai modal di atas beranda
    Route::post('/lupa-sandi', [LupaSandiController::class, 'kirim'])->name('password.email');
    Route::get('/atur-ulang-sandi/{token}', [LupaSandiController::class, 'create'])->name('password.reset');
    Route::post('/atur-ulang-sandi', [LupaSandiController::class, 'update'])->name('password.update');
});
// Alamat lama (tautan yang sudah terlanjur dibagikan) diarahkan ke alamat baru
Route::permanentRedirect('/masuk', '/login');
Route::permanentRedirect('/daftar', '/register');

Route::post('/keluar', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::livewire('/kamar', DaftarKamar::class)->name('kamar');
Route::livewire('/kamar/{kode}', DetailKamar::class)->name('kamar.tipe');
// Data pemesan, lalu uang muka dibayar di halaman reservasi (tautan berisi token akses)
Route::livewire('/kamar/{kode}/pesan', PesanKamar::class)->name('kamar.pesan');
Route::get('/reservasi/{kode}', [ReservasiTamuController::class, 'show'])->name('reservasi.status');
Route::post('/reservasi/{kode}/bayar', [ReservasiTamuController::class, 'bayar'])->middleware('throttle:10,1,bayar-reservasi')->name('reservasi.bayar');
// Tautan bagikan: URL bertanda tangan tanpa token akses, halaman baca saja
Route::get('/reservasi/{kode}/lihat', [ReservasiTamuController::class, 'bagikan'])->middleware(['signed', 'throttle:30,1,lihat-reservasi'])->name('reservasi.lihat');
Route::get('/reservasi/{kode}/cek', [ReservasiTamuController::class, 'cek'])->middleware('throttle:30,1,cek-reservasi')->name('reservasi.cek');
Route::post('/reservasi/{kode}/batal', [ReservasiTamuController::class, 'batal'])->middleware('throttle:5,1,batal-reservasi')->name('reservasi.batal');
Route::post('/reservasi/{kode}/ubah-tanggal', [ReservasiTamuController::class, 'ubahTanggal'])->middleware('throttle:5,1,ubah-reservasi')->name('reservasi.ubah');
Route::get('/resto', RestoController::class)->name('menu');

// Pesan makanan online (ambil / makan di tempat), dibayar lewat Midtrans. Tidak wajib masuk akun.
Route::post('/resto/pesan', [PesananMakananController::class, 'store'])->middleware('throttle:10,1,pesan-makanan')->name('resto.pesan');
// Tamu menginap: cocokkan kode kamar (dibatasi 10 kali per menit per IP di KodeKamar)
Route::post('/resto/kode-kamar', [PesananMakananController::class, 'cocokkanKamar'])->name('resto.kode-kamar');
Route::post('/resto/kode-kamar/lupakan', [PesananMakananController::class, 'lupakanKamar'])->name('resto.kode-kamar.lupakan');
Route::get('/pesanan-makanan/{kode}', [PesananMakananController::class, 'show'])->name('pesanan.status');
Route::post('/pesanan-makanan/{kode}/bayar', [PesananMakananController::class, 'bayar'])->middleware('throttle:10,1,bayar-makanan')->name('pesanan.bayar');
Route::get('/pesanan-makanan/{kode}/cek', [PesananMakananController::class, 'cek'])->middleware('throttle:30,1,cek-bayar')->name('pesanan.cek');
Route::post('/midtrans/notification', [PesananMakananController::class, 'notifikasi'])->name('midtrans.notifikasi');
// Laundry kiloan (tamu menginap dan tamu luar); pembayaran setelah ditimbang memakai route pesanan.bayar/cek.
// Halaman /laundry dan /aula hanya informasi; formulirnya dibuka lewat tombol di halaman sendiri.
Route::get('/laundry', [InfoLayananController::class, 'laundry'])->name('laundry');
Route::livewire('/laundry/pesan', PesanLaundry::class)->name('laundry.pesan');
Route::get('/laundry/{kode}', [LaundryController::class, 'show'])->name('laundry.status');
Route::get('/aula', [InfoLayananController::class, 'aula'])->name('aula');
Route::livewire('/aula/ajukan', SewaAula::class)->name('aula.ajukan');
Route::view('/kontak', 'tamu.kontak')->name('kontak');
// Nota PDF lewat tautan bertanda tangan, dikirim ke WhatsApp setelah pembayaran berhasil
Route::get('/nota/{nota}', [NotaController::class, 'unduh'])->middleware(['signed', 'throttle:30,1,unduh-nota'])->name('nota.unduh');
// Tagihan bulanan untuk narahubung instansi (tanpa akun), tautan bertanda tangan dari WhatsApp
Route::get('/tagihan-bulanan/{tagihan}', [DokumenTagihanController::class, 'unduhPublik'])->middleware(['signed', 'throttle:30,1,unduh-tagihan'])->name('tagihan-bulanan.unduh');
// Bukti pemesanan instansi (QR dan kode bukti) untuk diteruskan PIC kepada staf yang ditugaskan menginap
Route::get('/bukti-instansi/{kode}', [BuktiInstansiController::class, 'unduh'])->middleware(['signed', 'throttle:30,1,unduh-bukti'])->name('bukti-instansi.unduh');
Route::get('/cek-pesanan', [CekPesananController::class, 'index'])->name('cek');
Route::post('/cek-pesanan', [CekPesananController::class, 'cari'])->middleware('throttle:10,1,cek-pesanan')->name('cek.cari');
Route::post('/cek-pesanan/lupakan', [CekPesananController::class, 'lupakan'])->name('cek.lupakan');
// Hasil Cek Pesanan: lihat saja dengan izin di session (tanpa token); batal dan ubah tanggal tetap lewat tautan bertoken
Route::get('/cek-pesanan/perangkat/{jenis}/{kode}', [CekPesananLihatController::class, 'bukaPerangkat'])->where('jenis', 'reservasi|makanan|laundry')->name('cek.perangkat.buka');
Route::get('/cek-pesanan/{jenis}/{kode}', [CekPesananLihatController::class, 'show'])->where('jenis', 'reservasi|makanan|laundry')->name('cek.lihat');
Route::post('/cek-pesanan/{jenis}/{kode}/kirim-ulang', [CekPesananLihatController::class, 'kirimUlang'])->where('jenis', 'reservasi|makanan|laundry')
    ->middleware('throttle:5,1,kirim-ulang-tautan')->name('cek.lihat.kirim');
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
    // Riwayat perubahan status kamar dan lini masa per kamar (Manager, FO)
    Route::livewire('riwayat-status-kamar', RiwayatStatus::class)->middleware('can:view-reservations')->name('kamar.riwayat-status');

    // Modul Reservasi; tombol aksi di tiap halaman diperiksa lagi dengan Gate
    Route::middleware('can:view-reservations')->group(function () {
        Route::livewire('reservasi', DaftarReservasi::class)->name('reservasi');
        Route::livewire('pengajuan-instansi', PengajuanInstansi::class)->name('pengajuan-instansi');
        Route::livewire('check-in', CheckIn::class)->name('check-in');
        Route::livewire('check-out', CheckOut::class)->name('check-out');
    });
    Route::livewire('reservasi-langsung', ReservasiLangsung::class)->middleware('can:manage-reservations')->name('reservasi-langsung');

    // Layanan: laundry (Housekeeping, FO) dan aula (FO, F&B; Manager menyetujui)
    Route::livewire('laundry', PesananLaundry::class)->middleware('can:view-laundry')->name('laundry');
    // Layanan F&B: kasir (F&B, FO), papan pesanan, riwayat, dan ketersediaan menu (F&B; Manager memantau)
    Route::prefix('layanan')->name('fnb.')->group(function () {
        Route::livewire('kasir', Kasir::class)->middleware('can:take-fnb-order')->name('kasir');
        Route::middleware('can:view-fnb')->group(function () {
            Route::livewire('papan-pesanan', PapanPesanan::class)->name('papan');
            Route::livewire('riwayat-pesanan', RiwayatPesanan::class)->name('riwayat');
            Route::livewire('ketersediaan-menu', KetersediaanMenu::class)->name('ketersediaan');
        });
    });
    // Keuangan: nota dan pembayaran (Manager, FO, F&B; F&B sebatas nota makanan)
    Route::prefix('keuangan')->name('keuangan.')->middleware('can:view-finance')->group(function () {
        Route::livewire('nota', Nota::class)->name('nota');
        Route::livewire('pembayaran', Pembayaran::class)->name('pembayaran');
        Route::get('pembayaran/cetak-rekap', RekapKasController::class)->name('pembayaran.cetak');
    });
    // Laporan Manager; ?format=csv atau pdf dengan penyaring yang sama
    Route::prefix('laporan')->name('laporan.')->middleware('can:view-reports')->group(function () {
        foreach (['pendapatan', 'hunian', 'pelanggan', 'operasional'] as $laporan) {
            Route::get($laporan, [LaporanController::class, $laporan])->name($laporan);
        }
    });
    // CRM: Daftar Pelanggan (Manager, FO), Instansi dan Riwayat Tamu (Manager)
    Route::livewire('pelanggan', DaftarPelanggan::class)->middleware('can:view-customers')->name('pelanggan');
    Route::prefix('pelanggan')->name('pelanggan.')->middleware('can:manage-institutions')->group(function () {
        Route::livewire('instansi', InstansiPelanggan::class)->name('instansi');
        Route::livewire('riwayat-tamu', RiwayatTamu::class)->name('riwayat');
    });
    // Penagihan instansi (Manager): piutang, tagihan bulanan, pengingat, dan dokumennya
    Route::prefix('keuangan')->name('keuangan.')->middleware('can:manage-receivables')->group(function () {
        Route::livewire('piutang-instansi', PiutangInstansi::class)->name('piutang');
        Route::livewire('tagihan-bulanan', TagihanBulanan::class)->name('tagihan-bulanan');
        Route::livewire('pengingat-penagihan', PengingatPenagihanPage::class)->name('pengingat');
        Route::get('tagihan-bulanan/{tagihan}/pdf', [DokumenTagihanController::class, 'tagihanBulanan'])->name('tagihan-bulanan.pdf');
        Route::get('surat-tagihan/{jenis}/{id}', [DokumenTagihanController::class, 'surat'])->whereIn('jenis', ['nota', 'bulanan'])->name('surat-tagihan');
    });
    Route::middleware('can:view-halls')->group(function () {
        Route::livewire('pemesanan-aula', PemesananAula::class)->name('aula-pemesanan');
        Route::livewire('kalender-aula', KalenderAula::class)->name('aula-kalender');
    });

    // Smart Cleaning (M6): prioritas AHP-SAW, ketersediaan staf, dan pembagian tugas Greedy oleh Manager; Tugas Saya untuk Housekeeping
    Route::prefix('housekeeping')->name('housekeeping.')->group(function () {
        Route::middleware('can:view-cleaning-priority')->group(function () {
            Route::livewire('prioritas', PrioritasPembersihan::class)->name('prioritas');
            Route::get('bobot-kriteria', BobotKriteriaController::class)->name('bobot');
        });
        Route::middleware('can:manage-housekeeping')->group(function () {
            Route::livewire('ketersediaan', KetersediaanStaf::class)->name('ketersediaan');
            Route::livewire('pembagian', PembagianTugas::class)->name('pembagian');
        });
        Route::livewire('tugas-saya', TugasSaya::class)->middleware('can:do-housekeeping-task')->name('tugas-saya');
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
            Route::post('{laporan}/jalur-cepat', [PenangananController::class, 'jalurCepat'])->whereNumber('laporan')->name('jalur-cepat');
            Route::post('{laporan}/lengkapi', [PenangananController::class, 'lengkapi'])->whereNumber('laporan')->name('lengkapi');

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
