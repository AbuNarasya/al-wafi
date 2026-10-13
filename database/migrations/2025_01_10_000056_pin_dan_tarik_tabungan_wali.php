<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PIN TRANSAKSI DOMPET + PENGAJUAN TARIK TABUNGAN (Portal Wali, keputusan 13 Okt 2026).
 *
 * PIN per ORANG (keluarga + peran), bukan per keluarga: ayah & ibu tak perlu
 * berbagi PIN, dan jejak transaksi menyebut siapa pelakunya. Hanya hash bcrypt
 * yang disimpan. Salah lima kali → terkunci 30 menit.
 *
 * Tabungan tak bisa dipindah langsung oleh wali: ia MENGAJUKAN, keuangan yang
 * menyetujui, dan baru saat itu saldo pindah Tabungan → Dompet Wali (berjurnal).
 *
 * OTP mendapat tujuan baru `pin`: membuat PIN pertama & lupa PIN dikonfirmasi
 * lewat email — sesi yang dipulihkan dari "ingat perangkat" saja tak cukup
 * untuk menyetel PIN yang membuka dompet.
 */
return new class extends Migration
{
    private const PERAN = ['ayah', 'ibu', 'wali'];

    public function up(): void
    {
        Schema::create('pin_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->enum('peran', self::PERAN);
            $table->string('pin_hash');
            $table->smallInteger('gagal')->default(0);
            $table->timestamp('terkunci_sampai')->nullable();
            $table->timestamp('diubah_pada')->nullable();
            $table->timestamps();

            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
            $table->unique(['id_wali', 'peran']);
        });

        Schema::create('pengajuan_tarik_tabungan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor')->unique();
            $table->unsignedInteger('id_santri');
            $table->unsignedInteger('id_wali');
            $table->enum('peran', self::PERAN);
            $table->decimal('nominal', 18, 2);
            $table->text('alasan');
            $table->enum('status', ['menunggu', 'disetujui', 'ditolak', 'batal'])->default('menunggu');
            $table->unsignedInteger('diputus_oleh')->nullable();
            $table->timestamp('diputus_pada')->nullable();
            $table->text('alasan_tolak')->nullable();
            // Mutasi keluar dari tabungan yang lahir saat disetujui (jurnalnya menempel di sana).
            $table->unsignedInteger('id_mutasi')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->restrictOnDelete();
            $table->foreign('id_wali')->references('id')->on('wali')->restrictOnDelete();
            $table->foreign('diputus_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->foreign('id_mutasi')->references('id')->on('mutasi_dompet')->nullOnDelete();
            $table->index(['status', 'created_at']);
        });

        $this->pasangTujuanOtp(['masuk', 'daftar', 'ttd', 'pin']);
    }

    public function down(): void
    {
        DB::table('otp_wali')->where('tujuan', 'pin')->delete();
        $this->pasangTujuanOtp(['masuk', 'daftar', 'ttd']);
        Schema::dropIfExists('pengajuan_tarik_tabungan');
        Schema::dropIfExists('pin_wali');
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK: menambah nilai = buang lalu pasang ulang. */
    private function pasangTujuanOtp(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE otp_wali DROP CONSTRAINT IF EXISTS otp_wali_tujuan_check');
        DB::statement("ALTER TABLE otp_wali ADD CONSTRAINT otp_wali_tujuan_check CHECK (tujuan IN ({$daftar}))");
    }
};
