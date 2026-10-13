<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BAYAR BEBERAPA TAGIHAN SEKALIGUS dari Portal Wali (keputusan user 13 Okt 2026).
 *
 * Pembukuan TIDAK berubah: tetap satu pembayaran + satu jurnal per tagihan. Yang baru
 * hanya penanda yang mengikat mereka:
 *  • `pembayaran_santri.kelompok_bayar` — sekali bayar (dompet / transfer satu bukti /
 *    satu VA). Transfer berkelompok diverifikasi atau ditolak keuangan SEKALIGUS,
 *    karena buktinya satu; kuitansi gabungan pun dicetak per kelompok.
 *  • `transaksi_midtrans_tagihan` — rincian satu VA yang membayar beberapa tagihan
 *    (jenis pesanan baru `bayar_kelompok`, biaya VA dipungut sekali).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran_santri', function (Blueprint $table) {
            $table->string('kelompok_bayar', 40)->nullable()->index();
        });

        Schema::create('transaksi_midtrans_tagihan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_transaksi');
            $table->unsignedInteger('id_tagihan');
            $table->decimal('nominal', 18, 2);
            $table->unsignedInteger('id_pembayaran')->nullable();
            $table->timestamps();

            $table->foreign('id_transaksi')->references('id')->on('transaksi_midtrans')->cascadeOnDelete();
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->restrictOnDelete();
            $table->foreign('id_pembayaran')->references('id')->on('pembayaran_santri')->nullOnDelete();
            $table->unique(['id_transaksi', 'id_tagihan']);
        });

        // Enum Laravel = varchar + CHECK: menambah nilai = buang lalu pasang ulang.
        $this->pasangJenis(['isi_dompet', 'bayar_tagihan', 'bayar_kelompok']);
    }

    public function down(): void
    {
        DB::table('transaksi_midtrans')->where('jenis', 'bayar_kelompok')->delete();
        $this->pasangJenis(['isi_dompet', 'bayar_tagihan']);
        Schema::dropIfExists('transaksi_midtrans_tagihan');
        Schema::table('pembayaran_santri', function (Blueprint $table) {
            $table->dropColumn('kelompok_bayar');
        });
    }

    private function pasangJenis(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE transaksi_midtrans DROP CONSTRAINT IF EXISTS transaksi_midtrans_jenis_check');
        DB::statement("ALTER TABLE transaksi_midtrans ADD CONSTRAINT transaksi_midtrans_jenis_check CHECK (jenis::text IN ({$daftar}))");
    }
};
