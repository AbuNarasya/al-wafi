<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENCEGAHAN POTONGAN YATIM — Fase 3c-1 (keputusan user 20 Okt 2026). "Yatim" = uang saku sudah terpotong di
 * ERP Al Wafi tetapi struknya tak tersimpan di Wafi Mart (jawaban penghubung hilang di jalan).
 *
 *  • Sisi Wafi Mart: `permintaan_kartu` = NIAT BAYAR yang disimpan SEBELUM memanggil Al Wafi, dengan nomor rujukan
 *    tetap. Permintaan yang nasibnya tak jelas karena itu selalu tercatat, bisa dipastikan, dan dicocokkan malam hari.
 *  • Sisi Al Wafi: nomor yang dipastikan "tidak ada" dicatat sebagai NISAN (`status = dibatalkan`, tanpa santri)
 *    sehingga permintaan potong yang terlambat tiba dengan nomor itu DITOLAK — jawaban "pastikan" jadi final.
 *    Karena itu `id_santri` boleh kosong (hanya untuk nisan).
 *
 * Kedua tabel ada di kedua instans (satu kode); masing-masing hanya terisi di instans yang memakainya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transaksi_dompet_toko ALTER COLUMN id_santri DROP NOT NULL');

        Schema::create('permintaan_kartu', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 64)->unique();             // = nomor rujukan di Al Wafi (ref_dompet)
            $table->enum('jenis', ['potong', 'kembalikan']);
            $table->decimal('nominal', 18, 2);
            $table->string('uid_kartu')->nullable();
            $table->string('nomor_asal', 64)->nullable();
            $table->unsignedInteger('id_shift')->nullable();
            $table->unsignedInteger('id_kasir');
            // menunggu = sedang dikirim · berhasil = terpotong di Al Wafi · gagal = PASTI tak terpotong ·
            // belum_pasti = Al Wafi tak terjangkau, kasir diminta "Cek lagi".
            $table->enum('status', ['menunggu', 'berhasil', 'gagal', 'belum_pasti'])->default('menunggu');
            $table->json('hasil')->nullable();                   // jawaban Al Wafi (nama, sisa saldo) untuk struk
            $table->string('pesan')->nullable();
            $table->unsignedInteger('id_penjualan')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->foreign('id_shift')->references('id_shift')->on('shift_kasir')->nullOnDelete();
            $table->foreign('id_kasir')->references('id_pengguna')->on('users');
            $table->foreign('id_penjualan')->references('id_penjualan')->on('penjualan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_kartu');
        DB::table('transaksi_dompet_toko')->whereNull('id_santri')->delete();
        DB::statement('ALTER TABLE transaksi_dompet_toko ALTER COLUMN id_santri SET NOT NULL');
    }
};
