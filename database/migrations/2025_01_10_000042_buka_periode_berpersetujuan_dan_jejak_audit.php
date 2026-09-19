<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DUA PENGETATAN KONTROL.
 *
 * 1. TUTUP BUKU JADI MENGIKAT. Sebelum ini `PeriodService::GRACE_DAYS`
 *    membiarkan periode yang SUDAH ditutup tetap boleh dijurnal oleh siapa pun
 *    selama 30 hari. Artinya laporan yang sudah dicetak dan disampaikan ke
 *    yayasan bisa berubah angkanya setelahnya — tanpa jejak, tanpa pemberitahuan,
 *    dan tanpa seorang pun perlu meminta izin. Toleransi itu dicabut; membuka
 *    kembali periode kini lewat permohonan bernama dan beralasan.
 *
 *    Admin keuangan MENGAJUKAN, direktur keuangan MENYETUJUI — dua orang, dua
 *    sumbu hak akses pada modul `buka-periode` (buat = mengajukan, ubah =
 *    memutuskan). Pemohon tak boleh memutuskan permohonannya sendiri.
 *
 * 2. JEJAK AUDIT MENJANGKAU MODUL KEUANGAN. `activity_log` selama ini hanya
 *    ditulisi modul kesantrian; jurnal, kas, invoice, pengajuan, dan perintah
 *    pembayaran tak meninggalkan jejak sama sekali — justru di situ jejak paling
 *    dibutuhkan. Kolom-kolom di bawah mengubahnya dari catatan teks bebas
 *    menjadi jejak yang bisa disaring dan ditelusuri sampai ke nilai lamanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permohonan_buka_periode', function (Blueprint $table) {
            $table->increments('id');
            // 'bulan' → satu bulan; 'tahun' → membatalkan tutup buku tahunan.
            $table->enum('lingkup', ['bulan', 'tahun'])->default('bulan');
            $table->integer('tahun');
            $table->integer('bulan')->nullable();
            $table->text('alasan');
            $table->enum('status', ['diajukan', 'disetujui', 'ditolak'])->default('diajukan');
            $table->unsignedInteger('diajukan_oleh');
            $table->timestamp('diajukan_pada');
            $table->unsignedInteger('diputus_oleh')->nullable();
            $table->timestamp('diputus_pada')->nullable();
            $table->text('catatan_keputusan')->nullable();
            $table->timestamps();

            $table->foreign('diajukan_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->foreign('diputus_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['tahun', 'bulan']);
            $table->index('status');
        });

        // Satu permohonan HIDUP per periode. Tanpa ini, permohonan yang sama
        // bisa diajukan berulang oleh beberapa orang lalu disetujui dua kali,
        // dan jejaknya jadi mustahil dibaca. NULL pada `bulan` (lingkup tahun)
        // dianggap BERBEDA oleh unique index PostgreSQL, jadi COALESCE dipakai.
        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX permohonan_buka_periode_hidup
             ON permohonan_buka_periode (tahun, COALESCE(bulan, 0), lingkup)
             WHERE status = 'diajukan'"
        );

        Schema::table('activity_log', function (Blueprint $table) {
            // Kode modul hak akses — supaya layar Jejak Audit bisa disaring
            // memakai kosakata yang sama dengan matriks hak akses.
            $table->string('modul')->nullable()->after('aksi');
            $table->string('ref_jenis')->nullable()->after('modul');
            $table->string('ref_id')->nullable()->after('ref_jenis');
            // {kolom: {lama, baru}} — nilai SEBELUM dan SESUDAH. Tanpa nilai
            // lama, jejak hanya bisa berkata "ada yang mengubah", tak pernah
            // "dari berapa menjadi berapa" — dan yang kedua itulah yang dicari
            // saat sebuah angka dipersoalkan.
            $table->jsonb('perubahan')->nullable()->after('detail');
            $table->string('ip')->nullable()->after('perubahan');
            $table->string('user_agent')->nullable()->after('ip');

            $table->index('modul');
            $table->index(['ref_jenis', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['modul']);
            $table->dropIndex(['ref_jenis', 'ref_id']);
            $table->dropColumn(['modul', 'ref_jenis', 'ref_id', 'perubahan', 'ip', 'user_agent']);
        });
        Schema::dropIfExists('permohonan_buka_periode');
    }
};
