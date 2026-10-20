<?php

use App\Support\Instans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KASIR WAFI MART LEWAT PENGHUBUNG — Fase 3b (keputusan user 20 Okt 2026). Di instans Wafi Mart santrinya
 * tak ada di database sendiri (dompetnya milik ERP Al Wafi), jadi struk kartu menyimpan SNAPSHOT dari jawaban
 * penghubung: nama santri, nomor rujukan transaksi di Al Wafi (`ref_dompet`), dan sisa uang saku untuk struk.
 *
 * `company_settings.kode_coa_kartu_santri` = akun yang DIDEBET saat tutup shift untuk penjualan kartu:
 * kosong → Titipan Uang Saku (instans Al Wafi, mode lokal); di Wafi Mart → Piutang ke Al Wafi (1.1.03.001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penjualan', function (Blueprint $table) {
            $table->string('nama_kartu')->nullable();
            $table->string('ref_dompet', 64)->nullable()->unique();
            $table->decimal('sisa_saldo_kartu', 18, 2)->nullable();
        });
        Schema::table('retur_penjualan', function (Blueprint $table) {
            $table->string('ref_dompet', 64)->nullable()->unique();
        });
        Schema::table('penjualan', function (Blueprint $table) {
            $table->string('ref_dompet_batal', 64)->nullable();
        });
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('kode_coa_kartu_santri')->nullable();
            $table->foreign('kode_coa_kartu_santri')->references('kode_coa')->on('coa_detail')->nullOnDelete();
        });

        if (Instans::adalah('wafimart') && DB::table('coa_detail')->where('kode_coa', '1.1.03.001')->exists()) {
            DB::table('company_settings')->whereNull('kode_coa_kartu_santri')->update(['kode_coa_kartu_santri' => '1.1.03.001']);
        }
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['kode_coa_kartu_santri']);
            $table->dropColumn('kode_coa_kartu_santri');
        });
        Schema::table('retur_penjualan', fn (Blueprint $t) => $t->dropColumn('ref_dompet'));
        Schema::table('penjualan', fn (Blueprint $t) => $t->dropColumn(['nama_kartu', 'ref_dompet', 'sisa_saldo_kartu', 'ref_dompet_batal']));
    }
};
