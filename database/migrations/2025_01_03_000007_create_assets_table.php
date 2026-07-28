<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * assets — Aset tetap. metode_depresiasi: garis_lurus | saldo_menurun.
 * status: aktif | dilepas | draft. kode_coa & kategori_aset = field pemilih
 * (tanpa FK). sumber_ref = transaksi asal bila aset dibuat otomatis dari pembelian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->string('kode_aset')->primary();
            $table->string('nama_aset');
            $table->string('kategori_aset')->nullable();
            $table->decimal('kuantiti', 18, 4)->default(1);
            $table->decimal('harga_perolehan', 18, 2);
            $table->date('tanggal_perolehan');
            $table->integer('umur_manfaat'); // bulan
            $table->string('metode_depresiasi')->default('garis_lurus');
            $table->decimal('nilai_residu', 18, 2)->default(0);
            $table->decimal('akumulasi_depresiasi', 18, 2)->default(0);
            $table->string('kode_coa')->nullable();
            $table->string('status')->default('aktif'); // aktif | dilepas | draft
            $table->string('sumber_ref')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
