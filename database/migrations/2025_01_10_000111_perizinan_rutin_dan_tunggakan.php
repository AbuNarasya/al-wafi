<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perizinan Tahap 2 & 3 (keputusan user 4 Nov 2026).
 *
 * TAHAP 2 — PERIZINAN RUTIN: Penjengukan & Kepulangan. Tanggalnya ditentukan pesantren lewat JADWAL; wali
 * hanya mengajukan untuk jadwal yang dibuka. Jadwal dibangkitkan dari MATRIKS aturan per (jenis, jenjang,
 * ikhwan/akhwat): "hari X ke-N dalam bulan, jam mulai → +D hari jam selesai"; tiap jadwal tetap bisa digeser,
 * ditiadakan (beralasan), atau ditambah jadwal khusus. Pengajuannya tetap baris `perizinan` (+ `id_jadwal`),
 * jadi persetujuan, pos RFID, kabar & riwayat dipakai ulang.
 *
 * TAHAP 3 — KEBIJAKAN TUNGGAKAN per jenis izin (insidental & rutin): `tidak` · `tolak` (izin tak diberikan /
 * tak boleh keluar) · `tahan_kembali` (boleh keluar, tak boleh kembali sebelum tunggakan diselesaikan).
 * Ukurannya: jumlah tagihan lewat jatuh tempo & belum lunas ≥ N, lingkup `spp` atau `semua` tagihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipe_izin', function (Blueprint $table) {
            $table->boolean('rutin')->default(false);
            $table->string('tunggakan_kebijakan', 15)->default('tidak');
            $table->unsignedSmallInteger('tunggakan_periode')->default(3);
            $table->string('tunggakan_lingkup', 10)->default('semua');
        });
        DB::statement("ALTER TABLE tipe_izin ADD CONSTRAINT tipe_izin_tunggakan_check CHECK (tunggakan_kebijakan IN ('tidak', 'tolak', 'tahan_kembali') AND tunggakan_lingkup IN ('spp', 'semua') AND tunggakan_periode >= 1)");

        $kini = now();
        DB::table('tipe_izin')->insert([
            ['kode' => 'penjengukan', 'nama' => 'Penjengukan', 'pulang' => false, 'wajib_keperluan' => false, 'rutin' => true, 'urutan' => 10,
                'aktif' => true, 'persetujuan' => 'petugas', 'created_at' => $kini, 'updated_at' => $kini],
            ['kode' => 'kepulangan', 'nama' => 'Kepulangan', 'pulang' => true, 'wajib_keperluan' => false, 'rutin' => true, 'urutan' => 11,
                'aktif' => true, 'persetujuan' => 'petugas', 'created_at' => $kini, 'updated_at' => $kini],
        ]);

        // Matriks aturan: satu sel per (jenis, jenjang, jenis kelamin).
        Schema::create('aturan_perizinan_rutin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_tipe', 30);
            $table->string('kode_jenjang');
            $table->string('jenis_kelamin', 1);                     // L | P
            $table->unsignedTinyInteger('hari');                     // 0 = Ahad … 6 = Sabtu (hari mulai)
            $table->unsignedTinyInteger('pekan_ke');                 // hari itu yang ke-N dalam bulan (1–5)
            $table->time('jam_mulai');
            $table->unsignedTinyInteger('lama_hari')->default(0);    // selesai = tanggal mulai + n hari
            $table->time('jam_selesai');
            $table->timestamps();
            $table->unique(['kode_tipe', 'kode_jenjang', 'jenis_kelamin']);
            $table->foreign('kode_tipe')->references('kode')->on('tipe_izin');
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE aturan_perizinan_rutin ADD CONSTRAINT aturan_perizinan_rutin_check CHECK (jenis_kelamin IN ('L', 'P') AND hari <= 6 AND pekan_ke BETWEEN 1 AND 5 AND lama_hari <= 14)");

        Schema::create('jadwal_perizinan_rutin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_tipe', 30);
            $table->timestamp('mulai');
            $table->timestamp('selesai');
            $table->string('status', 12)->default('aktif');         // aktif | ditiadakan
            $table->text('alasan_ditiadakan')->nullable();
            $table->string('catatan', 500)->nullable();
            $table->boolean('khusus')->default(false);              // ditambah manual, bukan dari matriks
            $table->timestamp('pengingat_pada')->nullable();        // kabar "batas pengajuan" sudah dikirim
            $table->unsignedInteger('dibuat_oleh')->nullable();
            $table->timestamps();
            $table->foreign('kode_tipe')->references('kode')->on('tipe_izin');
            $table->foreign('dibuat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['kode_tipe', 'mulai']);
        });
        DB::statement("ALTER TABLE jadwal_perizinan_rutin ADD CONSTRAINT jadwal_perizinan_rutin_check CHECK (status IN ('aktif', 'ditiadakan') AND selesai > mulai)");

        Schema::create('jadwal_perizinan_rutin_cakupan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_jadwal');
            $table->string('kode_jenjang');
            $table->string('jenis_kelamin', 1);
            $table->unique(['id_jadwal', 'kode_jenjang', 'jenis_kelamin']);
            $table->foreign('id_jadwal')->references('id')->on('jadwal_perizinan_rutin')->cascadeOnDelete();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->cascadeOnDelete();
        });

        Schema::table('perizinan', function (Blueprint $table) {
            $table->unsignedInteger('id_jadwal')->nullable();
            $table->foreign('id_jadwal')->references('id')->on('jadwal_perizinan_rutin');
            $table->index('id_jadwal');
        });
    }

    public function down(): void
    {
        Schema::table('perizinan', function (Blueprint $table) {
            $table->dropForeign(['id_jadwal']);
            $table->dropColumn('id_jadwal');
        });
        Schema::dropIfExists('jadwal_perizinan_rutin_cakupan');
        Schema::dropIfExists('jadwal_perizinan_rutin');
        Schema::dropIfExists('aturan_perizinan_rutin');
        DB::table('tipe_izin')->whereIn('kode', ['penjengukan', 'kepulangan'])->delete();
        DB::statement('ALTER TABLE tipe_izin DROP CONSTRAINT IF EXISTS tipe_izin_tunggakan_check');
        Schema::table('tipe_izin', fn (Blueprint $table) => $table->dropColumn(['rutin', 'tunggakan_kebijakan', 'tunggakan_periode', 'tunggakan_lingkup']));
    }
};
