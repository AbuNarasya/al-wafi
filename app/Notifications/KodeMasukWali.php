<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Kode OTP Portal Wali, lewat email. Tidak di-antre: kodenya hanya berguna beberapa menit. */
class KodeMasukWali extends Notification
{
    public function __construct(public readonly string $kode, public readonly int $menit) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Kode masuk Portal Wali: {$this->kode}")
            ->greeting('Assalamu\'alaikum,')
            ->line('Kode masuk Portal Wali Al Wafi Anda:')
            ->line("**{$this->kode}**")
            ->line("Berlaku {$this->menit} menit. Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku petugas.")
            ->line('Bila Anda tidak meminta kode ini, abaikan email ini.')
            ->salutation('Al Wafi Islamic Boarding School');
    }
}
