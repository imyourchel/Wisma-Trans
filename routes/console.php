<?php

use App\Services\NotifikasiReservasi;
use App\Services\PembayaranMidtrans;
use App\Services\PengingatJadwalPerawatan;
use App\Services\ReservasiService;
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

// Tamu yang tidak datang sampai akhir hari kedatangan: no_show, uang muka hangus
Artisan::command('reservasi:tandai-tidak-datang', function (ReservasiService $reservasi) {
    $this->info($reservasi->tandaiTidakDatang().' reservasi ditandai tidak datang.');
})->purpose('Tandai reservasi yang tamunya tidak datang');

// Perawatan rutin yang terlambat atau harus dilakukan dalam 7 hari: Manager diingatkan lewat WhatsApp dan email, sekali sehari
Artisan::command('maintenance:pengingat-jadwal {--paksa : kirim lagi walau hari ini sudah terkirim}', function (PengingatJadwalPerawatan $pengingat) {
    $jumlah = $pengingat->kirim((bool) $this->option('paksa'));
    $this->info($jumlah ? "Pengingat {$jumlah} jadwal perawatan dikirim ke Manager." : 'Tidak ada pengingat yang dikirim.');
})->purpose('Ingatkan Manager tentang perawatan rutin yang terlambat atau segera dilakukan');

Schedule::command('reservasi:batalkan-kedaluwarsa')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reservasi:tandai-tidak-datang')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('maintenance:pengingat-jadwal')->dailyAt('07:00')->withoutOverlapping();
