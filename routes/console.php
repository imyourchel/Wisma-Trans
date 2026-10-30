<?php

use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Keuangan\PengingatPenagihan;
use App\Services\Keuangan\PiutangService;
use App\Services\Maintenance\PengingatJadwalPerawatan;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\ReservasiService;
use App\Services\SmartCleaning\TugasHousekeepingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reservasi online yang uang mukanya tidak dibayar sampai batas waktu: dibatalkan, kamar kembali tersedia
Artisan::command('reservasi:batalkan-kedaluwarsa', function (ReservasiService $reservasi, NotifikasiReservasi $notifikasi, PembayaranMidtrans $midtrans) {
    $batal = $reservasi->batalkanKedaluwarsa();
    foreach ($batal as $r) {
        $midtrans->akhiriTagihan($r);
        $notifikasi->dibatalkanOtomatis($r);
    }
    $this->info(count($batal).' reservasi dibatalkan.');
})->purpose('Batalkan reservasi yang lewat batas bayar uang muka');

// Cadangan notifikasi Midtrans: pembayaran online yang masih pending (< 2 hari) dicek langsung ke Midtrans
Artisan::command('midtrans:sinkron', function (PembayaranMidtrans $midtrans) {
    if (! PembayaranMidtrans::siap()) {
        $this->info('Kunci Midtrans kosong, sinkron dilewati.');

        return;
    }
    $this->info($midtrans->sinkronSemua().' pembayaran online diperbarui dari Midtrans.');
})->purpose('Cek status pembayaran online yang masih menunggu ke Midtrans');

// Tamu yang tidak datang sampai akhir hari kedatangan: no_show, uang muka hangus
Artisan::command('reservasi:tandai-tidak-datang', function (ReservasiService $reservasi) {
    $this->info($reservasi->tandaiTidakDatang().' reservasi ditandai tidak datang.');
})->purpose('Tandai reservasi yang tamunya tidak datang');

// Perawatan rutin yang terlambat atau harus dilakukan dalam 7 hari: Manager diingatkan lewat WhatsApp dan email, sekali sehari
Artisan::command('maintenance:pengingat-jadwal {--paksa : kirim lagi walau hari ini sudah terkirim}', function (PengingatJadwalPerawatan $pengingat) {
    $jumlah = $pengingat->kirim((bool) $this->option('paksa'));
    $this->info($jumlah ? "Pengingat {$jumlah} jadwal perawatan dikirim ke Manager." : 'Tidak ada pengingat yang dikirim.');
})->purpose('Ingatkan Manager tentang perawatan rutin yang terlambat atau segera dilakukan');

// Tugas Housekeeping hari sebelumnya yang belum dimulai: dibatalkan, kamar yang masih kotor ikut prioritas berikutnya
Artisan::command('housekeeping:batalkan-tugas-lama', function (TugasHousekeepingService $tugas) {
    $this->info($tugas->batalkanTugasLama().' tugas Housekeeping hari sebelumnya dibatalkan.');
})->purpose('Batalkan tugas Housekeeping hari sebelumnya yang belum dimulai');

// Penagihan instansi harian (idempoten, menyusul bila sempat tidak berjalan): nota sewa aula instansi, nota periode
// menginap panjang, draft tagihan bulanan, dan status lewat jatuh tempo
Artisan::command('penagihan:harian', function (PiutangService $piutang) {
    $h = $piutang->harian();
    $this->info("Nota aula {$h['aula']}, nota periode {$h['periode']}, draft tagihan bulanan {$h['bulanan']}, ditandai lewat jatuh tempo {$h['overdue']}.");
})->purpose('Buat nota aula dan periode instansi, draft tagihan bulanan, dan tandai tagihan lewat jatuh tempo');

// Pengingat penagihan: ringkasan ke Manager (H-n), lalu pengingat ke instansi menunggu diperiksa Manager
Artisan::command('penagihan:pengingat', function (PengingatPenagihan $pengingat) {
    $h = $pengingat->jalankan();
    $this->info("Pengingat internal {$h['internal']}, pengingat instansi menunggu diperiksa {$h['instansi']}, dibatalkan {$h['dibatalkan']}.");
})->purpose('Kirim ringkasan tagihan instansi ke Manager dan siapkan pengingat ke instansi');

Schedule::command('penagihan:harian')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('penagihan:pengingat')->dailyAt('07:30')->withoutOverlapping();
Schedule::command('reservasi:batalkan-kedaluwarsa')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('midtrans:sinkron')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reservasi:tandai-tidak-datang')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('housekeeping:batalkan-tugas-lama')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('maintenance:pengingat-jadwal')->dailyAt('07:00')->withoutOverlapping();
