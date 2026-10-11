<?php

namespace App\Providers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hak akses modul kamar. Admin hanya mengelola data master (Tipe Kamar, Kamar), bukan status operasional.
        // Staf yang merangkap peran mendapat gabungan haknya.
        Gate::define('manage-rooms', fn (User $user) => $user->hasRole('admin'));
        Gate::define('view-rooms', fn (User $user) => $user->hasRole('manager', 'front_office', 'housekeeping'));
        Gate::define('change-room-status', fn (User $user) => $user->hasRole('front_office', 'housekeeping'));

        // Reservasi: Manager dan FO melihat; FO mengerjakan (walk-in, check-in, ubah, batal); Manager memutuskan pengajuan instansi.
        // Admin tidak mengerjakan operasional harian.
        Gate::define('view-reservations', fn (User $user) => $user->hasRole('manager', 'front_office'));
        Gate::define('manage-reservations', fn (User $user) => $user->hasRole('front_office'));
        Gate::define('approve-reservations', fn (User $user) => $user->hasRole('manager'));

        // Laundry dikerjakan Housekeeping dan diterima juga di resepsionis (FO); Manager melihat
        Gate::define('view-laundry', fn (User $user) => $user->hasRole('manager', 'housekeeping', 'front_office'));
        Gate::define('manage-laundry', fn (User $user) => $user->hasRole('housekeeping', 'front_office'));
        // F&B: dapur dan ketersediaan menu dikelola F&B; Manager hanya memantau; kasir dipegang F&B dan FO
        Gate::define('view-fnb', fn (User $user) => $user->hasRole('manager', 'fnb'));
        Gate::define('manage-fnb', fn (User $user) => $user->hasRole('fnb'));
        Gate::define('take-fnb-order', fn (User $user) => $user->hasRole('fnb', 'front_office'));
        // Aula dicatat FO dan F&B; Manager menyetujui atau menolak
        Gate::define('view-halls', fn (User $user) => $user->hasRole('manager', 'front_office', 'fnb'));
        Gate::define('manage-halls', fn (User $user) => $user->hasRole('front_office', 'fnb'));
        Gate::define('approve-halls', fn (User $user) => $user->hasRole('manager'));

        // Keuangan: Nota dan Pembayaran. F&B hanya melihat nota layanan makanan (Invoice::terlihatOleh);
        // diskon, koreksi, pembatalan nota, dan menghanguskan refund hanya Manager
        Gate::define('view-finance', fn (User $user) => $user->hasRole('manager', 'front_office', 'fnb'));
        Gate::define('record-payment', fn (User $user) => $user->hasRole('front_office', 'fnb'));
        Gate::define('approve-finance', fn (User $user) => $user->hasRole('manager'));
        // Penagihan instansi: piutang, tagihan bulanan, dan pengingat hanya Manager
        Gate::define('manage-receivables', fn (User $user) => $user->hasRole('manager'));
        // Laporan Manager: pendapatan, hunian, pelanggan, operasional
        Gate::define('view-reports', fn (User $user) => $user->hasRole('manager'));
        // CRM: Daftar Pelanggan dan ubah kontak (Manager, FO; total belanja hanya Manager); Instansi dan Riwayat Tamu hanya Manager
        Gate::define('view-customers', fn (User $user) => $user->hasRole('manager', 'front_office'));
        Gate::define('manage-institutions', fn (User $user) => $user->hasRole('manager'));

        // Maintenance: semua staf operasional boleh melapor; penanganan, jadwal, dan riwayat hanya Manager. Admin tidak ikut.
        Gate::define('report-damage', fn (User $user) => $user->hasRole('manager', 'front_office', 'fnb', 'housekeeping'));
        Gate::define('manage-maintenance', fn (User $user) => $user->hasRole('manager'));

        // Smart Cleaning (M6): Manager menghitung prioritas, mengatur ketersediaan staf, dan membagi tugas;
        // staf Housekeeping mengerjakan tugasnya. Admin tidak ikut (hanya data master).
        Gate::define('view-cleaning-priority', fn (User $user) => $user->hasRole('manager'));
        Gate::define('manage-housekeeping', fn (User $user) => $user->hasRole('manager'));
        Gate::define('do-housekeeping-task', fn (User $user) => $user->hasRole('housekeeping'));

        // Info wisma yang tampil di navbar, footer, dan beranda
        View::composer(['components.layouts.guest', 'tamu.home', 'tamu.resto', 'tamu.pesanan-status', 'tamu.pesanan-saya', 'tamu.reservasi-status', 'tamu.cek-pesanan', 'tamu.instansi', 'livewire.tamu.pesan-kamar', 'pdf.nota', 'pdf.tagihan-bulanan', 'pdf.surat-tagihan', 'tamu.kontak', 'tamu.laundry-status', 'livewire.tamu.pesan-laundry', 'livewire.tamu.sewa-aula', 'livewire.tamu.daftar-kamar', 'livewire.tamu.detail-kamar', 'errors::403', 'errors.403', 'errors::404', 'errors.404', 'errors::429', 'errors.429'], function ($view) {
            $view->with('wisma', [
                'telepon' => '0822-9222-4111',
                'whatsapp' => '6282292224111',
                'instagram' => 'https://www.instagram.com/wisma.trans/',
                'facebook' => 'https://www.facebook.com/wismatrans.lutim',
                'alamat' => ['Jln. Soekarno Hatta No. 17', 'Malili, Luwu Timur', 'Sulawesi Selatan'],
                'maps' => 'https://maps.app.goo.gl/1zgjjPLge9yRiE4Y6',
                'checkIn' => Setting::get('check_in_time', '14:00'),
                'checkOut' => Setting::get('check_out_time', '12:00'),
            ]);
        });
    }
}
