<?php

namespace App\Notifications;

use App\Support\Instans;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tautan reset kata sandi staf. Tidak di-antre (antrean tanpa pekerja) — tautannya hanya berguna beberapa menit. */
class TautanResetSandiStaf extends Notification
{
    public function __construct(public readonly string $tautan, public readonly int $menit) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset kata sandi '.Instans::nama())
            ->greeting("Assalamu'alaikum, {$notifiable->nama}")
            ->line("Ada permintaan mengganti kata sandi akun **{$notifiable->username}** di ".Instans::nama().'.')
            ->action('Buat kata sandi baru', $this->tautan)
            ->line("Tautan ini berlaku {$this->menit} menit dan hanya bisa dipakai sekali.")
            ->line('Bila Anda tidak memintanya, abaikan email ini — kata sandi Anda tidak berubah.')
            ->salutation(Instans::nama());
    }
}
