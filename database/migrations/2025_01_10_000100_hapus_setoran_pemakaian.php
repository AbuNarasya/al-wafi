<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setoran Laundry & Matriks Tarif Layanan (cara tagih `pemakaian`) DIHAPUS
 * menyeluruh (keputusan user 2 Nov 2026) — laundry kini modul tersendiri
 * (grup LAUNDRY, migrasi 000098–000099) yang menerbitkan tagihannya sendiri.
 *
 *  - jenis biaya bercara tagih `pemakaian` (Laundry SDTQ/SMP/SMA) TETAP ada,
 *    cara tagihnya dikosongkan (= langsung) supaya bisa dipakai modul laundry;
 *  - CHECK `cara_tagih` tinggal `kepesertaan` (atau kosong);
 *  - hak akses modul `setoran-laundry` & `tarif-pemakaian` dibuang;
 *  - tabel `setoran_pemakaian` & `tarif_pemakaian` dibuang.
 *
 * Pengaman: bila masih ada setoran tercatat, migrasi BERHENTI — timbangan yang
 * benar-benar terjadi tak boleh lenyap diam-diam. Saat ditulis, produksi & dev
 * sama-sama 0 setoran.
 *
 * Tak bisa dibalik dengan data: down() hanya memulihkan bentuk tabel kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('setoran_pemakaian') && ($n = DB::table('setoran_pemakaian')->count()) > 0) {
            throw new RuntimeException("Masih ada {$n} setoran pemakaian tercatat — pindahkan / tagih dulu sebelum fitur lamanya dihapus.");
        }

        DB::table('jenis_biaya')->where('cara_tagih', 'pemakaian')->update(['cara_tagih' => null]);
        DB::statement('ALTER TABLE jenis_biaya DROP CONSTRAINT IF EXISTS jenis_biaya_cara_tagih_check');
        DB::statement("ALTER TABLE jenis_biaya ADD CONSTRAINT jenis_biaya_cara_tagih_check CHECK (cara_tagih IS NULL OR cara_tagih IN ('kepesertaan'))");

        DB::table('hak_akses_modul')->whereIn('kode_modul', ['setoran-laundry', 'tarif-pemakaian'])->delete();

        Schema::dropIfExists('setoran_pemakaian');
        Schema::dropIfExists('tarif_pemakaian');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE jenis_biaya DROP CONSTRAINT IF EXISTS jenis_biaya_cara_tagih_check');
        DB::statement("ALTER TABLE jenis_biaya ADD CONSTRAINT jenis_biaya_cara_tagih_check CHECK (cara_tagih IS NULL OR cara_tagih IN ('pemakaian','kepesertaan'))");

        Schema::create('tarif_pemakaian', function ($table) {
            $table->increments('id');
            $table->string('kode_jenis')->unique();
            $table->decimal('tarif_satuan', 18, 2);
            $table->string('nama_satuan');
            $table->decimal('kuota_gratis', 12, 2)->nullable();
            $table->timestamps();
            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->cascadeOnDelete();
        });
        Schema::create('setoran_pemakaian', function ($table) {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->unsignedInteger('id_santri');
            $table->date('tanggal');
            $table->decimal('kuantitas', 12, 2);
            $table->string('catatan')->nullable();
            $table->unsignedInteger('id_tagihan')->nullable();
            $table->unsignedInteger('dicatat_oleh')->nullable();
            $table->timestamps();
            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->cascadeOnDelete();
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->nullOnDelete();
            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['kode_jenis', 'id_tagihan', 'tanggal']);
            $table->index(['id_santri', 'tanggal']);
        });
    }
};
