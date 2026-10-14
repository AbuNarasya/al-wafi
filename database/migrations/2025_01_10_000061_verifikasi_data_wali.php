<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VERIFIKASI DATA WALI (keputusan user 14 Okt 2026) — staf membuat link unik per
 * keluarga dan mengirimnya lewat WhatsApp; wali membuka link, memeriksa &
 * membetulkan data keluarga dan anak-anaknya, lalu membuktikan email aktifnya
 * dengan kode. Email itu menjadi akun Portal Wali.
 *
 *  • Link berlaku 30 hari sejak tanggal MULAI yang dipilih staf.
 *  • Yang disimpan sidik sha256 kodenya (untuk mencocokkan) + salinan
 *    terenkripsi APP_KEY (supaya link yang SAMA bisa dikirim ulang).
 *  • Satu link aktif per keluarga — dijaga indeks unik parsial.
 *  • OTP mendapat tujuan baru `verifikasi`: membuktikan email yang SUDAH tercatat.
 */
return new class extends Migration
{
    private const PERAN = ['ayah', 'ibu', 'wali'];

    public function up(): void
    {
        Schema::create('verifikasi_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->string('token_hash', 64)->unique();
            $table->text('token_enkripsi');
            $table->timestamp('berlaku_mulai');
            $table->timestamp('berlaku_sampai');
            $table->timestamp('dikirim_pada')->nullable();
            $table->timestamp('dibuka_pertama')->nullable();
            $table->timestamp('dibuka_terakhir')->nullable();
            $table->timestamp('selesai_pada')->nullable();
            $table->enum('selesai_peran', self::PERAN)->nullable();
            $table->string('selesai_email')->nullable();
            $table->timestamp('dicabut_pada')->nullable();
            $table->unsignedInteger('dibuat_oleh')->nullable();
            $table->timestamps();

            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
            $table->foreign('dibuat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX verifikasi_wali_satu_aktif ON verifikasi_wali (id_wali) WHERE dicabut_pada IS NULL');

        $this->pasangTujuanOtp(['masuk', 'daftar', 'ttd', 'pin', 'ganti_email', 'verifikasi']);
    }

    public function down(): void
    {
        DB::table('otp_wali')->where('tujuan', 'verifikasi')->delete();
        $this->pasangTujuanOtp(['masuk', 'daftar', 'ttd', 'pin', 'ganti_email']);
        Schema::dropIfExists('verifikasi_wali');
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK: menambah nilai = buang lalu pasang ulang. */
    private function pasangTujuanOtp(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE otp_wali DROP CONSTRAINT IF EXISTS otp_wali_tujuan_check');
        DB::statement("ALTER TABLE otp_wali ADD CONSTRAINT otp_wali_tujuan_check CHECK (tujuan IN ({$daftar}))");
    }
};
