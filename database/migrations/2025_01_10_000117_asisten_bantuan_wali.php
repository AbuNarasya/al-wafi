<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ASISTEN BANTUAN PORTAL WALI (keputusan user 11 Nov 2026, cakupan A).
 *
 * Wali bertanya dalam bahasa sehari-hari; jawaban disusun HANYA dari artikel panduan (`artikel_bantuan`).
 * AI memakai paket gratis Groq / Gemini (penyedia dipilih di layar pengaturan, kunci di .env). Bila AI
 * mati, kunci kosong, kuota habis, atau galat → asisten jatuh ke pencarian artikel saja (tanpa AI).
 * Tak ada data anak yang dikirim; angka panjang & email di pertanyaan disamarkan sebelum keluar.
 *
 * Nilai awal: MATI — staf menyalakan sesudah artikel diperiksa & kunci dipasang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_asisten_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('aktif')->default(false);
            $table->string('penyedia', 10)->default('groq');
            $table->string('model_groq', 80)->default('llama-3.3-70b-versatile');
            $table->string('model_gemini', 80)->default('gemini-2.5-flash-lite');
            // Batas pertanyaan per keluarga per hari (WIB) — menjaga kuota gratis tak dihabiskan satu orang.
            $table->unsignedSmallInteger('batas_harian')->default(20);
            $table->unsignedInteger('diubah_oleh')->nullable();
            $table->foreign('diubah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE pengaturan_asisten_wali ADD CONSTRAINT pengaturan_asisten_wali_penyedia_check CHECK (penyedia IN ('groq', 'gemini'))");
        DB::table('pengaturan_asisten_wali')->insert(['aktif' => false, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('artikel_bantuan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('judul', 200)->unique();
            $table->text('isi');
            $table->text('kata_kunci')->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('percakapan_asisten', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('id_wali');
            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
            // Pertanyaan SESUDAH disamarkan — yang tersimpan sama dengan yang terkirim ke penyedia AI.
            $table->text('pertanyaan');
            $table->text('jawaban');
            $table->string('sumber', 10); // groq | gemini | artikel
            $table->json('artikel')->nullable(); // id artikel rujukan
            $table->string('galat', 300)->nullable(); // sebab jatuh ke pencarian artikel
            $table->unsignedInteger('durasi_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['id_wali', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('percakapan_asisten');
        Schema::dropIfExists('artikel_bantuan');
        Schema::dropIfExists('pengaturan_asisten_wali');
    }
};
