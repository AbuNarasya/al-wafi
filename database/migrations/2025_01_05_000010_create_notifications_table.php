<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** notifications — Notifikasi dalam aplikasi. Hanya created_at. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengguna');
            $table->string('judul');
            $table->text('pesan');
            $table->string('jenis'); // approval_edit | approval_menunggu | ...
            $table->string('ref_jenis')->nullable();
            $table->string('ref_id')->nullable();
            $table->boolean('dibaca')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->cascadeOnDelete();
            $table->index(['id_pengguna', 'dibaca']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
