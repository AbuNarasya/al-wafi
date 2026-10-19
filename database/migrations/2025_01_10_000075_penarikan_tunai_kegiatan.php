<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENARIKAN TUNAI KEGIATAN (outbound/rihlah) — rancangan disetujui 13 Okt 2026, dirinci 19 Okt.
 *
 *  • Keuangan membuat SESI (peserta, empat tanggal, maks per santri, rekening bank & kas tunai).
 *    Tahapnya DIHITUNG dari tanggal, tidak dimajukan penjadwal (cron produksi mati) — kolom
 *    `status` hanya mencatat langkah yang dilakukan orang: dana diambil, selesai, batal.
 *  • Wali mengajukan dari portal (Dompet Santri saja, kelipatan Rp 50.000, PIN). Saldonya
 *    DICADANGKAN tanpa jurnal: cadangan dihitung dari tabel ini, bukan kolom di dompet,
 *    jadi tak bisa melenceng dari daftar pengajuannya.
 *  • Kesantrian memutus (boleh nominal lebih kecil). Yang belum diputus saat batas
 *    persetujuan lewat = GUGUR — juga dihitung, tak perlu ditulis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesi_tarik_tunai', function (Blueprint $table) {
            $table->increments('id_sesi');
            $table->string('nomor')->unique();
            $table->string('nama');
            $table->text('keterangan')->nullable();
            $table->dateTime('batas_pengajuan');
            $table->dateTime('batas_persetujuan');
            $table->date('tanggal_ambil');
            $table->date('tanggal_distribusi');
            $table->decimal('maks_per_santri', 18, 2);
            $table->string('kode_coa_bank');
            $table->string('kode_coa_kas');
            // Saklar keuangan: tampil & boleh diajukan wali. Mati = sesi disembunyikan dari portal.
            $table->boolean('dibuka')->default(false);
            $table->enum('status', ['berjalan', 'dana_diambil', 'selesai', 'batal'])->default('berjalan');
            $table->string('alasan_batal')->nullable();
            $table->unsignedInteger('dibuat_oleh')->nullable();
            $table->timestamps();

            $table->foreign('kode_coa_bank')->references('kode_coa')->on('bank_accounts');
            $table->foreign('kode_coa_kas')->references('kode_coa')->on('bank_accounts');
        });

        Schema::create('peserta_tarik_tunai', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_sesi');
            $table->unsignedInteger('id_santri');
            $table->timestamps();

            $table->unique(['id_sesi', 'id_santri']);
            $table->foreign('id_sesi')->references('id_sesi')->on('sesi_tarik_tunai')->cascadeOnDelete();
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
        });

        Schema::create('pengajuan_tarik_tunai', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor')->unique();
            $table->unsignedInteger('id_sesi');
            $table->unsignedInteger('id_santri');
            $table->unsignedInteger('id_wali');
            $table->string('peran', 10);
            $table->decimal('nominal', 18, 2);
            $table->decimal('nominal_disetujui', 18, 2)->nullable();
            $table->enum('status', ['diajukan', 'disetujui', 'ditolak', 'dibatalkan', 'diserahkan', 'tak_diambil'])->default('diajukan');
            $table->string('alasan_tolak')->nullable();
            $table->unsignedInteger('diputus_oleh')->nullable();
            $table->timestamp('diputus_pada')->nullable();
            $table->timestamps();

            $table->index(['id_santri', 'status']);
            $table->foreign('id_sesi')->references('id_sesi')->on('sesi_tarik_tunai');
            $table->foreign('id_santri')->references('id')->on('santri');
            $table->foreign('id_wali')->references('id')->on('wali');
        });

        // Satu pengajuan HIDUP per santri per sesi; yang ditolak/dibatalkan boleh diulang.
        DB::statement("CREATE UNIQUE INDEX pengajuan_tarik_tunai_sekali_per_sesi ON pengajuan_tarik_tunai (id_sesi, id_santri)
            WHERE status IN ('diajukan', 'disetujui', 'diserahkan')");
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_tarik_tunai');
        Schema::dropIfExists('peserta_tarik_tunai');
        Schema::dropIfExists('sesi_tarik_tunai');
    }
};
