<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pendaftaran` jadi SATU BARIS PER SIKLUS PENERIMAAN, bukan satu per santri.
 *
 * Sebabnya: kenaikan jenjang internal harus melewati proses PPSB (seleksi, med
 * check, penerimaan), tetapi santrinya SUDAH aktif. Dua hal menghalanginya:
 *   • `Tahap::TRANSISI` hanya mengizinkan `aktif → alumni|keluar`;
 *   • berkas/nilai/med check disimpan di `pendaftaran` yang unique per santri,
 *     sehingga pendaftaran kedua tak punya tempat.
 *
 * Memutar `santri.status` kembali ke `calon` bukan jalan keluar: santri kelas
 * akhir yang sedang mendaftar ke jenjang berikutnya akan berhenti berstatus aktif
 * di tengah tahun ajaran — padahal ia masih bersekolah, masih ditagih SPP, dan
 * masih masuk laporan.
 *
 * Karena itu STATUS TAHAPAN pindah ke `pendaftaran`:
 *   • `santri.status` hanya menyatakan keadaan santri (aktif/alumni/keluar/…);
 *   • `pendaftaran.status` menyatakan sejauh mana SATU siklus pendaftaran berjalan.
 * Untuk pendaftar baru keduanya bergerak seiring, jadi alur PPSB yang sudah ada
 * tak berubah perilakunya.
 *
 * `jenis`:
 *   • `baru`     — pendaftar dari luar; tahapan penuh (registrasi → berkas → …).
 *   • `lanjutan` — kenaikan jenjang internal; tahap BERKAS dilewati (santrinya
 *     sudah dikenal & dokumennya sudah ada), registrasi tetap ditagih bila sel
 *     tarifnya terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pendaftaran', function (Blueprint $table) {
            // Sasaran pendaftaran ini — untuk baris lama sama dengan data santrinya.
            $table->string('tahun_ajaran')->nullable()->after('id_santri');
            $table->string('kode_jenjang')->nullable()->after('tahun_ajaran');
            $table->string('kode_jalur')->nullable()->after('kode_jenjang');
            $table->string('jenis')->default('baru')->after('kode_jalur');
            $table->string('status')->nullable()->after('jenis');
            $table->string('nomor')->nullable()->after('status');
        });

        // Isi dari data santri SEBELUM unique dibuang, supaya tak ada baris
        // setengah terisi bila migrasi berhenti di tengah.
        DB::statement("
            UPDATE pendaftaran p SET
                tahun_ajaran = s.tahun_ajaran,
                kode_jenjang = s.kode_jenjang,
                kode_jalur   = s.jalur,
                jenis        = 'baru',
                status       = s.status,
                nomor        = s.no_pendaftaran
            FROM santri s
            WHERE s.id = p.id_santri
        ");

        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->dropUnique('pendaftaran_id_santri_unique');
            $table->index(['id_santri', 'tahun_ajaran'], 'pendaftaran_santri_ta_index');

            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran');
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang');
            $table->foreign('kode_jalur')->references('kode')->on('jalur_pendaftaran');
        });

        // Satu siklus pendaftaran per (santri, T.A, jenjang tujuan). Lewat ekspresi
        // COALESCE karena di PostgreSQL dua NULL dianggap berbeda — tanpa itu,
        // santri lama tanpa jenjang bisa punya baris kembar tanpa halangan.
        //
        // PARSIAL: siklus yang BERAKHIR GAGAL dikecualikan. Pendaftaran lanjutan
        // yang dibatalkan atau tidak lulus harus boleh diulang ke sasaran yang
        // sama — kalau tidak, satu pembatalan menutup jenjang itu selamanya.
        $gagal = "'".implode("','", ['mengundurkan_diri', 'tidak_lulus', 'gagal_medcheck'])."'";
        DB::statement("CREATE UNIQUE INDEX pendaftaran_siklus_unik ON pendaftaran
            (id_santri, COALESCE(tahun_ajaran, '-'), COALESCE(kode_jenjang, '-'))
            WHERE status IS NULL OR status NOT IN ({$gagal})");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pendaftaran_siklus_unik');

        // Sisakan satu baris per santri (yang paling awal) agar unique lama bisa
        // dipasang kembali; siklus lanjutan memang tak punya tempat di skema lama.
        DB::statement('DELETE FROM pendaftaran WHERE id NOT IN (SELECT MIN(id) FROM pendaftaran GROUP BY id_santri)');

        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->dropForeign(['tahun_ajaran']);
            $table->dropForeign(['kode_jenjang']);
            $table->dropForeign(['kode_jalur']);
            $table->dropIndex('pendaftaran_santri_ta_index');
            $table->unique('id_santri', 'pendaftaran_id_santri_unique');
            $table->dropColumn(['tahun_ajaran', 'kode_jenjang', 'kode_jalur', 'jenis', 'status', 'nomor']);
        });
    }
};
