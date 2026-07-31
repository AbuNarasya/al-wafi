<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * JADWAL PERUBAHAN SANTRI — kenaikan, pengulangan, kelulusan, dan perpindahan
 * jenjang DITETAPKAN lebih dulu, BERLAKU saat tahun ajaran tujuan dimulai.
 *
 * Sebelum ini modul Kenaikan Tingkat mengubah santri seketika saat tombolnya
 * ditekan. Padahal keputusannya memang harus diambil SEBELUM tahun barunya
 * mulai, sehingga santri berjalan berbulan-bulan dengan tingkat & tahun berjalan
 * yang belum berlaku — dan seluruh pencarian tarif yang bersandar pada kedua
 * kolom itu ikut mendahului kalender.
 *
 * Yang disimpan di sini adalah santri ini AKAN MENJADI APA pada T.A tujuan.
 * Penerapannya menyentuh empat kolom yang sama untuk semua keputusan:
 * `kode_jenjang`, `tingkat`, `jalur`, `tahun_ajaran_berjalan` (+ `status` &
 * `tanggal_lulus` untuk kelulusan).
 *
 * STATUS:
 *  • `siap`          — syaratnya sudah lengkap, tinggal menunggu tanggalnya.
 *    Naik/mengulang/lulus langsung `siap`: keputusan staf ADALAH syaratnya.
 *  • `menunggu_ppsb` — khusus `melanjutkan`. Perpindahan jenjang menuntut uang
 *    (registrasi, uang pangkal, perlengkapan), kelayakan (med check, dokumen),
 *    dan nominal yang diketik petugas per santri — tak satu pun bisa ditebak
 *    penjadwal. Menjadi `siap` begitu siklus PPSB-nya dieksekusi.
 *  • `diterapkan`    — sudah menyala.
 *  • `dibatalkan`    — ditarik sebelum menyala.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_perubahan_santri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_santri')->constrained('santri', 'id')->cascadeOnDelete();
            // T.A TUJUAN — sekaligus penentu KAPAN jadwal ini menyala, lewat
            // `tahun_ajaran.tanggal_mulai`. Tanggalnya sengaja tidak disalin ke
            // sini: kalender bisa dikoreksi, dan jadwal harus ikut koreksinya.
            $table->string('tahun_ajaran');
            $table->string('keputusan'); // naik | mengulang | melanjutkan | lulus
            $table->string('status')->default('siap');

            // Keadaan TUJUAN. Untuk `naik`/`mengulang` jenjangnya tetap; untuk
            // `melanjutkan` ketiganya datang dari siklus PPSB-nya.
            $table->string('kode_jenjang_tujuan')->nullable();
            $table->unsignedSmallInteger('tingkat_tujuan')->nullable();
            $table->string('kode_jalur_tujuan')->nullable();
            $table->date('tanggal_lulus')->nullable();

            // Siklus PPSB yang menjadi syarat (hanya `melanjutkan`).
            $table->foreignId('id_pendaftaran')->nullable()->constrained('pendaftaran', 'id')->nullOnDelete();

            $table->string('batch')->nullable();
            $table->unsignedBigInteger('ditetapkan_oleh')->nullable();
            $table->timestamp('ditetapkan_pada')->nullable();
            $table->timestamp('diterapkan_pada')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->cascadeOnDelete();
            $table->foreign('kode_jenjang_tujuan')->references('kode')->on('jenjang');
            $table->foreign('kode_jalur_tujuan')->references('kode')->on('jalur_pendaftaran');
            $table->foreign('ditetapkan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();

            // Penerap memindai kolom ini tiap halaman dibuka — harus murah.
            $table->index(['status', 'tahun_ajaran'], 'jadwal_perubahan_status_ta');
        });

        // Satu jadwal HIDUP per (santri, T.A). Yang batal & yang sudah diterapkan
        // dikecualikan: santri yang jadwalnya ditarik harus bisa dijadwalkan
        // ulang, dan riwayat penerapan tak boleh menghalangi tahun berikutnya.
        DB::statement("
            CREATE UNIQUE INDEX jadwal_perubahan_hidup_unik
            ON jadwal_perubahan_santri (id_santri, tahun_ajaran)
            WHERE status IN ('siap', 'menunggu_ppsb')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_perubahan_santri');
    }
};
