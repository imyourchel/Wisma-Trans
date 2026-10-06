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
        // Aula dicatat FO dan F&B; Manager menyetujui atau menolak
        Gate::define('view-halls', fn (User $user) => $user->hasRole('manager', 'front_office', 'fnb'));
        Gate::define('manage-halls', fn (User $user) => $user->hasRole('front_office', 'fnb'));
        Gate::define('approve-halls', fn (User $user) => $user->hasRole('manager'));

        // Maintenance: semua staf operasional boleh melapor; penanganan, jadwal, dan riwayat hanya Manager. Admin tidak ikut.
        Gate::define('report-damage', fn (User $user) => $user->hasRole('manager', 'front_office', 'fnb', 'housekeeping'));
        Gate::define('manage-maintenance', fn (User $user) => $user->hasRole('manager'));

        // Info wisma yang tampil di navbar, footer, dan beranda
        View::composer(['components.layouts.guest', 'home', 'resto', 'pesanan-status', 'pesanan-saya', 'reservasi-status', 'cek-pesanan', 'instansi', 'livewire.tamu.pesan-kamar', 'pdf.nota', 'kontak', 'laundry-status', 'livewire.tamu.pesan-laundry', 'livewire.tamu.sewa-aula', 'livewire.tamu.daftar-kamar', 'livewire.tamu.detail-kamar', 'errors::404', 'errors.404'], function ($view) {
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
