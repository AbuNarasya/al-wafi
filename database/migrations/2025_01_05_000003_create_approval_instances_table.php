<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * approval_instances — Satu dokumen yang sedang/sudah melewati rantai.
 * overbudget/belum_dianggarkan dibekukan SAAT diajukan. posted=false + belum
 * final → nominal dihitung sebagai KOMITMEN anggaran. 1 dokumen = 1 instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_instances', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_flow');
            $table->string('jenis_dokumen');
            $table->string('id_dokumen');
            $table->string('kode_bagian')->nullable();
            $table->string('kode_coa')->nullable();
            $table->integer('tahun')->nullable();
            $table->integer('bulan')->nullable();
            $table->decimal('nominal', 18, 2)->nullable();
            $table->string('status')->default('berjalan'); // berjalan | disetujui | ditolak | dibatalkan
            $table->integer('tahap_sekarang')->default(1);
            $table->boolean('overbudget')->default(false);
            $table->boolean('belum_dianggarkan')->default(false);
            $table->unsignedInteger('id_pemohon');
            $table->boolean('posted')->default(false);
            $table->timestamps();

            $table->unique(['jenis_dokumen', 'id_dokumen']);
            $table->index('status');
            $table->index(['kode_coa', 'kode_bagian', 'tahun']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_instances');
    }
};
