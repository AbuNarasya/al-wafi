<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** budget_pengajuan_detail — Baris usulan anggaran per akun per bulan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_pengajuan_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengajuan');
            $table->string('kode_coa');
            $table->string('nama_coa');
            $table->integer('bulan'); // 1..12
            $table->decimal('nominal', 18, 2);

            $table->foreign('id_pengajuan')->references('id')->on('budget_pengajuan')->cascadeOnDelete();
            $table->index('id_pengajuan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_pengajuan_detail');
    }
};
