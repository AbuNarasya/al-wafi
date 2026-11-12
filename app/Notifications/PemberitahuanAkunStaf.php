<?php

namespace App\Notifications;

use App\Support\Instans;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pemberitahuan keamanan akun staf: kata sandi baru saja diganti lewat tautan email, atau email akun diubah
 * (dikirim ke alamat LAMA). Pemilik akun langsung tahu bila bukan ia yang melakukannya.
 */
class PemberitahuanAkunStaf extends Notification
{
    public function __construct(public readonly string $judul, public readonly string $isi, public readonly string $username) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->judul.' — '.Instans::nama())
            ->greeting("Assalamu'alaikum,")
            ->line($this->isi)
            ->line('Waktu: '.now()->translatedFormat('j F Y, H:i').' · Akun: **'.$this->username.'**')
            ->line('Bila BUKAN Anda yang melakukannya, segera hubungi administrator '.Instans::nama().'.')
            ->salutation(Instans::nama());
    }
}
