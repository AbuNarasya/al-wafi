<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DANA TERIKAT — wakaf, donasi berperuntukan, beasiswa, dana bantuan.
 *
 * Persoalannya bukan mencatat penerimaannya; itu sudah bisa lewat Kas Masuk.
 * Persoalannya adalah PEMBATASAN PERUNTUKAN: uang wakaf pembangunan tidak
 * boleh terpakai untuk gaji, dan sampai sekarang aplikasi tak punya satu pun
 * cara untuk mengatakan itu — apalagi menolaknya.
 *
 * Tiga bagian:
 *
 *  1. `dana` — masternya. Jenisnya mengikuti kosakata pelaporan nirlaba:
 *     tidak terikat, terikat temporer (batasnya gugur bila peruntukannya
 *     tercapai), terikat permanen (pokoknya tak boleh dipakai selamanya).
 *
 *  2. `dana_akun` — daftar akun beban yang BOLEH dibebani dana itu. Kosong
 *     berarti tanpa pembatasan akun; terisi berarti di luar daftar ditolak.
 *
 *  3. `journal_lines.kode_dana` — dimensinya, sejajar dengan `kode_bagian` dan
 *     `kode_unit` yang sudah ada. Inilah yang membuat tiap rupiah bisa
 *     ditelusuri asal dan peruntukannya, dan yang membuat laporan per donatur
 *     mungkin dibuat sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana', function (Blueprint $table) {
            $table->string('kode_dana')->primary();
            $table->string('nama_dana');
            $table->enum('jenis', ['tidak_terikat', 'terikat_temporer', 'terikat_permanen'])
                ->default('tidak_terikat');
            $table->string('donatur')->nullable();
            $table->text('peruntukan')->nullable();
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->decimal('target_nominal', 18, 2)->default(0);
            $table->enum('status', ['aktif', 'nonaktif', 'selesai'])->default('aktif');
            $table->integer('urutan')->default(0);
            $table->timestamps();

            $table->index('jenis');
            $table->index('status');
        });

        Schema::create('dana_akun', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_dana');
            $table->string('kode_coa');
            $table->timestamps();

            $table->foreign('kode_dana')->references('kode_dana')->on('dana')->cascadeOnDelete();
            $table->foreign('kode_coa')->references('kode_coa')->on('coa_detail')->cascadeOnDelete();
            $table->unique(['kode_dana', 'kode_coa']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            // Sejajar dengan kode_bagian & kode_unit: dimensi melekat di BARIS,
            // bukan di kepala transaksi. Satu kas keluar bisa membebani dua dana
            // sekaligus, dan memaksanya satu dana per dokumen hanya akan
            // melahirkan dokumen-dokumen pecahan yang menyulitkan penelusuran.
            $table->string('kode_dana')->nullable()->after('kode_unit');
            $table->foreign('kode_dana')->references('kode_dana')->on('dana')->restrictOnDelete();
            $table->index('kode_dana');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['kode_dana']);
            $table->dropIndex(['kode_dana']);
            $table->dropColumn('kode_dana');
        });
        Schema::dropIfExists('dana_akun');
        Schema::dropIfExists('dana');
    }
};
