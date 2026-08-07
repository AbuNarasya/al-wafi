<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STATUS `dihapus` — tagihan yang dikoreksi nominalnya sampai Rp 0.
 *
 * Sebelum ini koreksi menolak nol dengan alasan "menolkan tagihan bukan koreksi,
 * itu pembatalan". Kenyataannya keduanya berbeda: `batal` dipakai untuk tagihan
 * yang ditarik kembali SEBELUM menjurnal apa pun, sedangkan tagihan yang
 * dinolkan lewat koreksi JUSTRU menerbitkan jurnal penyesuaian — piutangnya
 * dibalik, dan uang yang terlanjur dibayar pindah ke Dompet Wali.
 *
 * Nilai tersendiri, bukan menumpang `lunas`, karena "sisa nol" di sini bukan
 * berarti terbayar: tagihan Rp 1.500.000 yang dihapus tanpa dibayar sepeser pun
 * akan terhitung sebagai tagihan tuntas di setiap rekap pembayaran — dan itu
 * kekeliruan yang tak bersuara.
 *
 * Enum Laravel di PostgreSQL = varchar + CHECK, jadi menambah nilainya berarti
 * membuang lalu memasang ulang constraint-nya.
 */
return new class extends Migration
{
    private const BARU = ['belum_bayar', 'sebagian', 'lunas', 'batal', 'dihapus'];

    private const LAMA = ['belum_bayar', 'sebagian', 'lunas', 'batal'];

    /** Perilaku yang tagihannya hanya boleh SEKALI per (santri, jenjang, T.A, periode). */
    private const SEKALI = ['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp'];

    public function up(): void
    {
        $this->pasangCek(self::BARU);
        $this->pasangIndeksSekali(['batal', 'dihapus']);
    }

    public function down(): void
    {
        $this->pasangIndeksSekali(['batal']);

        // Tanpa ini constraint lamanya menolak baris yang sudah terlanjur
        // berstatus `dihapus`, dan migrasi mundurnya berhenti di tengah.
        DB::table('tagihan_santri')->where('status', 'dihapus')->update(['status' => 'batal']);

        $this->pasangCek(self::LAMA);
    }

    /**
     * Indeks unik anti tagih-ganda ikut dipasang ulang.
     *
     * Ia semula mengecualikan `batal` saja. Kalau `dihapus` tak ikut
     * dikecualikan, tagihan yang sudah dihapus TETAP memblokir penerbitan ulang
     * — dan kegagalannya muncul sebagai pelanggaran indeks di tengah penerbitan
     * massal, bukan sebagai pesan yang bisa dibaca petugas. Sementara di sisi
     * lain SantriService sudah menganggap tagihan tak-berlaku sebagai "boleh
     * ditagih lagi", jadi keduanya harus sepakat.
     */
    private function pasangIndeksSekali(array $statusDikecualikan): void
    {
        $perilaku = "'".implode("','", self::SEKALI)."'";
        $status = "'".implode("','", $statusDikecualikan)."'";

        DB::statement('DROP INDEX IF EXISTS tagihan_santri_sekali_per_ta');
        DB::statement("CREATE UNIQUE INDEX tagihan_santri_sekali_per_ta ON tagihan_santri
            (id_santri, perilaku, COALESCE(kode_jenjang, '-'), COALESCE(tahun_ajaran, '-'), COALESCE(periode, '-'))
            WHERE perilaku IN ({$perilaku}) AND status NOT IN ({$status})");
    }

    private function pasangCek(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE tagihan_santri DROP CONSTRAINT IF EXISTS tagihan_santri_status_check');
        DB::statement("ALTER TABLE tagihan_santri ADD CONSTRAINT tagihan_santri_status_check CHECK (status IN ({$daftar}))");
    }
};
