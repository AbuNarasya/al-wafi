<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Impor artikel panduan portal wali dari berkas markdown (docs/bantuan-wali/panduan-portal-wali.md).
 *
 * Format per artikel:
 *   ## Judul
 *   kata kunci: a, b, c
 *   <isi>
 *
 * Artikel dicocokkan lewat JUDUL: yang sudah ada diperbarui, yang baru ditambah, yang tak ada di berkas
 * dibiarkan (tak dihapus — bisa jadi ditulis staf lewat layar). Artikel bertanda [PERLU DICEK] ditolak
 * kecuali --paksa. Tanpa --jalankan = uji-kering (hanya menampilkan rencana).
 */
class ImporArtikelBantuan extends Command
{
    protected $signature = 'bantuan:impor-artikel {berkas=docs/bantuan-wali/panduan-portal-wali.md} {--audiens=wali : pembaca artikel: wali | staf} {--jalankan : benar-benar menyimpan} {--paksa : tetap impor artikel bertanda [PERLU DICEK]}';

    protected $description = 'Impor artikel panduan asisten bantuan portal wali dari berkas markdown';

    public function handle(): int
    {
        $audiens = (string) $this->option('audiens');
        if (! array_key_exists($audiens, \App\Services\PortalWali\AsistenWali::AUDIENS)) {
            $this->error('--audiens harus wali atau staf.');

            return self::FAILURE;
        }
        $berkas = $this->argument('berkas');
        $jalur = is_file($berkas) ? $berkas : base_path($berkas);
        if (! is_file($jalur)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $artikel = self::urai((string) file_get_contents($jalur));
        if ($artikel === []) {
            $this->error('Tidak ada artikel (judul diawali "## ").');

            return self::FAILURE;
        }

        $ragu = array_filter($artikel, fn ($a) => str_contains($a['isi'], '[PERLU DICEK'));
        if ($ragu !== [] && ! $this->option('paksa')) {
            $this->error(count($ragu).' artikel masih bertanda [PERLU DICEK] — periksa dulu, atau jalankan dengan --paksa:');
            foreach ($ragu as $a) {
                $this->line(' - '.$a['judul']);
            }

            return self::FAILURE;
        }

        $ada = DB::table('artikel_bantuan')->where('audiens', $audiens)->pluck('id', 'judul');
        $baru = $ubah = 0;
        foreach ($artikel as $a) {
            $ada->has($a['judul']) ? $ubah++ : $baru++;
        }
        $this->info(count($artikel)." artikel ({$audiens}) di berkas: {$baru} baru, {$ubah} diperbarui.");

        if (! $this->option('jalankan')) {
            $this->warn('Uji-kering — tidak ada yang disimpan. Tambahkan --jalankan untuk menyimpan.');

            return self::SUCCESS;
        }

        // Urutan menyambung artikel yang sudah ada di pembaca yang sama — beberapa berkas staf (umum, erp-*)
        // diimpor berurutan tanpa saling menimpa urutannya.
        $mulai = $ubah > 0 ? 0 : (int) DB::table('artikel_bantuan')->where('audiens', $audiens)->max('urutan');
        DB::transaction(function () use ($artikel, $audiens, $ada, $mulai) {
            foreach ($artikel as $i => $a) {
                $kunci = ['audiens' => $audiens, 'judul' => $a['judul']];
                $nilai = ['isi' => $a['isi'], 'kata_kunci' => $a['kata_kunci'], 'aktif' => true, 'updated_at' => now()];
                if ($ada->has($a['judul'])) {
                    DB::table('artikel_bantuan')->where($kunci)->update($nilai);
                } else {
                    DB::table('artikel_bantuan')->insert($kunci + $nilai + ['urutan' => $mulai + ($i + 1) * 10, 'created_at' => now()]);
                }
            }
        });
        $this->info('Tersimpan.');

        return self::SUCCESS;
    }

    /** @return list<array{judul:string, kata_kunci:?string, isi:string}> */
    public static function urai(string $teks): array
    {
        $teks = str_replace("\r\n", "\n", $teks);
        $hasil = [];
        foreach (preg_split('/^## /m', $teks) as $i => $bagian) {
            if ($i === 0) {
                continue; // pengantar sebelum artikel pertama
            }
            $baris = explode("\n", $bagian);
            $judul = trim(array_shift($baris));
            $kunci = null;
            while ($baris !== [] && trim($baris[0]) === '') {
                array_shift($baris);
            }
            if ($baris !== [] && preg_match('/^kata kunci\s*:\s*(.*)$/iu', trim($baris[0]), $m)) {
                $kunci = trim($m[1]) ?: null;
                array_shift($baris);
            }
            $isi = trim(implode("\n", $baris));
            // Garis pemisah markdown di akhir artikel tak ikut.
            $isi = trim(preg_replace('/\n-{3,}\s*$/', '', $isi));
            if ($judul !== '' && $isi !== '') {
                $hasil[] = ['judul' => mb_substr($judul, 0, 200), 'kata_kunci' => $kunci, 'isi' => $isi];
            }
        }

        return $hasil;
    }
}
