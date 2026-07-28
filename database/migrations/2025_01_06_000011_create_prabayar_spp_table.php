<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** prabayar_spp — Saldo SPP dibayar di muka (lawan: Pendapatan Diterima Dimuka). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prabayar_spp', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri')->unique();
            $table->decimal('saldo', 18, 2)->default(0);
            $table->timestamps();
            $table->foreign('id_santri')->references('id')->on('santri')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prabayar_spp');
    }
};
