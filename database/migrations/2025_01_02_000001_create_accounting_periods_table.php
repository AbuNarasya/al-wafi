<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_periods — Periode akuntansi (tutup buku) per tahun+bulan.
 * status: open | closed. Unik per (tahun, bulan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tahun');
            $table->integer('bulan'); // 1..12
            $table->string('status')->default('open'); // open | closed
            $table->integer('closed_by')->nullable();
            $table->string('nama_closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->unique(['tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
