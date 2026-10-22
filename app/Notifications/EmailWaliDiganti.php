<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dikirim ke email LAMA sesudah diganti lewat Portal Wali. Pemilik email lama
 * kehilangan pintu masuknya — ia berhak tahu, dan inilah satu-satunya tanda bila
 * penggantian itu bukan atas kehendaknya.
 */
class EmailWaliDiganti extends Notification
{
    public function __construct(
        public readonly string $peran,
        public readonly string $oleh,
        public readonly string $emailBaru,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // Teks `__()`: tak di-antre, jadi terkirim dalam locale permintaan portal (bahasa wali yang mengganti).
            ->subject(__('Email Portal Wali Anda telah diganti'))
            ->greeting(__("Assalamu'alaikum,"))
            ->line(__('Email :peran di Portal Wali Al Wafi baru saja diganti oleh :oleh menjadi :email.', [
                'peran' => mb_strtolower(__(ucfirst($this->peran))), 'oleh' => $this->oleh, 'email' => $this->emailBaru,
            ]))
            ->line(__('Mulai sekarang, alamat ini tidak bisa lagi dipakai untuk masuk Portal Wali.'))
            ->line(__('Bila Anda tidak mengenali perubahan ini, segera hubungi panitia / bagian kesantrian pesantren.'))
            ->salutation('Al Wafi Islamic Boarding School');
    }
}
