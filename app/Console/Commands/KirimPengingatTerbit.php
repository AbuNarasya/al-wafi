<?php

namespace App\Console\Commands;

use App\Services\Modules\PengingatTerbitService;
use Illuminate\Console\Command;

/**
 * Tepuk bahu petugas yang harus menyusun draft tagihan hari ini.
 *
 * Aman dijalankan berulang: notifikasi yang sudah ada untuk (jadwal, periode)
 * yang sama tidak dikirim lagi.
 */
class KirimPengingatTerbit extends Command
{
    protected $signature = 'pengingat:terbit-tagihan {--hari= : Tanggal yang diperiksa (YYYY-MM-DD); bawaan hari ini}';

    protected $description = 'Kirim pengingat kepada petugas untuk menyusun & memeriksa draft tagihan.';

    public function handle(PengingatTerbitService $service): int
    {
        $hari = $this->option('hari') ? \Illuminate\Support\Carbon::parse($this->option('hari')) : null;
        $hasil = $service->kirim($hari);

        if ($hasil['terkirim'] === 0) {
            return self::SUCCESS; // diam saat tak ada yang perlu ditepuk
        }

        $this->info("Pengingat terbit: {$hasil['terkirim']} notifikasi.");
        foreach ($hasil['jadwal'] as $j) {
            $this->line("  {$j['judul']} ({$j['periode']}) → {$j['terkirim']} penerima");
        }

        return self::SUCCESS;
    }
}
