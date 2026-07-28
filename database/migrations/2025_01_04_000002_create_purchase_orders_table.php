<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** purchase_orders — Purchase Order (TIDAK menghasilkan jurnal). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->increments('id_po');
            $table->string('nomor_po')->unique();
            $table->date('tanggal_po');
            $table->string('kode_vendor');
            $table->string('kode_unit');
            $table->text('keterangan')->nullable();
            $table->decimal('total_po', 18, 2);
            $table->enum('status', ['open', 'sebagian', 'selesai', 'batal'])->default('open');
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_vendor')->references('kode_vendor')->on('vendors');
            $table->foreign('kode_unit')->references('kode_unit')->on('business_units');
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users');
            $table->index('kode_vendor');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
