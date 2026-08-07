<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Cadangan database ke satu berkas .sql berstempel waktu.
 *
 * Ada karena mahalnya pelajaran: satu `migrate:fresh` yang salah sasaran
 * menghapus seluruh database kerja, dan cadangan terakhir waktu itu berumur
 * SEMINGGU — sehingga yang hilang bukan nol, melainkan 45 baris grid tarif dan
 * seluruh pengaturan akun pengurang dana bebas yang tak ada di produksi.
 *
 * Ditulis ke LUAR folder repo (bawaan: `../cadangan` di sebelah proyek), supaya
 * tak pernah ikut ter-commit dan tak ikut terhapus bila folder proyek disetel
 * ulang. Sandi database dioper lewat env PGPASSWORD, bukan argumen baris
 * perintah — argumen terlihat di daftar proses seluruh mesin.
 */
class CadanganBuat extends Command
{
    protected $signature = 'cadangan:buat
        {--folder= : Folder tujuan (bawaan: ../cadangan di sebelah folder proyek)}
        {--simpan=14 : Berapa berkas terbaru yang dipertahankan; 0 = simpan semua}';

    protected $description = 'Cadangkan database ke berkas .sql berstempel waktu, lalu buang yang paling lama.';

    public function handle(): int
    {
        $koneksi = config('database.default');
        $db = config("database.connections.{$koneksi}.database");
        $host = config("database.connections.{$koneksi}.host");
        $port = (string) config("database.connections.{$koneksi}.port");
        $user = config("database.connections.{$koneksi}.username");
        $sandi = (string) config("database.connections.{$koneksi}.password");

        $pgDump = $this->cariPgDump();
        if (! $pgDump) {
            $this->error('pg_dump tidak ditemukan. Pasang PostgreSQL client tools, atau tambahkan foldernya ke PATH.');

            return self::FAILURE;
        }

        $folder = $this->option('folder') ?: dirname(base_path()).DIRECTORY_SEPARATOR.'cadangan';
        File::ensureDirectoryExists($folder);

        $berkas = $folder.DIRECTORY_SEPARATOR.sprintf('%s-%s.sql', $db, now()->format('Ymd-Hi'));

        $this->line("Mencadangkan <info>{$db}</info> → {$berkas}");

        $proses = new Process(
            [$pgDump, '--host', $host, '--port', $port, '--username', $user, '--no-password', '--file', $berkas, $db],
            null,
            // Sandi lewat ENV, bukan argumen: argumen baris perintah terbaca
            // oleh proses lain di mesin yang sama.
            ['PGPASSWORD' => $sandi],
            null,
            600,
        );
        $proses->run();

        if (! $proses->isSuccessful()) {
            $this->error('pg_dump gagal: '.trim($proses->getErrorOutput()));
            // Berkas separuh jadi lebih berbahaya daripada tak ada berkas — ia
            // tampak seperti cadangan yang sah sampai saat dibutuhkan.
            File::delete($berkas);

            return self::FAILURE;
        }

        $ukuran = File::size($berkas);
        $this->info(sprintf('Selesai — %s (%s KB)', basename($berkas), number_format($ukuran / 1024, 1)));

        $this->buangYangLama($folder, $db);

        return self::SUCCESS;
    }

    private function cariPgDump(): ?string
    {
        // PATH lebih dulu; kalau tak ada, telusuri pemasangan PostgreSQL di
        // Windows dari versi terbaru ke terlama.
        foreach (['pg_dump', 'pg_dump.exe'] as $nama) {
            $p = new Process([$nama, '--version']);
            $p->run();
            if ($p->isSuccessful()) {
                return $nama;
            }
        }

        $akar = 'C:\\Program Files\\PostgreSQL';
        if (! File::isDirectory($akar)) {
            return null;
        }

        $versi = collect(File::directories($akar))->sortByDesc(fn ($d) => (int) basename($d));
        foreach ($versi as $d) {
            $exe = $d.'\\bin\\pg_dump.exe';
            if (File::exists($exe)) {
                return $exe;
            }
        }

        return null;
    }

    private function buangYangLama(string $folder, string $db): void
    {
        $simpan = (int) $this->option('simpan');
        if ($simpan <= 0) {
            return;
        }

        $berkas = collect(File::glob($folder.DIRECTORY_SEPARATOR.$db.'-*.sql'))
            ->sortByDesc(fn ($f) => File::lastModified($f))
            ->values();

        $buang = $berkas->slice($simpan);
        foreach ($buang as $f) {
            File::delete($f);
        }

        if ($buang->isNotEmpty()) {
            $this->line("Membuang {$buang->count()} cadangan lama; menyisakan {$simpan} terbaru.");
        }
    }
}
