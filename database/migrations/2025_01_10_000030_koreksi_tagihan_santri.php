<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOREKSI NOMINAL TAGIHAN — jejaknya.
 *
 * Sebelum ini, tagihan yang sudah diakrualkan tak punya jalan koreksi sama
 * sekali: `tagihan-lain` menolak yang `sudah_akrual`, dan koreksi uang pangkal
 * menolaknya juga sambil menyuruh "lewat jurnal penyesuaian oleh keuangan".
 * Masalahnya, jurnal penyesuaian membetulkan BUKU BESAR tanpa menyentuh
 * `tagihan_santri` — hasilnya neraca benar tapi tagihan per santri tetap salah,
 * dan selisihnya hanya dijembatani ingatan orang.
 *
 * Tabel ini bukan sekadar log: ia menyimpan id jurnal penyesuaiannya, sehingga
 * angka di buku pembantu selalu bisa ditelusuri balik ke barisnya di buku besar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('koreksi_tagihan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_tagihan');
            $table->decimal('nominal_lama', 18, 2);
            $table->decimal('nominal_baru', 18, 2);
            // Berapa yang SUDAH dibayar saat koreksi dilakukan — tanpa angka ini,
            // munculnya kelebihan bayar di kemudian hari tak bisa dijelaskan.
            $table->decimal('terbayar', 18, 2);
            $table->decimal('kelebihan_ke_dompet', 18, 2)->default(0);
            $table->string('alasan');
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('dikoreksi_oleh')->nullable();
            $table->timestamps();

            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->cascadeOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('dikoreksi_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('id_tagihan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('koreksi_tagihan');
    }
};
