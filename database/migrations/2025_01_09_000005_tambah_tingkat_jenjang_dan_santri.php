<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TINGKAT (kelas) santri.
 *
 * Dua kolom, dua peran yang berbeda:
 *  • `jenjang.jumlah_tingkat` — BERAPA tingkat yang dimiliki jenjang itu
 *    (SDTQ 6, SMP 3, SMA 3). Disimpan di master, bukan dipaku di kode, supaya
 *    jenjang baru cukup didaftarkan lewat form tanpa menyunting program.
 *  • `santri.tingkat` — tingkat SANTRI ITU, diisi saat pendaftaran PPSB dan
 *    dibatasi 1..jumlah_tingkat jenjangnya.
 *
 * `santri.tingkat` sengaja NULLABLE walau pendaftaran baru mewajibkannya:
 * santri yang sudah ada (termasuk hasil impor data awal) belum punya nilainya,
 * dan menolak baris lama dengan NOT NULL berarti migrasi ini gagal di produksi.
 * Tingkat mereka diisi lewat menu master data per jenjang.
 */
return new class extends Migration
{
    /** Jumlah tingkat bawaan; hanya diterapkan pada jenjang yang kodenya cocok. */
    private const BAWAAN = ['SDTQ' => 6, 'SMP' => 3, 'SMA' => 3];

    public function up(): void
    {
        Schema::table('jenjang', function (Blueprint $table) {
            $table->unsignedSmallInteger('jumlah_tingkat')->nullable()->after('nama');
        });

        Schema::table('santri', function (Blueprint $table) {
            $table->unsignedSmallInteger('tingkat')->nullable()->after('kode_jenjang');
        });

        foreach (self::BAWAAN as $kode => $jumlah) {
            DB::table('jenjang')->where('kode', $kode)->update(['jumlah_tingkat' => $jumlah]);
        }
    }

    public function down(): void
    {
        Schema::table('jenjang', fn (Blueprint $t) => $t->dropColumn('jumlah_tingkat'));
        Schema::table('santri', fn (Blueprint $t) => $t->dropColumn('tingkat'));
    }
};
