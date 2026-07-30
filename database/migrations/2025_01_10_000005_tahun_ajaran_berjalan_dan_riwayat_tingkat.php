<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DUA TAHUN AJARAN pada seorang santri — sumber kekacauan yang selama ini
 * ditumpangkan pada satu kolom:
 *
 *  • `santri.tahun_ajaran`          = tahun MASUK (angkatan). TIDAK pernah maju.
 *  • `santri.tahun_ajaran_berjalan` = tahun yang sedang DIJALANI. Maju tiap kali
 *    santri naik tingkat atau naik jenjang.
 *
 * Tanpa pemisahan ini, tarif SPP santri angkatan 2026 akan selamanya dicari di
 * T.A 2026 — padahal ia sudah kelas 3 pada T.A 2028 dan tarifnya sudah berubah
 * dua kali. Diisikan sama dengan angkatan untuk data yang sudah ada; itulah
 * keadaan yang benar bagi santri yang belum pernah naik.
 *
 * `riwayat_tingkat` mencatat di mana seorang santri berada pada tiap tahun
 * ajaran. Satu baris per (santri, T.A) — tanpa ini, "kelas berapa dia tahun lalu"
 * hanya bisa dijawab dengan menebak dari tingkat sekarang dikurangi selisih tahun,
 * yang langsung salah begitu ada santri mengulang atau masuk di tengah jenjang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->string('tahun_ajaran_berjalan')->nullable()->after('tahun_ajaran');
            $table->foreign('tahun_ajaran_berjalan')->references('kode')->on('tahun_ajaran');
        });

        // Santri yang belum pernah naik: tahun berjalan = tahun masuk.
        DB::statement('UPDATE santri SET tahun_ajaran_berjalan = tahun_ajaran WHERE tahun_ajaran IS NOT NULL');

        Schema::create('riwayat_tingkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_santri')->constrained('santri', 'id')->cascadeOnDelete();
            $table->string('tahun_ajaran');
            $table->string('kode_jenjang');
            $table->unsignedSmallInteger('tingkat')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->cascadeOnDelete();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->cascadeOnDelete();
            // Satu santri satu tempat per tahun ajaran.
            $table->unique(['id_santri', 'tahun_ajaran'], 'riwayat_tingkat_unik');
        });

        // Keadaan sekarang dijadikan baris pertama riwayatnya, supaya tahun yang
        // sedang berjalan pun punya catatan — bukan hanya tahun-tahun sesudahnya.
        DB::statement("
            INSERT INTO riwayat_tingkat (id_santri, tahun_ajaran, kode_jenjang, tingkat, catatan, created_at, updated_at)
            SELECT id, tahun_ajaran_berjalan, kode_jenjang, tingkat, 'Keadaan awal saat riwayat tingkat dibuat.', now(), now()
            FROM santri
            WHERE tahun_ajaran_berjalan IS NOT NULL AND kode_jenjang IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_tingkat');
        Schema::table('santri', function (Blueprint $table) {
            $table->dropForeign(['tahun_ajaran_berjalan']);
            $table->dropColumn('tahun_ajaran_berjalan');
        });
    }
};
