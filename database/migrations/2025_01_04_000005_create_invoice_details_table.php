<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** invoice_details — Baris invoice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_invoice');
            $table->string('kode_coa');
            $table->string('nama_coa');
            $table->text('keterangan')->nullable();
            $table->decimal('kuantiti', 18, 4)->nullable();
            $table->decimal('harga_satuan', 18, 2)->nullable();
            $table->decimal('total', 18, 2);
            $table->string('kode_persediaan')->nullable();

            $table->foreign('id_invoice')->references('id_invoice')->on('invoices')->cascadeOnDelete();
            $table->index('id_invoice');
            $table->index('kode_coa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_details');
    }
};
