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
        // Hak akses modul kamar. Staf yang merangkap peran mendapat gabungan haknya.
        Gate::define('manage-rooms', fn (User $user) => $user->hasRole('admin'));
        Gate::define('view-rooms', fn (User $user) => $user->hasRole('admin', 'manager', 'front_office', 'housekeeping'));
        Gate::define('change-room-status', fn (User $user) => $user->hasRole('admin', 'front_office', 'housekeeping'));

        // Info wisma yang tampil di navbar, footer, dan beranda
        View::composer(['components.layouts.guest', 'home', 'resto', 'pesanan-status', 'errors::404', 'errors.404'], function ($view) {
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
