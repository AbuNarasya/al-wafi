<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perizinan Tahap 1 (keputusan user 4 Nov 2026), berlaku untuk insidental & kelak rutin:
 *  • batas pengajuan H-n per jenis izin (`batas_hari`, NULL = tanpa batas) menggantikan `batas_jam`;
 *  • mode persetujuan per jenis: `otomatis` (langsung disetujui) atau `petugas` (tingkat 1/2);
 *  • penjemput: dijemput WALI (ayah/ibu/wali, data diambil dari data keluarga) atau DIWAKILKAN
 *    (isian tambahan; yang wajib dicentang petugas di `pengaturan_perizinan.wakil_wajib`).
 */
return new class extends Migration
{
    public const ISIAN_WAKIL = ['nama', 'hubungan', 'hp', 'identitas', 'foto_identitas'];

    public function up(): void
    {
        Schema::table('tipe_izin', function (Blueprint $table) {
            $table->unsignedSmallInteger('batas_hari')->nullable();
            $table->string('persetujuan', 10)->default('petugas');
        });
        // Batas lama dalam jam → hari (dibulatkan ke atas); 0 jam = tanpa batas.
        DB::statement('UPDATE tipe_izin SET batas_hari = CEIL(batas_jam / 24.0) WHERE batas_jam > 0');
        Schema::table('tipe_izin', fn (Blueprint $table) => $table->dropColumn('batas_jam'));
        DB::statement("ALTER TABLE tipe_izin ADD CONSTRAINT tipe_izin_persetujuan_check CHECK (persetujuan IN ('otomatis', 'petugas'))");

        Schema::table('pengaturan_perizinan', function (Blueprint $table) {
            $table->json('wakil_wajib')->nullable();
        });
        DB::table('pengaturan_perizinan')->update(['wakil_wajib' => json_encode(self::ISIAN_WAKIL)]);

        Schema::table('perizinan', function (Blueprint $table) {
            $table->boolean('disetujui_otomatis')->default(false);
            $table->string('penjemput_jenis', 10)->nullable();     // wali | wakil
            $table->string('penjemput_peran', 10)->nullable();     // ayah | ibu | wali (bila dijemput wali)
            $table->string('penjemput_identitas', 50)->nullable(); // NIK / no. identitas (wakil)
            $table->string('penjemput_foto_path')->nullable();
            $table->string('penjemput_foto_nama')->nullable();
            $table->text('penjemput_keterangan')->nullable();
        });
        DB::statement("ALTER TABLE perizinan ADD CONSTRAINT perizinan_penjemput_jenis_check CHECK (penjemput_jenis IS NULL OR penjemput_jenis IN ('wali', 'wakil'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE perizinan DROP CONSTRAINT IF EXISTS perizinan_penjemput_jenis_check');
        Schema::table('perizinan', fn (Blueprint $table) => $table->dropColumn(['disetujui_otomatis', 'penjemput_jenis', 'penjemput_peran',
            'penjemput_identitas', 'penjemput_foto_path', 'penjemput_foto_nama', 'penjemput_keterangan']));
        Schema::table('pengaturan_perizinan', fn (Blueprint $table) => $table->dropColumn('wakil_wajib'));
        DB::statement('ALTER TABLE tipe_izin DROP CONSTRAINT IF EXISTS tipe_izin_persetujuan_check');
        Schema::table('tipe_izin', fn (Blueprint $table) => $table->unsignedSmallInteger('batas_jam')->default(0));
        DB::statement('UPDATE tipe_izin SET batas_jam = batas_hari * 24 WHERE batas_hari IS NOT NULL');
        Schema::table('tipe_izin', fn (Blueprint $table) => $table->dropColumn(['batas_hari', 'persetujuan']));
    }
};
