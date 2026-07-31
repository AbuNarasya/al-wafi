<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Kebalikan `dummy:isi` — membersihkan SELURUH data kesantrian & jurnalnya,
 * menyisakan master.
 *
 * Kenapa bukan `migrate:fresh --seed`: seeder di sini hanya membuat COA, unit
 * bisnis, level, jalur, T.A, dan admin. Jenjang, tipe & jenis biaya, grid tarif,
 * potongan gelombang, sumber informasi, target santri — semuanya isian manual
 * yang hidup di basis data ini saja. `migrate:fresh` akan ikut membuangnya, dan
 * tak ada yang bisa mengembalikannya.
 *
 * Yang dibuang: santri, wali, pendaftaran, tagihan, pembayaran, jurnal beserta
 * barisnya, dompet & mutasinya, tabungan, prabayar SPP, riwayat tingkat,
 * dokumen, notifikasi, dan log aktivitas.
 *
 * Yang DIPERTAHANKAN: seluruh master (jenjang, tipe/jenis biaya, tarif_biaya,
 * potongan_gelombang, jalur_pendaftaran, jalur_nonaktif, sumber_informasi,
 * tahun_ajaran, target_santri, COA, bank, unit bisnis, pengguna, pengaturan).
 *
 * Penomoran dokumen (no. pendaftaran, NIS, nomor jurnal) diturunkan dari baris
 * yang ada — bukan dari tabel pencacah — jadi ikut mulai dari awal dengan
 * sendirinya. Urutan `id` tetap disetel ulang supaya benar-benar bersih.
 */
class HapusDataDummy extends Command
{
    protected $signature = 'dummy:hapus {--paksa : Jalankan tanpa bertanya}';

    protected $description = 'Hapus seluruh data santri/wali/tagihan/pembayaran/jurnal hasil uji; master dipertahankan.';

    /**
     * Urutan WAJIB anak-dulu: kunci asing di sini ON DELETE RESTRICT/NO ACTION,
     * jadi menghapus induk lebih dulu akan ditolak, bukan dibiarkan mengalir.
     */
    private const TABEL = [
        'journal_lines',
        'journal_entries',
        'termin_uang_pangkal',
        'rencana_angsuran_uang_pangkal',
        'potongan_uang_pangkal',
        'pembayaran_santri',
        'tagihan_santri',
        'mutasi_dompet',
        'dompet_santri',
        'dompet_wali',
        'tabungan_santri',
        'prabayar_spp',
        'persetujuan_term',
        'dokumen_santri',
        'riwayat_tingkat',
        'pendaftaran',
        'santri',
        'wali',
        'notifications',
        'activity_log',
    ];

    public function handle(): int
    {
        $isi = [];
        foreach (self::TABEL as $t) {
            $n = DB::table($t)->count();
            if ($n > 0) {
                $isi[$t] = $n;
            }
        }

        if ($isi === []) {
            $this->info('Tidak ada data kesantrian yang tersisa — sudah bersih.');

            return self::SUCCESS;
        }

        $this->warn('Akan dihapus PERMANEN:');
        $this->table(['Tabel', 'Baris'], array_map(fn ($t, $n) => [$t, $n], array_keys($isi), $isi));
        $this->line('Master (jenjang, jenis biaya, tarif, potongan gelombang, COA, pengguna) tidak disentuh.');

        if (! $this->option('paksa') && ! $this->confirm('Lanjutkan?', false)) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            foreach (self::TABEL as $t) {
                DB::table($t)->delete();

                // Setel ulang urutan id kalau tabelnya memang ber-serial. Tabel
                // ber-PK string tak punya sequence — pg_get_serial_sequence
                // mengembalikan null, dan itu bukan galat.
                $seq = DB::selectOne('select pg_get_serial_sequence(?, ?) as s', [$t, 'id'])->s;
                if ($seq) {
                    DB::statement('select setval(?, 1, false)', [$seq]);
                }
            }
        });

        // Berkas CSV yang ditulis dummy:isi untuk alur Impor Santri Lama.
        $folder = storage_path('app/private/impor-dummy');
        if (File::isDirectory($folder)) {
            File::deleteDirectory($folder);
            $this->line("Folder berkas impor uji dihapus: {$folder}");
        }

        $this->newLine();
        $this->info('Selesai — '.array_sum($isi).' baris dibuang dari '.count($isi).' tabel.');
        $this->line('Basis data siap untuk pengujian manual dari nol.');

        return self::SUCCESS;
    }
}
