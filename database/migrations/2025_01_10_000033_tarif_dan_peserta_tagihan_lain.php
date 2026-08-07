<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KELUARGA B — tagihan yang ditagihkan MENURUT KEPESERTAAN: ekskul, kegiatan
 * khusus, program umroh. Dua tabel, dan keduanya menjawab pertanyaan berbeda.
 *
 * `tarif_tagihan_lain` menjawab "berapa untuk jenjang ini". Tarifnya bisa
 * berbeda antar jenjang — umroh SMA tidak sama dengan umroh SMP.
 *
 * SEL KOSONG DI SINI BERARTI "JENJANG ITU TIDAK IKUT", bukan "belum diisi".
 * Ini SENGAJA BERBEDA dari `tarif_biaya`, yang memperlakukan ketiadaan baris
 * sebagai kealpaan lalu MENGHENTIKAN penagihan dengan pesan. Bedanya lahir dari
 * sifat barangnya: SPP pasti ditagih ke semua jenjang, sedangkan tak ada yang
 * aneh bila SDTQ memang tidak ikut program umroh. Karena bentuknya mirip tapi
 * artinya berlawanan, layarnya wajib menyebutkan ini.
 *
 * `peserta_tagihan_lain` menjawab "siapa saja". Berbeda dari laundry — yang
 * pesertanya diturunkan dari jenjang tanpa pendaftaran — kegiatan hanya
 * ditagihkan kepada yang benar-benar ikut, jadi daftarnya memang disusun.
 *
 * `nominal` pada peserta NULLABLE, dan itu pembeda yang penting: NULL berarti
 * "ikut tarif jenjangnya" — sehingga tarif yang dikoreksi di matriks ikut
 * berlaku bagi seluruh peserta biasa tanpa menyentuh satu baris peserta pun.
 * Terisi berarti keringanan yang disengaja, dan barisnya ditandai di layar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarif_tagihan_lain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->string('kode_jenjang');
            $table->decimal('nominal', 18, 2);
            $table->timestamps();

            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->cascadeOnDelete();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->cascadeOnDelete();
            $table->unique(['kode_jenis', 'kode_jenjang'], 'tarif_tagihan_lain_sel');
        });

        Schema::create('peserta_tagihan_lain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->unsignedInteger('id_santri');
            // NULL = ikut tarif jenjangnya. Lihat catatan di atas.
            $table->decimal('nominal', 18, 2)->nullable();
            $table->string('status')->default('ikut');
            $table->timestamps();

            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->cascadeOnDelete();
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->unique(['kode_jenis', 'id_santri'], 'peserta_tagihan_lain_sekali');
            $table->index('kode_jenis');
        });

        DB::statement("ALTER TABLE peserta_tagihan_lain ADD CONSTRAINT peserta_tagihan_lain_status_check
                       CHECK (status IN ('ikut','berhenti'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('peserta_tagihan_lain');
        Schema::dropIfExists('tarif_tagihan_lain');
    }
};
