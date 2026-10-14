<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu "Data Wali & Santri" di Portal Wali (14 Okt 2026): wali boleh mengganti
 * email keluarganya sendiri. Email = identitas masuk, jadi alamat BARU dibuktikan
 * lewat kode yang dikirim ke alamat itu — tujuan OTP baru `ganti_email`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->pasangTujuanOtp(['masuk', 'daftar', 'ttd', 'pin', 'ganti_email']);
    }

    public function down(): void
    {
        DB::table('otp_wali')->where('tujuan', 'ganti_email')->delete();
        $this->pasangTujuanOtp(['masuk', 'daftar', 'ttd', 'pin']);
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK: menambah nilai = buang lalu pasang ulang. */
    private function pasangTujuanOtp(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE otp_wali DROP CONSTRAINT IF EXISTS otp_wali_tujuan_check');
        DB::statement("ALTER TABLE otp_wali ADD CONSTRAINT otp_wali_tujuan_check CHECK (tujuan IN ({$daftar}))");
    }
};
