<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * jenis_biaya.kode_jalur — dimensi KEDUA penentu tarif, di samping kode_jenjang.
 *
 * Sebelum ini tarif hanya bisa dibedakan per jenjang, sehingga dua baris untuk
 * jenjang yang sama (mis. uang pangkal SMP jalur OSS 70jt vs SMP reguler 50jt)
 * tak bisa dibedakan program — yang terpilih baris pertama urut kode, diam-diam
 * bisa salah tarif. Jalur yang ditulis di dalam KODE ("UP-SMP27-OSS") tidak
 * pernah terbaca query.
 *
 * NULL = berlaku untuk SEMUA jalur (tarif dasar). Isi hanya baris pengecualian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->string('kode_jalur')->nullable()->after('kode_jenjang');
            $table->foreign('kode_jalur')->references('kode')->on('jalur_pendaftaran')->restrictOnDelete();
            $table->index(['tipe', 'tahun_ajaran', 'kode_jenjang', 'kode_jalur'], 'jenis_biaya_berlaku_idx');
        });
    }

    public function down(): void
    {
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->dropForeign(['kode_jalur']);
            $table->dropIndex('jenis_biaya_berlaku_idx');
            $table->dropColumn('kode_jalur');
        });
    }
};
