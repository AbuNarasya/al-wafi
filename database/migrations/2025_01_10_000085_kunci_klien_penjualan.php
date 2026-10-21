<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KUNCI KLIEN PER PERCOBAAN BAYAR (audit 21 Okt 2026, Paket 3). Layar kasir membuat satu kunci acak per keranjang
 * dan mengirimnya bersama "Bayar". Bila koneksi putus SESUDAH server menyimpan, mengulang "Bayar" dengan kunci yang
 * sama mengembalikan struk yang sudah ada — bukan struk kedua (tunai) atau potongan kartu kedua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penjualan', function (Blueprint $table) {
            $table->string('kunci_klien', 64)->nullable()->unique();
        });
        Schema::table('permintaan_kartu', function (Blueprint $table) {
            $table->string('kunci_klien', 64)->nullable();
            $table->index(['kunci_klien', 'id_kasir']);
        });
    }

    public function down(): void
    {
        Schema::table('permintaan_kartu', function (Blueprint $table) {
            $table->dropIndex(['kunci_klien', 'id_kasir']);
            $table->dropColumn('kunci_klien');
        });
        Schema::table('penjualan', fn (Blueprint $t) => $t->dropColumn('kunci_klien'));
    }
};
