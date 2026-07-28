<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** activity_log — Audit trail aksi pengguna. Hanya created_at. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->string('aksi');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('id_pengguna');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
