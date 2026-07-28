<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** purchase_order_details — Baris PO. Sisa = kuantiti - qty_invoiced. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_po');
            $table->string('kode_coa');
            $table->string('nama_coa');
            $table->text('keterangan')->nullable();
            $table->decimal('kuantiti', 18, 4);
            $table->decimal('harga_satuan', 18, 2);
            $table->decimal('total', 18, 2);
            $table->decimal('qty_invoiced', 18, 4)->default(0);
            $table->string('kode_persediaan')->nullable();

            $table->foreign('id_po')->references('id_po')->on('purchase_orders')->cascadeOnDelete();
            $table->index('id_po');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_details');
    }
};
