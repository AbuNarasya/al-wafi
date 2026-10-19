<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BELANJA KARTU (D2) — struk dibayar dengan saldo DOMPET SANTRI lewat kartu RFID.
 *
 *  • `penjualan.metode = 'dompet'` + santri, UID kartu, dan mutasi dompet yang memotongnya.
 *    Saldo terpotong saat struk disimpan; jurnalnya (D Titipan Uang Saku / K Pendapatan)
 *    menyusul RINGKAS di tutup shift, sama dengan penjualan tunai.
 *  • Retur struk kartu mengembalikan uangnya ke DOMPET, bukan dari laci — `retur_penjualan.metode`.
 *  • `id_pencairan` disiapkan untuk D3 (pencairan mingguan ke kas toko).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penjualan', function (Blueprint $table) {
            $table->unsignedInteger('id_santri')->nullable();
            $table->string('uid_kartu', 64)->nullable();
            $table->unsignedInteger('id_mutasi_dompet')->nullable();
            $table->unsignedInteger('id_pencairan')->nullable();

            $table->foreign('id_santri')->references('id')->on('santri')->nullOnDelete();
            $table->index(['metode', 'id_pencairan']);
            $table->index(['id_santri', 'waktu']);
        });

        Schema::table('retur_penjualan', function (Blueprint $table) {
            $table->string('metode', 20)->default('tunai');
            $table->unsignedInteger('id_mutasi_dompet')->nullable();
        });

        // Potret tutup shift: penjualan kartu (tak masuk laci). `total_retur` sejak ini berarti
        // retur yang DIBAYAR TUNAI dari laci saja — retur struk kartu kembali ke dompet.
        Schema::table('shift_kasir', function (Blueprint $table) {
            $table->decimal('total_dompet', 18, 2)->default(0)->after('total_tunai');
        });
    }

    public function down(): void
    {
        Schema::table('shift_kasir', function (Blueprint $table) {
            $table->dropColumn('total_dompet');
        });
        Schema::table('retur_penjualan', function (Blueprint $table) {
            $table->dropColumn(['metode', 'id_mutasi_dompet']);
        });
        Schema::table('penjualan', function (Blueprint $table) {
            $table->dropForeign(['id_santri']);
            $table->dropIndex(['metode', 'id_pencairan']);
            $table->dropIndex(['id_santri', 'waktu']);
            $table->dropColumn(['id_santri', 'uid_kartu', 'id_mutasi_dompet', 'id_pencairan']);
        });
    }
};
