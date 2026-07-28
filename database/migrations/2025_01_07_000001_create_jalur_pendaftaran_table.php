<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * jalur_pendaftaran — MASTER jalur pendaftaran (reguler/tahfizh/beasiswa, dst.)
 * yang dikelola admin. `kode` dipakai sebagai nilai santri.jalur (relasi longgar
 * via teks, selaras kode_jenjang). Jalur yang dipakai santri tak boleh dihapus.
 * (Port dari branch dev app asli — dijadikan master terkelola.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jalur_pendaftaran', function (Blueprint $table) {
            $table->string('kode')->primary();
            $table->string('nama');
            $table->text('keterangan')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jalur_pendaftaran');
    }
};
