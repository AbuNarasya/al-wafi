<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TARIF DAFTAR ULANG dibedakan PER TINGKAT — daftar ulang kelas 1 tak sama dengan
 * kelas 6. SPP tetap satu angka per jenjang.
 *
 * `tingkat` NULL = tarif tidak dibedakan per tingkat; itulah keadaan seluruh
 * perilaku lain (registrasi, uang pangkal, perlengkapan, SPP). Hanya daftar ulang
 * yang mengisinya — lihat `TarifService::PER_TINGKAT`.
 *
 * Unique index dipasang ulang memuat `tingkat`: tanpa itu, tarif tingkat 1 dan
 * tingkat 2 akan dianggap baris kembar dan yang kedua ditolak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarif_biaya', function (Blueprint $table) {
            $table->unsignedSmallInteger('tingkat')->nullable()->after('kode_jalur');
        });

        // COALESCE dipakai lagi karena di PostgreSQL dua NULL dianggap BERBEDA,
        // sehingga unique biasa tak menghalangi baris kembar bertingkat kosong.
        DB::statement('DROP INDEX IF EXISTS tarif_biaya_sel_unik');
        DB::statement("CREATE UNIQUE INDEX tarif_biaya_sel_unik ON tarif_biaya
            (tahun_ajaran, COALESCE(kode_jenjang, '-'), COALESCE(kode_jalur, '-'), perilaku, COALESCE(tingkat, 0))");
    }

    public function down(): void
    {
        // Sel daftar ulang bertingkat akan bertabrakan begitu kolomnya hilang,
        // jadi yang bertingkat dibuang lebih dulu — nilainya memang tak punya
        // tempat lagi di skema lama.
        DB::table('tarif_biaya')->whereNotNull('tingkat')->delete();

        DB::statement('DROP INDEX IF EXISTS tarif_biaya_sel_unik');
        DB::statement("CREATE UNIQUE INDEX tarif_biaya_sel_unik ON tarif_biaya
            (tahun_ajaran, COALESCE(kode_jenjang, '-'), COALESCE(kode_jalur, '-'), perilaku)");

        Schema::table('tarif_biaya', function (Blueprint $table) {
            $table->dropColumn('tingkat');
        });
    }
};
