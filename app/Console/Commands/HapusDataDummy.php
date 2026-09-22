<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Kebalikan `dummy:isi` — membersihkan SELURUH data transaksi, menyisakan master.
 *
 * Kenapa bukan `migrate:fresh --seed`: seeder di sini hanya membuat COA, unit
 * bisnis, level, jalur, T.A, dan admin. Jenjang, tipe & jenis biaya, grid tarif,
 * potongan gelombang, sumber informasi, target santri — semuanya isian manual
 * yang hidup di basis data ini saja. `migrate:fresh` akan ikut membuangnya, dan
 * tak ada yang bisa mengembalikannya.
 *
 * ══ KONEKSI WAJIB DISEBUT ══
 * Tidak ada nilai bawaan, dan itu disengaja. Perintah yang menghapus massal
 * dengan koneksi yang tak terlihat adalah bentuk kecelakaan yang sudah pernah
 * terjadi di proyek ini: satu `migrate:fresh` tanpa `--database=` menghapus
 * seluruh database kerja karena mengenai koneksi bawaan, bukan yang dikira.
 * Di sini nama koneksi, host, dan nama databasenya ikut DITAMPILKAN sebelum
 * bertanya — supaya yang menekan "ya" benar-benar melihat apa yang ia hapus.
 *
 * ══ YANG DIBUANG ══
 * Kesantrian (santri, wali, pendaftaran, tagihan, pembayaran, dompet, tabungan,
 * prabayar, riwayat, dokumen), jurnal beserta barisnya, dokumen keuangan (kas
 * masuk/keluar, pengajuan & perintah pembayaran, persetujuannya), batch tagihan,
 * notifikasi, dan log aktivitas.
 *
 * ══ YANG DIPERTAHANKAN ══
 * Seluruh master (jenjang, tipe/jenis biaya, tarif, potongan gelombang, jalur,
 * T.A, COA, bank, unit bisnis, pengguna, hak akses, pengaturan) — DAN
 * `push_langganan`, yang bukan data uji melainkan langganan notifikasi milik
 * perangkat staf. Membuangnya memaksa setiap orang menyalakannya ulang satu per
 * satu, tanpa ada pemberitahuan apa pun yang memberi tahu mereka.
 *
 * Daftar ini TIDAK menjanjikan kelengkapan untuk modul yang belum pernah
 * dipakai. Kalau kelak ada modul transaksi baru, tambahkan tabelnya di sini —
 * yang tertinggal akan membuat "siap dari nol" jadi janji yang tak ditepati.
 */
class HapusDataDummy extends Command
{
    protected $signature = 'dummy:hapus
        {--koneksi= : Koneksi database yang disasar. WAJIB disebut, tanpa bawaan}
        {--sesi : Ikut membuang sesi login & cache (semua orang keluar akun)}
        {--paksa : Jalankan tanpa bertanya}';

    protected $description = 'Hapus seluruh data transaksi (kesantrian, jurnal, dokumen keuangan); master & langganan push dipertahankan.';

    /**
     * Urutan WAJIB anak-dulu. Sebagian kunci asing memang CASCADE, tetapi
     * mengandalkan itu membuat urutannya tak terbaca — dan satu saja yang
     * ternyata RESTRICT akan menggagalkan seluruh transaksi di tengah jalan.
     */
    private const TABEL = [
        // ── Jurnal ──
        'journal_lines',
        'journal_entries',

        // ── Kesantrian ──
        'termin_uang_pangkal',
        'rencana_angsuran_uang_pangkal',
        'potongan_uang_pangkal',
        'pembayaran_santri',
        'batch_tagihan_baris',
        'batch_tagihan',
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

        // ── Dokumen keuangan ──
        'cash_out_details',
        'cash_out',
        'cash_in_details',
        'cash_in',
        'perintah_pembayaran_detail',
        'perintah_pembayaran',
        'pengajuan_rekening_riwayat',
        'pengajuan_pembayaran_detail',
        'pengajuan_pembayaran',
        'rekening_tersimpan',
        'approval_logs',
        'approval_instances',

        // ── Jejak, paling akhir: ia mencatat penghapusan modul di atasnya ──
        'konfirmasi_pengingat_terbit',
        'notifications',
        'activity_log',
    ];

