<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Bangkitkan sepasang kunci VAPID untuk push notification.
 *
 * Dijalankan SEKALI per lingkungan, hasilnya ditempel ke `.env`. Kuncinya
 * sengaja hanya DICETAK, tidak ditulis sendiri ke `.env`: berkas itu memuat
 * sandi database produksi, dan perintah yang menyuntingnya otomatis adalah
 * perintah yang suatu hari akan merusaknya.
 */
class PushKunci extends Command
{
    protected $signature = 'push:kunci';

    protected $description = 'Bangkitkan sepasang kunci VAPID untuk push notification (tempel sendiri ke .env).';

    public function handle(): int
    {
        if (config('push.vapid.public')) {
            $this->warn('Kunci VAPID SUDAH ADA di lingkungan ini.');
            $this->line('');
            $this->error('Menggantinya akan MEMATIKAN seluruh langganan yang sudah ada:');
            $this->line('alamat langganan di peramban terikat pada kunci publik yang dipakai');
            $this->line('saat mendaftar. Setiap staf harus menekan "Nyalakan" lagi satu per satu,');
            $this->line('dan tak ada pemberitahuan apa pun yang memberi tahu mereka.');
            $this->line('');

            if (! $this->confirm('Tetap bangkitkan pasangan kunci baru?', false)) {
                return self::SUCCESS;
            }
        }

        try {
            $kunci = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            return $this->jelaskanKegagalan($e);
        }

        $this->info('Tempel tiga baris ini ke .env, lalu jalankan: php artisan config:cache');
        $this->line('');
        $this->line('VAPID_SUBJECT="'.config('push.vapid.subject').'"');
        $this->line('VAPID_PUBLIC_KEY="'.$kunci['publicKey'].'"');
        $this->line('VAPID_PRIVATE_KEY="'.$kunci['privateKey'].'"');
        $this->line('');
        $this->warn('Kunci PRIVAT jangan pernah ikut ter-commit, dan jangan disamakan antar lingkungan.');

        return self::SUCCESS;
    }

    /**
     * Pustakanya melempar "Unable to create the key" tanpa menyebut sebabnya.
     *
     * Di Windows sebabnya hampir selalu sama dan tak ada hubungannya dengan
     * push: `openssl_pkey_new()` menuntut berkas konfigurasi OpenSSL, dan PHP
     * bawaan Windows tak menyetel `OPENSSL_CONF` — padahal berkasnya ada. Tanpa
     * keterangan ini, orang berikutnya akan menyangka pustakanya yang rusak.
     */
    private function jelaskanKegagalan(\Throwable $e): int
    {
        $this->error('Gagal membangkitkan kunci: '.$e->getMessage());

        $galat = [];
        while ($p = openssl_error_string()) {
            $galat[] = $p;
        }

        $soalKonfigurasi = (bool) array_filter($galat, fn ($g) => str_contains($g, 'configuration file'));

        if ($soalKonfigurasi || PHP_OS_FAMILY === 'Windows') {
            $cnf = getenv('OPENSSL_CONF');
            $this->line('');
            $this->warn('Sebabnya OpenSSL tak menemukan berkas konfigurasinya, bukan push notification.');
            $this->line('OPENSSL_CONF sekarang: '.($cnf ?: '(kosong)'));
            $this->line('');
            $this->line('Di Windows, jalankan ulang dengan menyebut berkasnya — biasanya ada di');
            $this->line('folder PHP, misalnya C:/php/extras/ssl/openssl.cnf:');
            $this->line('');
            $this->line('    OPENSSL_CONF="C:/php/extras/ssl/openssl.cnf" php artisan push:kunci');
            $this->line('');
            $this->line('Di server Linux hal ini tidak terjadi.');
        }

        foreach ($galat as $g) {
            $this->line('  · '.$g);
        }

        return self::FAILURE;
    }
}
