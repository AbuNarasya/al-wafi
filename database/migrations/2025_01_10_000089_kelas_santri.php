<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KELAS (ROMBEL) — keputusan user 21 Okt 2026, dari data santri eksisting ("Data FIX 100%"):
 * kelas putra & putri dipisah di SMP/SMA (7A - ABU BAKAR… vs 7 SEVILLA), campur di SDTQ.
 *
 *  • `kelas` — master: jenjang, tingkat, nama, peruntukan (L = putra, P = putri, C = campur).
 *  • `santri.id_kelas` — kelas SAAT INI (boleh kosong). Santri baru ditempatkan admin sesudah aktif;
 *    kenaikan tingkat / pergantian tahun berjalan mengosongkannya (Santri::booted) untuk ditempatkan ulang.
 *  • Riwayat kelas per tahun ajaran sengaja BELUM disimpan (keputusan user: ditunda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_jenjang');
            $table->unsignedSmallInteger('tingkat');
            $table->string('nama', 120);
            $table->enum('peruntukan', ['L', 'P', 'C'])->default('C');
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->unique(['kode_jenjang', 'tingkat', 'nama']);
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang');
        });

        Schema::table('santri', function (Blueprint $table) {
            $table->unsignedInteger('id_kelas')->nullable()->after('tingkat');
            $table->foreign('id_kelas')->references('id')->on('kelas');
            $table->index('id_kelas');
        });
    }

    public function down(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->dropForeign(['id_kelas']);
            $table->dropColumn('id_kelas');
        });
        Schema::dropIfExists('kelas');
    }
};
