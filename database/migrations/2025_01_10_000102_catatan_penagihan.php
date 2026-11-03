<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENAGIHAN TUNGGAKAN (keputusan user 3 Nov 2026): admin sekolah menagih wali secara manual dan mencatat
 * prosesnya PER TAGIHAN — kapan ditagih, lewat apa, tanggapan wali, janji bayar, langkah berikutnya.
 * Catatan tak bisa diubah/dihapus (jejak penagihan); salah ketik ditimpa catatan baru.
 * Janji bayar yang lewat sementara tagihannya belum lunas masuk penanda Tugas Saya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catatan_penagihan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_tagihan');
            $table->unsignedInteger('id_santri');
            $table->date('ditagih_pada');
            $table->enum('cara', ['telepon', 'whatsapp', 'tatap_muka', 'surat', 'lainnya']);
            $table->text('tanggapan');
            $table->date('janji_tanggal')->nullable();
            $table->decimal('janji_nominal', 18, 2)->nullable();
            $table->text('tindak_lanjut')->nullable();
            $table->unsignedInteger('dicatat_oleh')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->cascadeOnDelete();
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['id_tagihan', 'id']);
            $table->index(['id_santri']);
            $table->index(['janji_tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catatan_penagihan');
    }
};
