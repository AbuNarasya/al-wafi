<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNDRY PAKET 1 (keputusan user 3 Nov 2026) — alur disamakan dengan kenyataan:
 *   diterima (timbang saja) → DIPERIKSA (timbang ulang + jenis & jumlah pakaian) → diproses → selesai (dipacking) → diserahkan.
 *
 * `berat` tetap kolom yang dihitung kuota: saat diterima = berat penerimaan, sesudah diperiksa = berat timbang ulang.
 * `berat_terima` menyimpan berat penerimaan supaya selisihnya bisa ditampilkan. Kantong lama: berat_terima = berat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry', function (Blueprint $table) {
            $table->decimal('berat_terima', 6, 2)->nullable();
            $table->timestamp('diperiksa_pada')->nullable();
            $table->unsignedInteger('diperiksa_oleh')->nullable();
            $table->foreign('diperiksa_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });
        DB::table('laundry')->update(['berat_terima' => DB::raw('berat')]);

        DB::statement('ALTER TABLE laundry DROP CONSTRAINT IF EXISTS laundry_status_check');
        DB::statement("ALTER TABLE laundry ADD CONSTRAINT laundry_status_check CHECK (status IN ('diterima', 'diperiksa', 'diproses', 'selesai', 'diserahkan', 'dibatalkan'))");
    }

    public function down(): void
    {
        DB::table('laundry')->where('status', 'diperiksa')->update(['status' => 'diterima']);
        DB::statement('ALTER TABLE laundry DROP CONSTRAINT IF EXISTS laundry_status_check');
        DB::statement("ALTER TABLE laundry ADD CONSTRAINT laundry_status_check CHECK (status IN ('diterima', 'diproses', 'selesai', 'diserahkan', 'dibatalkan'))");
        Schema::table('laundry', function (Blueprint $table) {
            $table->dropForeign(['diperiksa_oleh']);
            $table->dropColumn(['berat_terima', 'diperiksa_pada', 'diperiksa_oleh']);
        });
    }
};
