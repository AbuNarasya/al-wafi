<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** approval_logs — Jejak audit rantai (siapa/kapan/aksi/catatan). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_instance');
            $table->integer('urutan');
            $table->unsignedInteger('id_pengguna');
            $table->string('nama_pengguna');
            $table->string('aksi'); // ajukan | approve | reject | edit
            $table->text('catatan')->nullable();
            $table->timestamp('waktu')->useCurrent();

            $table->foreign('id_instance')->references('id')->on('approval_instances')->cascadeOnDelete();
            $table->index('id_instance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
    }
};
