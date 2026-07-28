<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pengajuan_pembayaran — Dokumen pengajuan (§4). jenis: pembayaran | uang_muka |
 * penyelesaian_uang_muka. Unit melekat di BARIS (bukan header). kode_coa_hutang
 * ditetapkan keuangan saat verifikasi. sisa_hutang diisi saat posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_pembayaran', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor')->unique(); // PB-YYMM-NNNNN
            $table->date('tanggal');
            $table->string('jenis')->default('pembayaran');
            $table->string('kode_bagian');
            $table->string('kode_coa_hutang')->nullable();
            $table->decimal('nominal', 18, 2);
            $table->decimal('sisa_hutang', 18, 2)->default(0);
            $table->text('keterangan');
            $table->string('referensi')->nullable();
            $table->string('status')->default('diajukan');
            $table->unsignedInteger('id_uang_muka')->nullable();
            $table->string('kode_rekening')->nullable();
            $table->string('void_reason')->nullable();
            $table->string('void_by')->nullable();
            $table->timestamp('void_at')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian')->restrictOnDelete();
            $table->index('status');
            $table->index('kode_bagian');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_pembayaran');
    }
};
