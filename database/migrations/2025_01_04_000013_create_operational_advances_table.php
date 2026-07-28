<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * operational_advances — Uang Muka Belanja Operasional. Sisa = nominal -
 * nominal_diselesaikan. status: outstanding | selesai | void.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_advances', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor_ref')->unique(); // UMB-YYMM-NNNN
            $table->date('tanggal');
            $table->string('kode_unit')->nullable();
            $table->string('kode_rekening');
            $table->string('kode_coa_uang_muka');
            $table->string('nama_coa_uang_muka');
            $table->string('penerima')->nullable();
            $table->text('keterangan');
            $table->decimal('nominal', 18, 2);
            $table->decimal('nominal_diselesaikan', 18, 2)->default(0);
            $table->string('status')->default('outstanding'); // outstanding | selesai | void
            $table->string('void_reason')->nullable();
            $table->string('void_by')->nullable();
            $table->timestamp('void_at')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->unsignedInteger('id_pengajuan_sumber')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('id_pengajuan_sumber');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_advances');
    }
};
