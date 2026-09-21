<?php

namespace App\Console\Commands;

use App\Services\Modules\BatchTagihanService;
use Illuminate\Console\Command;

/**
 * Rilis batch tagihan yang waktunya sudah lewat.
 *
 * Dijadwalkan tiap 5 menit. Tak berbahaya dijalankan berkali-kali: batch yang
 * sudah dirilis berganti status, jadi ia tak pernah terjaring dua kali.
 *
 * ══ BUKAN SATU-SATUNYA PEMICU, DAN ITU DISENGAJA ══
 * Produksi berjalan di shared hosting yang `proc_open`-nya disetel lewat berkas
 * milik panel — sekali panel menulis ulang berkas itu, `schedule:run` berhenti
 * TANPA PESAN APA PUN. Pengingat yang hilang cuma menjengkelkan; tagihan yang
 * tak pernah terbit adalah lubang di piutang. Karena itu BatchTagihanService
 * juga dipanggil dari dalam aplikasi (lihat `pemicuCadangan`), dengan pola sama
 * seperti `santri:terapkan-jadwal`: idempoten, jadi dipanggil dari dua arah pun
 * aman.
 */
class RilisBatchTagihan extends Command
{
    protected $signature = 'tagihan:rilis-terjadwal';

    protected $description = 'Terbitkan batch tagihan yang sudah diotorisasi dan waktu rilisnya sudah lewat.';

    public function handle(BatchTagihanService $service): int
    {
        $hasil = $service->rilisYangJatuhTempo();

        if ($hasil['diproses'] === 0 && $hasil['gagal'] === 0) {
            // Diam saat tak ada pekerjaan: perintah ini menyala tiap 5 menit, dan
            // log yang penuh baris "tak ada apa-apa" membuat yang penting tenggelam.
            return self::SUCCESS;
        }

        $this->info("Batch dirilis: {$hasil['diproses']} batch, {$hasil['terbit']} tagihan terbit.");

        foreach ($hasil['batch'] as $b) {
            $pesan = "  #{$b['id']} [{$b['status']}] ".($b['pesan'] ?? '');
            in_array($b['status'], ['gagal', 'galat'], true) ? $this->warn($pesan) : $this->line($pesan);
        }

        // Keluar tak-nol saat ada yang gagal supaya cron & log memperlakukannya
        // sebagai kejadian, bukan sebagai lari yang sukses.
        return $hasil['gagal'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
