<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Email berisi tautan untuk membuat kata sandi baru. */
class AturUlangKataSandi extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $menit = config('auth.passwords.users.expire', 60);
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject('Atur ulang kata sandi akun Wisma Trans')
            ->greeting('Halo, '.Str::before($notifiable->name, ' ').'.')
            ->line('Kami menerima permintaan untuk mengatur ulang kata sandi akun Wisma Trans Anda.')
            ->action('Buat kata sandi baru', $url)
            ->line("Tautan ini berlaku selama {$menit} menit dan hanya dapat dipakai satu kali.")
            ->line('Jika Anda tidak meminta pengaturan ulang, abaikan email ini. Kata sandi Anda tidak akan berubah.')
            ->salutation('Salam hangat, Wisma Trans');
    }
}
