<?php

namespace App\Console\Commands;

use App\Services\Modules\PushService;
use Illuminate\Console\Command;

/**
 * Dorong notifikasi tugas yang belum sampai ke perangkat.
 *
 * Dijadwalkan tiap menit. Aman dijalankan berulang: yang sudah didorong punya
 * penanda waktunya sendiri, jadi tak pernah terkirim dua kali.
 *
 * Kalau kunci VAPID belum diisi di lingkungan ini, ia diam saja — tak ada yang
 * meledak, dan notifikasi dalam aplikasi tetap berjalan seperti biasa.
 */
class PushKirim extends Command
{
    protected $signature = 'push:kirim
        {--uji= : Kirim satu notifikasi uji ke perangkat seorang pengguna (sebut username-nya)}';

    protected $description = 'Dorong notifikasi tugas yang belum sampai ke perangkat staf.';

    public function handle(PushService $push): int
    {
        if ($username = $this->option('uji')) {
            return $this->uji($push, (string) $username);
        }

        $h = $push->kirimYangBelum();

        if ($h['disapu'] === 0) {
            return self::SUCCESS; // diam saat tak ada pekerjaan
        }

        $this->info("Push: {$h['terkirim']} terkirim, {$h['gagal']} gagal, dari {$h['disapu']} notifikasi.");

        if ($h['langganan_dibuang'] > 0) {
            // Bukan galat: perangkat yang izinnya dicabut atau aplikasinya
            // dicopot memang meninggalkan langganan basi, dan membuangnya
            // adalah perawatan biasa.
            $this->line("  {$h['langganan_dibuang']} langganan mati dibuang.");
        }

        return self::SUCCESS;
    }

    /** Uji pemasangan tanpa menunggu ada tugas sungguhan. */
    private function uji(PushService $push, string $username): int
    {
        $user = \App\Models\User::where('username', $username)->first();
        if (! $user) {
            $this->error("Pengguna \"{$username}\" tidak ditemukan.");

            return self::FAILURE;
        }

        $h = $push->kirimUji((int) $user->id_pengguna);

        $this->line("Perangkat terdaftar: {$h['perangkat']}");
        $this->line("Terkirim           : {$h['terkirim']}");
        $this->line("Gagal              : {$h['gagal']}");

        foreach ($h['pesan'] as $p) {
            $this->warn('  '.$p);
        }

        if ($h['terkirim'] > 0) {
            $this->info('Notifikasi sudah dilepas — periksa perangkatnya sekarang.');
            $this->line('Kalau tak muncul juga, pekerja layanan di perangkat itu kemungkinan masih versi lama.');
        }

        return $h['gagal'] > 0 || $h['terkirim'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
