<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kode OTP Portal Wali, lewat email. Tidak di-antre: kodenya hanya berguna beberapa menit.
 * Teksnya `__()` — dikirim dengan locale wali (MasukWali memasang ->locale() saat mengirim).
 */
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
            ->subject(__('Kode masuk Portal Wali: :kode', ['kode' => $this->kode]))
            ->greeting(__("Assalamu'alaikum,"))
            ->line(__('Kode masuk Portal Wali Al Wafi Anda:'))
            ->line("**{$this->kode}**")
            ->line(__('Berlaku :menit menit. Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku petugas.', ['menit' => $this->menit]))
            ->line(__('Bila Anda tidak meminta kode ini, abaikan email ini.'))
            ->salutation('Al Wafi Islamic Boarding School');
    }
}
