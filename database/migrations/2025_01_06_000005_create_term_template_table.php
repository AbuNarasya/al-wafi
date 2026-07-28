<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** term_template — S&K umum berversi (tidak pernah disunting di tempat). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_template', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('versi')->unique();
            $table->string('judul');
            $table->text('isi');
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->date('berlaku_mulai');
            $table->unsignedInteger('dibuat_oleh');
            $table->timestamps();

            $table->foreign('dibuat_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_template');
    }
};
