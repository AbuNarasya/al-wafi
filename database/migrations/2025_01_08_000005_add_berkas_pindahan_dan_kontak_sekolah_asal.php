<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas pindahan dari sekolah asal + kontak sekolah asal.
 *
 * - dokumen_santri.jenis: tambah surat_keterangan_aktif, surat_keterangan_
 *   kelakuan_baik, rapot_terakhir. Enum Laravel di Postgres = varchar + CHECK,
 *   jadi constraint-nya dibuat ulang (bukan ALTER TYPE).
 * - santri: kontak kepala sekolah & wali kelas asal; kolom `angkatan` DIBUANG
 *   (tak dipakai logika mana pun — hanya form).
 */
return new class extends Migration
{
    private const JENIS_LAMA = [
        'ktp_ayah', 'ktp_ibu', 'ktp_wali', 'akta_kelahiran', 'kartu_keluarga', 'foto',
        'surat_keterangan_sekolah', 'hasil_medcheck', 'lainnya',
    ];

    private const JENIS_BARU = ['surat_keterangan_aktif', 'surat_keterangan_kelakuan_baik', 'rapot_terakhir'];

    public function up(): void
    {
        $this->setJenisCheck([...self::JENIS_LAMA, ...self::JENIS_BARU]);

        Schema::table('santri', function (Blueprint $table) {
            $table->string('cp_kepala_sekolah_asal')->nullable()->after('kepala_sekolah_asal');
            $table->string('wali_kelas_asal')->nullable()->after('cp_kepala_sekolah_asal');
            $table->string('cp_wali_kelas_asal')->nullable()->after('wali_kelas_asal');
            $table->dropColumn('angkatan');
        });
    }

    public function down(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->integer('angkatan')->nullable();
            $table->dropColumn(['cp_kepala_sekolah_asal', 'wali_kelas_asal', 'cp_wali_kelas_asal']);
        });

        // Baris berjenis baru harus dibuang dulu agar constraint lama bisa dipasang.
        DB::table('dokumen_santri')->whereIn('jenis', self::JENIS_BARU)->delete();
        $this->setJenisCheck(self::JENIS_LAMA);
    }

    /** @param  list<string>  $jenis */
    private function setJenisCheck(array $jenis): void
    {
        $daftar = implode(', ', array_map(fn ($j) => "'".$j."'", $jenis));
        DB::statement('ALTER TABLE dokumen_santri DROP CONSTRAINT IF EXISTS dokumen_santri_jenis_check');
        DB::statement("ALTER TABLE dokumen_santri ADD CONSTRAINT dokumen_santri_jenis_check CHECK (jenis::text = ANY (ARRAY[{$daftar}]::text[]))");
    }
};
