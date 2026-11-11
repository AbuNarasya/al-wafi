<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ASISTEN BANTUAN UNTUK STAF ERP & WAFI MART (keputusan user 11 Nov 2026).
 *
 * Mesin yang sama dengan asisten portal wali (000117). Artikel kini punya PEMBACA (`audiens`):
 * `wali` atau `staf`. Tiap instans punya database sendiri, jadi artikel staf ERP Al Wafi & Wafi Mart
 * cukup dibedakan oleh database tempat ia diimpor. Semua staf yang login boleh bertanya (tanpa hak);
 * saklar & batas hariannya terpisah dari wali. Nama layar yang sedang dibuka ikut dicatat (`layar`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artikel_bantuan', function (Blueprint $table) {
            $table->string('audiens', 10)->default('wali');
        });
        DB::statement("ALTER TABLE artikel_bantuan ADD CONSTRAINT artikel_bantuan_audiens_check CHECK (audiens IN ('wali', 'staf'))");
        Schema::table('artikel_bantuan', function (Blueprint $table) {
            $table->dropUnique(['judul']);
            $table->unique(['audiens', 'judul']);
        });

        Schema::table('percakapan_asisten', function (Blueprint $table) {
            $table->unsignedInteger('id_wali')->nullable()->change();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->cascadeOnDelete();
            $table->string('audiens', 10)->default('wali');
            $table->string('layar', 150)->nullable();
            $table->index(['id_pengguna', 'created_at']);
        });
        DB::statement('ALTER TABLE percakapan_asisten ADD CONSTRAINT percakapan_asisten_penanya_check CHECK ((id_wali IS NULL) <> (id_pengguna IS NULL))');

        Schema::table('pengaturan_asisten_wali', function (Blueprint $table) {
            $table->boolean('aktif_staf')->default(false);
            $table->unsignedSmallInteger('batas_harian_staf')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_asisten_wali', function (Blueprint $table) {
            $table->dropColumn(['aktif_staf', 'batas_harian_staf']);
        });
        DB::statement('ALTER TABLE percakapan_asisten DROP CONSTRAINT IF EXISTS percakapan_asisten_penanya_check');
        DB::table('percakapan_asisten')->whereNull('id_wali')->delete();
        Schema::table('percakapan_asisten', function (Blueprint $table) {
            $table->dropForeign(['id_pengguna']);
            $table->dropIndex(['id_pengguna', 'created_at']);
            $table->dropColumn(['id_pengguna', 'audiens', 'layar']);
            $table->unsignedInteger('id_wali')->nullable(false)->change();
        });
        DB::table('artikel_bantuan')->where('audiens', 'staf')->delete();
        Schema::table('artikel_bantuan', function (Blueprint $table) {
            $table->dropUnique(['audiens', 'judul']);
            $table->unique('judul');
        });
        DB::statement('ALTER TABLE artikel_bantuan DROP CONSTRAINT IF EXISTS artikel_bantuan_audiens_check');
        Schema::table('artikel_bantuan', function (Blueprint $table) {
            $table->dropColumn('audiens');
        });
    }
};
