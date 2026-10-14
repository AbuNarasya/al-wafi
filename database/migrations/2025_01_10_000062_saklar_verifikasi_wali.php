<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar verifikasi data wali pindah dari `.env` ke layar staf (permintaan user
 * 14 Okt 2026): kampanye yang tuntas dalam seminggu ditutup staf sendiri, tanpa SSH.
 *
 * Tabel tunggal (id = 1) sendiri, BUKAN kolom di company_settings: baris itu
 * mewajibkan nama perusahaan & periode pembukuan, jadi tak bisa dibuat diam-diam
 * di database yang belum diisi. Sekaligus mencatat siapa terakhir mengubahnya.
 *
 * Nilai awal = saklar `.env` lama lewat config (BUKAN env(): di server config sudah
 * di-cache, dan env() di luar berkas config membaca kosong) — dev.erp yang sedang
 * menyala tetap menyala, ERP yang tak pernah menyalakannya tetap mati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_verifikasi_wali', function (Blueprint $table) {
            $table->integer('id')->primary()->default(1);
            $table->boolean('aktif')->default(false);
            $table->unsignedInteger('diubah_oleh')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('diubah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });

        DB::table('pengaturan_verifikasi_wali')->insert([
            'id' => 1, 'aktif' => (bool) config('app.verifikasi_wali_aktif'), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_verifikasi_wali');
    }
};
