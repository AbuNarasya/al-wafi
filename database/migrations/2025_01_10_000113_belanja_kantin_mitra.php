<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BELANJA KARTU DI KANTIN MITRA WAFI MART — Paket 1, sisi ERP Al Wafi (keputusan user 9 Nov 2026).
 *
 *  • `transaksi_dompet_toko.asal` — dari mana potongan datang: `mart` (kasir Wafi Mart) atau `kantin`
 *    (mitra penyewa lahan, mis. Kantin Basah). Baris lama semuanya `mart`.
 *  • `pengaturan_belanja_kantin` (satu baris):
 *      mode_batas  gabung = satu batas harian untuk semua tempat (perilaku lama) ·
 *                  pisah  = batas Wafi Mart & batas Kantin (SEMUA kantin digabung) masing-masing.
 *      batas_kantin_bawaan — batas kantin bila wali belum mengatur (mode pisah saja).
 *      saklar_wali — nyala: wali bisa mematikan belanja kantin per anak di portal; mati: pilihan itu
 *                    tak tampil dan SEMUA santri boleh belanja di kantin (pilihan wali tetap tersimpan).
 *  • `dompet_santri.batas_harian_kantin` (NULL = bawaan) & `kantin_nonaktif` (pilihan wali).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaksi_dompet_toko', function (Blueprint $table) {
            $table->string('asal', 10)->default('mart');
        });
        DB::statement("ALTER TABLE transaksi_dompet_toko ADD CONSTRAINT transaksi_dompet_toko_asal_check CHECK (asal IN ('mart', 'kantin'))");

        Schema::table('dompet_santri', function (Blueprint $table) {
            $table->decimal('batas_harian_kantin', 14, 2)->nullable();
            $table->boolean('kantin_nonaktif')->default(false);
        });

        Schema::create('pengaturan_belanja_kantin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('mode_batas', 10)->default('gabung');
            $table->decimal('batas_kantin_bawaan', 14, 2)->default(20000);
            $table->boolean('saklar_wali')->default(false);
            $table->unsignedInteger('diubah_oleh')->nullable();
            $table->foreign('diubah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE pengaturan_belanja_kantin ADD CONSTRAINT pengaturan_belanja_kantin_mode_check CHECK (mode_batas IN ('gabung', 'pisah'))");

        DB::table('pengaturan_belanja_kantin')->insert([
            'mode_batas' => 'gabung', 'batas_kantin_bawaan' => 20000, 'saklar_wali' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_belanja_kantin');
        Schema::table('dompet_santri', fn (Blueprint $t) => $t->dropColumn(['batas_harian_kantin', 'kantin_nonaktif']));
        DB::statement('ALTER TABLE transaksi_dompet_toko DROP CONSTRAINT IF EXISTS transaksi_dompet_toko_asal_check');
        Schema::table('transaksi_dompet_toko', fn (Blueprint $t) => $t->dropColumn('asal'));
    }
};
