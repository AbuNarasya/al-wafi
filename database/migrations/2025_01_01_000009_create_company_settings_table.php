<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * company_settings — Baris tunggal (singleton, id=1). periode_awal_pembukuan
 * menggerakkan Laba/Rugi Tahun Berjalan pada Neraca. bulan_awal_anggaran (1..12)
 * = awal Tahun Anggaran (hanya memengaruhi modul anggaran).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->integer('id')->primary()->default(1);
            $table->string('nama_perusahaan');
            $table->string('jenis_perusahaan')->default('PT');
            $table->text('alamat')->nullable();
            $table->string('telepon')->nullable();
            $table->string('email')->nullable();
            $table->string('npwp')->nullable();
            $table->string('mata_uang')->default('IDR');
            $table->date('periode_awal_pembukuan');
            $table->integer('bulan_awal_anggaran')->default(1);
            $table->boolean('topup_tunai_dompet_santri')->default(true);
            $table->text('keterangan')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
