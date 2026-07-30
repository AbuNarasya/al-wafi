<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERILAKU KE-6: `daftar_ulang` — biaya daftar ulang TAHUNAN untuk santri yang
 * sudah aktif. Sebelum ini ia cuma jenis biaya berperilaku `lain` yang bernama
 * "Daftar Ulang": punya akun, tapi tak punya tarif, tak bisa diterbitkan massal,
 * dan tak terjaga dari penerbitan ganda.
 *
 * Kenapa perilaku sendiri, bukan menumpang `lain`:
 *  • `lain` boleh berganda per santri — daftar ulang justru harus SEKALI per
 *    (santri, jenjang, tahun ajaran), dan itu ditegakkan indeks unik di bawah.
 *  • `lain` tak punya sel di grid Tarif, padahal besaran daftar ulang ditetapkan
 *    di muka lalu diposting massal.
 *
 * Bedanya dari SPP: SPP berulang tiap PERIODE (bulan), daftar ulang sekali per
 * TAHUN AJARAN. Sama-sama TIDAK bergantung jalur pendaftaran — keduanya untuk
 * santri yang sudah bersekolah, dan jalur hanya bermakna saat ia masuk.
 *
 * JANGAN TERTUKAR dengan PROSES "Daftar Ulang" (tahap 7 PPSB: calon → aktif +
 * akrual uang pangkal + terbit NIS). Namanya sama, urusannya berbeda; yang ini
 * murni komponen biaya.
 *
 * Ikut dibuat di sini: tabel `jalur_nonaktif` — penanda bahwa sebuah jalur TIDAK
 * BERLAKU pada (tahun ajaran, jenjang) tertentu. Mis. SDTQ tak punya jalur OSS
 * maupun jalur lanjutan mana pun, SMA tak punya jalur OSS. Barisnya menyimpan
 * PENGECUALIAN saja: tak ada baris = jalurnya berlaku, sehingga data lama tetap
 * sah tanpa perlu diisi apa pun.
 */
return new class extends Migration
{
    private const PERILAKU_BARU = ['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp', 'lain'];

    private const PERILAKU_LAMA = ['registrasi', 'uang_pangkal', 'perlengkapan', 'spp', 'lain'];

    /** Perilaku yang tagihannya hanya boleh SEKALI per (santri, jenjang, T.A, periode). */
    private const SEKALI_BARU = ['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp'];

    private const SEKALI_LAMA = ['registrasi', 'uang_pangkal', 'perlengkapan', 'spp'];

    public function up(): void
    {
        $this->pasangCek(self::PERILAKU_BARU);

        // Tipe yang SUDAH ADA dan jelas-jelas daftar ulang dipindahkan perilakunya.
        // Sengaja dicocokkan lewat nama, bukan kode: kode tipe dibuat sendiri oleh
        // tiap pesantren (001, 002, …) sehingga tak bisa ditebak.
        DB::table('tipe_biaya')
            ->where('perilaku', 'lain')
            ->whereRaw('lower(nama) like ?', ['%daftar ulang%'])
            ->update(['perilaku' => 'daftar_ulang', 'updated_at' => now()]);

        // Baris bawaan hanya disisipkan bila SETELAH pemindahan di atas belum ada
        // tipe berperilaku daftar_ulang. Pemasangan baru butuh barisnya (kolom
        // `jenis_biaya.tipe` ber-kunci asing ke sini), sedangkan pesantren yang
        // sudah punya tipe sendiri tak perlu ditambahi baris ke-7 yang tak diminta.
        if (! DB::table('tipe_biaya')->where('perilaku', 'daftar_ulang')->exists()) {
            $now = now();
            DB::table('tipe_biaya')->updateOrInsert(
                ['kode' => 'daftar_ulang'],
                [
                    'nama' => 'Daftar Ulang',
                    'perilaku' => 'daftar_ulang',
                    'urutan' => 4,
                    'bawaan' => true,
                    'status' => 'aktif',
                    'keterangan' => 'Tagihan tahunan santri aktif. Tarifnya tidak dibedakan per jalur, dan diakui akrual saat diterbitkan.',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        // Tagihan yang sudah terbit ikut disesuaikan supaya kolom snapshot-nya
        // tidak berbeda dari perilaku tipenya sekarang.
        DB::statement("
            UPDATE tagihan_santri t SET perilaku = 'daftar_ulang'
            FROM jenis_biaya jb, tipe_biaya tp
            WHERE jb.kode = t.kode_jenis AND tp.kode = jb.tipe
              AND tp.perilaku = 'daftar_ulang' AND t.perilaku <> 'daftar_ulang'
        ");

        $this->pasangIndeksSekali(self::SEKALI_BARU);

        Schema::create('jalur_nonaktif', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->string('kode_jenjang');
            $table->string('kode_jalur');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->cascadeOnDelete();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->cascadeOnDelete();
            $table->foreign('kode_jalur')->references('kode')->on('jalur_pendaftaran')->cascadeOnDelete();
            $table->unique(['tahun_ajaran', 'kode_jenjang', 'kode_jalur'], 'jalur_nonaktif_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jalur_nonaktif');
        $this->pasangIndeksSekali(self::SEKALI_LAMA);

        // Perilaku dikembalikan ke `lain` supaya CHECK yang lama bisa dipasang.
        DB::table('tipe_biaya')->where('perilaku', 'daftar_ulang')
            ->update(['perilaku' => 'lain', 'updated_at' => now()]);
        DB::table('tagihan_santri')->where('perilaku', 'daftar_ulang')->update(['perilaku' => 'lain']);

        $this->pasangCek(self::PERILAKU_LAMA);
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK; nilainya diganti dengan memasang ulang. */
    private function pasangCek(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE tipe_biaya DROP CONSTRAINT IF EXISTS tipe_biaya_perilaku_check');
        DB::statement("ALTER TABLE tipe_biaya ADD CONSTRAINT tipe_biaya_perilaku_check CHECK (perilaku IN ({$daftar}))");
    }

    /** Indeks parsial anti tagih-ganda; daftar perilakunya dipaku di SQL, jadi harus dipasang ulang. */
    private function pasangIndeksSekali(array $perilaku): void
    {
        $daftar = "'".implode("','", $perilaku)."'";
        DB::statement('DROP INDEX IF EXISTS tagihan_santri_sekali_per_ta');
        DB::statement("CREATE UNIQUE INDEX tagihan_santri_sekali_per_ta ON tagihan_santri
            (id_santri, perilaku, COALESCE(kode_jenjang, '-'), COALESCE(tahun_ajaran, '-'), COALESCE(periode, '-'))
            WHERE perilaku IN ({$daftar}) AND status <> 'batal'");
    }
};
