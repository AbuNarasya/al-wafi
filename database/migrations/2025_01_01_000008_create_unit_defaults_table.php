<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * unit_defaults — Unit bisnis DEFAULT per modul asal jurnal. Dipakai postJournal
 * hanya bila pemanggil tidak menentukan kode_unit. onDelete: Restrict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_defaults', function (Blueprint $table) {
            $table->string('sumber_modul')->primary(); // JurnalUmum, PinjamanBank, SPP, ...
            $table->string('kode_unit');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('kode_unit')->references('kode_unit')->on('business_units')->restrictOnDelete();
            $table->index('kode_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_defaults');
    }
};
