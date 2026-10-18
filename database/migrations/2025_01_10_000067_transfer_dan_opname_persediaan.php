<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFI MART TAHAP 1c — TRANSFER ANTAR LOKASI (dua langkah) & STOK OPNAME BERDOKUMEN.
 *
 * Transfer: kirim → stok pindah dari asal ke lokasi sistem `TRANSIT` ("Dalam Perjalanan")
 * membawa harga pokok asalnya; terima → dari TRANSIT ke tujuan. Selisih kirim–terima
 * DITAHAN di TRANSIT sampai pemegang hak persetujuan memutus: kembali ke asal, atau
 * dibukukan sebagai kehilangan (jurnal ke akun Selisih Persediaan). Pindah lokasi sendiri
 * tak berjurnal — barang & akunnya sama.
 *
 * Opname: satu dokumen per lokasi (opsional per kategori). Hitungan disimpan bertahap,
 * diajukan, lalu DISETUJUI orang lain; baru saat itu selisihnya menggerakkan stok & jurnal.
 * `stok_tercatat` dipotret SAAT angka fisik baris itu diisi — penjualan yang terjadi
 * sesudahnya tak ikut terhitung sebagai selisih.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lokasi_persediaan')->insert([
            'kode' => 'TRANSIT', 'nama' => 'Dalam Perjalanan', 'jenis' => 'transit', 'status' => 'aktif',
            'urutan' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('transfer_persediaan', function (Blueprint $table) {
            $table->increments('id_transfer');
            $table->string('nomor')->unique();
            $table->date('tanggal_kirim');
            $table->date('tanggal_terima')->nullable();
            $table->string('kode_lokasi_asal', 20);
            $table->string('kode_lokasi_tujuan', 20);
            // dikirim → (diterima | selisih → selesai) ; dikirim → batal
            $table->enum('status', ['dikirim', 'selisih', 'selesai', 'batal'])->default('dikirim');
            $table->text('keterangan')->nullable();
            $table->string('catatan_terima')->nullable();
            // Putusan atas selisih: kembali (ke asal) | hilang (dibukukan sebagai kehilangan).
            $table->enum('putusan', ['kembali', 'hilang'])->nullable();
            $table->string('catatan_putusan')->nullable();
            $table->unsignedInteger('dikirim_oleh')->nullable();
            $table->unsignedInteger('diterima_oleh')->nullable();
            $table->unsignedInteger('diputus_oleh')->nullable();
            $table->timestamp('diputus_pada')->nullable();
            $table->timestamps();

            $table->foreign('kode_lokasi_asal')->references('kode')->on('lokasi_persediaan');
            $table->foreign('kode_lokasi_tujuan')->references('kode')->on('lokasi_persediaan');
            $table->index('status');
        });

        Schema::create('transfer_persediaan_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_transfer');
            $table->string('kode_persediaan');
            $table->string('satuan', 50);
            $table->decimal('isi', 18, 4)->default(1);
            $table->decimal('kuantiti_input', 18, 4);
            // Satuan DASAR.
            $table->decimal('kuantiti', 18, 4);
            $table->decimal('nilai', 18, 2)->default(0);
            $table->decimal('kuantiti_diterima', 18, 4)->nullable();
            $table->string('keterangan')->nullable();

            $table->foreign('id_transfer')->references('id_transfer')->on('transfer_persediaan')->cascadeOnDelete();
            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory');
        });

        Schema::create('opname_persediaan', function (Blueprint $table) {
            $table->increments('id_opname');
            $table->string('nomor')->unique();
            $table->date('tanggal');
            $table->string('kode_lokasi', 20);
            $table->string('kode_kategori', 20)->nullable();
            // Bagian yang menanggung selisih (akun Selisih Persediaan = akun beban).
            $table->string('kode_bagian')->nullable();
            $table->enum('status', ['draft', 'diajukan', 'disetujui', 'batal'])->default('draft');
            $table->text('keterangan')->nullable();
            $table->string('catatan_putusan')->nullable();
            $table->unsignedInteger('dibuat_oleh')->nullable();
            $table->unsignedInteger('diajukan_oleh')->nullable();
            $table->timestamp('diajukan_pada')->nullable();
            $table->unsignedInteger('disetujui_oleh')->nullable();
            $table->timestamp('disetujui_pada')->nullable();
            $table->timestamps();

            $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
            $table->foreign('kode_kategori')->references('kode')->on('kategori_barang')->nullOnDelete();
            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian');
            $table->index('status');
        });

        Schema::create('opname_persediaan_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_opname');
            $table->string('kode_persediaan');
            // Kosong = belum dihitung → tak menggerakkan apa pun saat disetujui.
            $table->decimal('stok_fisik', 18, 4)->nullable();
            $table->decimal('stok_tercatat', 18, 4)->nullable();
            $table->timestamp('dihitung_pada')->nullable();
            $table->decimal('nilai_selisih', 18, 2)->nullable();
            $table->unsignedInteger('id_mutasi')->nullable();

            $table->foreign('id_opname')->references('id_opname')->on('opname_persediaan')->cascadeOnDelete();
            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory');
            $table->unique(['id_opname', 'kode_persediaan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opname_persediaan_detail');
        Schema::dropIfExists('opname_persediaan');
        Schema::dropIfExists('transfer_persediaan_detail');
        Schema::dropIfExists('transfer_persediaan');
        DB::table('lokasi_persediaan')->where('kode', 'TRANSIT')->delete();
    }
};