    /** Bukan data, tapi ikut dibuang bila diminta lewat --sesi. */
    private const TABEL_SESI = ['sessions', 'cache'];

    public function handle(): int
    {
        $koneksi = (string) $this->option('koneksi');

        if ($koneksi === '') {
            $this->error('Opsi --koneksi WAJIB disebut. Tidak ada nilai bawaan, dan itu disengaja.');
            $this->line('');
            $this->line('Perintah ini menghapus massal. Koneksi yang tak terlihat adalah bentuk');
            $this->line('kecelakaan yang sudah pernah terjadi di proyek ini — satu perintah yang');
            $this->line('dikira mengenai database uji ternyata mengenai database kerja.');
            $this->line('');
            $this->line('Koneksi yang tersedia: '.implode(', ', array_keys(config('database.connections'))));

            return self::FAILURE;
        }

        if (! config("database.connections.{$koneksi}")) {
            $this->error("Koneksi \"{$koneksi}\" tidak ada di config/database.php.");

            return self::FAILURE;
        }

        $db = DB::connection($koneksi);
        $tabel = array_merge(self::TABEL, $this->option('sesi') ? self::TABEL_SESI : []);

        $isi = [];
        foreach ($tabel as $t) {
            // Modul yang belum pernah dimigrasi di koneksi ini bukan galat —
            // lewati saja, jangan menggagalkan seluruh pembersihan karenanya.
            if (! $db->getSchemaBuilder()->hasTable($t)) {
                continue;
            }
            $n = (int) $db->table($t)->count();
            if ($n > 0) {
                $isi[$t] = $n;
            }
        }

        // Identitas sasaran DITAMPILKAN, bukan disimpulkan. Inilah satu-satunya
        // kesempatan melihat bahwa yang akan dihapus memang yang dimaksud.
        $cfg = config("database.connections.{$koneksi}");
        $this->newLine();
        $this->line('  Koneksi  : <options=bold>'.$koneksi.'</>');
        $this->line('  Host     : '.($cfg['host'] ?? parse_url((string) ($cfg['url'] ?? ''), PHP_URL_HOST) ?: '—'));
        $this->line('  Database : '.($cfg['database'] ?? parse_url((string) ($cfg['url'] ?? ''), PHP_URL_PATH) ?: '—'));
        $this->newLine();

        if ($isi === []) {
            $this->info('Tidak ada data transaksi yang tersisa — sudah bersih.');

            return self::SUCCESS;
        }

        $this->warn('Akan dihapus PERMANEN:');
        $this->table(['Tabel', 'Baris'], array_map(fn ($t, $n) => [$t, $n], array_keys($isi), $isi));
        $this->line('Master (jenjang, jenis biaya, tarif, COA, pengguna, hak akses) tidak disentuh.');
        $this->line('Langganan push juga dipertahankan — ia milik perangkat staf, bukan data uji.');

        if (! $this->option('paksa') && ! $this->confirm('Lanjutkan?', false)) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $db->transaction(function () use ($db, $isi) {
            foreach (array_keys($isi) as $t) {
                $db->table($t)->delete();

                // Setel ulang urutan id kalau tabelnya memang ber-serial. Tabel
                // ber-PK string tak punya sequence — pg_get_serial_sequence
                // mengembalikan null, dan itu bukan galat.
                $seq = $db->selectOne('select pg_get_serial_sequence(?, ?) as s', [$t, 'id'])->s ?? null;
                if ($seq) {
                    $db->statement('select setval(?, 1, false)', [$seq]);
                }
            }
        });

        // Berkas CSV yang ditulis dummy:isi untuk alur Impor Santri Lama.
        // Hanya bermakna untuk koneksi lokal; berkasnya memang tinggal di mesin ini.
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
