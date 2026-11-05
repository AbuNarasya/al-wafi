<?php

namespace App\Console\Commands;

use App\Exceptions\AppException;
use App\Models\User;
use App\Services\Impor\ImporUlangSantri;
use Illuminate\Console\Command;

/**
 * Kosongkan seluruh santri & wali beserta transaksinya, lalu impor ulang dari satu berkas CSV santri lama
 * — lihat App\Services\Impor\ImporUlangSantri. TANPA --jalankan = uji-kering (semua langkah dijalankan
 * lalu dibatalkan). Cadangkan database dulu sebelum --jalankan.
 */
class ImporUlangSantriCommand extends Command
{
    protected $signature = 'santri:impor-ulang
        {berkas : Berkas CSV santri lama (kolom seperti templat Impor Data Awal → Santri Lama)}
        {--ta= : Tahun ajaran untuk baris yang kolomnya kosong, mis. 2026/2027}
        {--jalur= : Kode jalur untuk baris yang kolomnya kosong}
        {--pengguna=admin : Username pelaku yang dicatat di batch & jejak audit}
        {--alasan= : Alasan, dicatat di jejak audit (wajib bersama --jalankan)}
        {--jalankan : Benar-benar menulis. Tanpa ini hanya uji-kering.}';

    protected $description = 'Kosongkan semua santri, wali & transaksinya lalu impor ulang dari CSV; Kebijakan Khusus disambung ulang lewat NIS.';

    public function handle(ImporUlangSantri $layanan): int
    {
        $berkas = (string) $this->argument('berkas');
        if (! is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }
        $sungguhan = (bool) $this->option('jalankan');
        $alasan = trim((string) $this->option('alasan'));
        if ($sungguhan && $alasan === '') {
            $this->error('--alasan wajib diisi bersama --jalankan.');

            return self::FAILURE;
        }
        $pengguna = User::where('username', $this->option('pengguna'))->first();
        if (! $pengguna) {
            $this->error("Pengguna \"{$this->option('pengguna')}\" tidak ada.");

            return self::FAILURE;
        }

        $db = config('database.connections.'.config('database.default'));
        $this->line(($sungguhan ? '<fg=red>SUNGGUHAN</>' : '<fg=yellow>UJI-KERING</> (tak ada yang tersimpan)')
            ." · database {$db['database']} @ {$db['host']}");

        try {
            $hasil = $layanan->jalankan($berkas, array_filter([
                'tahun_ajaran_bawaan' => (string) $this->option('ta'),
                'jalur_bawaan' => (string) $this->option('jalur'),
            ]), $sungguhan, $pengguna->id_pengguna, $alasan);
        } catch (AppException $e) {
            $this->error('DIBATALKAN, tak ada yang berubah.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Dihapus:');
        foreach (array_filter($hasil['dihapus']) as $tabel => $n) {
            $this->line(sprintf('  %-32s %6d', $tabel, $n));
        }
        $this->info('Jurnal modul santri dihapus:');
        foreach ($hasil['jurnal'] as $sumber => $n) {
            $this->line(sprintf('  %-32s %6d', $sumber, $n));
        }
        $t = $hasil['impor']['tersimpan'];
        $this->info("Impor: {$t['santri']} santri, {$t['wali']} wali, {$t['tagihan']} tagihan tunggakan (batch #{$hasil['impor']['id_batch']}).");
        $this->info("Kebijakan Khusus disambung ulang: {$hasil['kebijakan']}.");
        $this->line($sungguhan ? '<fg=green>SELESAI — tersimpan.</>' : '<fg=yellow>Uji-kering selesai — semua dibatalkan. Ulangi dengan --jalankan --alasan="…".</>');

        return self::SUCCESS;
    }
}
