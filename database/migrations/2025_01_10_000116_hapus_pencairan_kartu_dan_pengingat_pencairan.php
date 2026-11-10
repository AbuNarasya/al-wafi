<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10 Nov 2026 (keputusan user):
 *
 *  1. "Pencairan Belanja Kartu" lama (pindah buku belanja kartu dari kasir di instans yang SAMA ke kas toko, mig 000074)
 *     DIHAPUS sampai ke kodenya — tak terpakai sejak Wafi Mart berdiri sendiri (penggantinya Pencairan ke Wafi Mart).
 *     Tabel & kolom `id_pencairan` dibuang HANYA bila belum pernah dipakai; ada isinya → dibiarkan (data tak hilang),
 *     cukup kodenya yang pergi. Baris hak akses modul `pencairan-kartu` dibersihkan.
 *  2. `company_settings.batas_hari_pencairan_toko` — pengingat Tugas Saya di ERP Al Wafi bila belanja kartu sudah
 *     lebih dari N hari belum dicairkan ke Wafi Mart (0 = tak diingatkan). Bawaan 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pencairan_kartu') && ! DB::table('pencairan_kartu')->exists()) {
            Schema::table('penjualan', function (Blueprint $table) {
                $table->dropForeign(['id_pencairan']);
                $table->dropIndex(['metode', 'id_pencairan']);
                $table->dropColumn('id_pencairan');
            });
            Schema::table('retur_penjualan', function (Blueprint $table) {
                $table->dropForeign(['id_pencairan']);
                $table->dropColumn('id_pencairan');
            });
            Schema::drop('pencairan_kartu');
        }
        DB::table('hak_akses_modul')->where('kode_modul', 'pencairan-kartu')->delete();

        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('batas_hari_pencairan_toko')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn('batas_hari_pencairan_toko'));
        // Pencairan Belanja Kartu lama tidak dipulihkan — kodenya sudah dihapus.
    }
};
