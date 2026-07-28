<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wali — Satu WALI = satu KELUARGA (kakak-adik berbagi satu Dompet Wali).
 * telepon unik (identitas login portal). Realm auth terpisah dari User staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pendapatan = ['di_bawah_5', 'juta_5_10', 'juta_10_15', 'juta_15_25', 'di_atas_25'];

        Schema::create('wali', function (Blueprint $table) use ($pendapatan) {
            $table->increments('id');
            $table->enum('kontak_utama', ['ayah', 'ibu', 'wali'])->default('ayah');
            foreach (['ayah', 'ibu', 'wali'] as $p) {
                $table->string("nama_{$p}")->nullable();
                $table->string("telepon_{$p}")->nullable();
                $table->string("email_{$p}")->nullable();
                $table->string("pekerjaan_{$p}")->nullable();
                $table->enum("pendapatan_{$p}", $pendapatan)->nullable();
            }
            $table->string('nama');
            $table->string('telepon')->unique();
            $table->string('nik')->nullable();
            $table->text('alamat')->nullable();
            $table->boolean('auto_debet')->default(false);
            $table->boolean('telepon_verified')->default(false);
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->index('nama');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wali');
    }
};
