<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices — Invoice vendor (pengakuan hutang; opsional ref PO).
 * kode_coa_hutang = akun hutang usaha (field pemilih, tanpa FK).
 * status: belum_bayar | sebagian | lunas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->increments('id_invoice');
            $table->string('nomor_invoice')->unique();
            $table->string('nomor_ref_internal')->nullable(); // INV-YYMM-NNNN
            $table->date('tanggal_invoice');
            $table->date('tanggal_jatuh_tempo');
            $table->string('kode_vendor');
            $table->string('kode_unit');
            $table->string('kode_coa_hutang');
            $table->unsignedInteger('id_po')->nullable();
            $table->string('nomor_po')->nullable();
            $table->text('keterangan')->nullable();
            $table->decimal('total', 18, 2);
            $table->decimal('sisa_hutang', 18, 2);
            $table->string('status')->default('belum_bayar');
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_vendor')->references('kode_vendor')->on('vendors');
            $table->foreign('kode_unit')->references('kode_unit')->on('business_units');
            $table->foreign('id_po')->references('id_po')->on('purchase_orders');
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users');
            $table->index('kode_vendor');
            $table->index('id_po');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
