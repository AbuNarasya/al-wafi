<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * potongan_uang_pangkal — Snapshot + siklus potongan pada satu tagihan uang
 * pangkal (1:1). status: berlaku | earned | hangus. Potongan TIDAK berjurnal (neto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potongan_uang_pangkal', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_tagihan')->unique();
            $table->integer('gelombang');
            $table->decimal('nominal_normal', 18, 2);
            $table->decimal('potongan', 18, 2);
            $table->integer('syarat_persen')->default(50);
            $table->date('tenggat');
            $table->enum('status', ['berlaku', 'earned', 'hangus'])->default('berlaku');
            $table->timestamp('dinilai_pada')->nullable();
            $table->unsignedInteger('dinilai_oleh')->nullable();
            $table->timestamps();

            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->cascadeOnDelete();
            $table->foreign('dinilai_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['status', 'tenggat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('potongan_uang_pangkal');
    }
};
