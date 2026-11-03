<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KUOTA LAUNDRY MATRIKS jenjang × ikhwan/akhwat (keputusan user 3 Nov 2026, "seperti matriks biaya"):
 *   sel (jenjang, bagian) → kosong = baris BAWAAN bagian itu (pengaturan_laundry.kuota_ikhwan_kg / kuota_akhwat_kg).
 * Angka lama tak hilang: kuota bawaan lama → kedua bagian; kuota per jenjang lama → kedua sel jenjang itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengaturan_laundry', function (Blueprint $table) {
            $table->decimal('kuota_ikhwan_kg', 6, 2)->default(20);
            $table->decimal('kuota_akhwat_kg', 6, 2)->default(20);
        });
        DB::table('pengaturan_laundry')->update(['kuota_ikhwan_kg' => DB::raw('kuota_bawaan_kg'), 'kuota_akhwat_kg' => DB::raw('kuota_bawaan_kg')]);
        Schema::table('pengaturan_laundry', fn (Blueprint $table) => $table->dropColumn('kuota_bawaan_kg'));

        Schema::table('pengaturan_laundry_jenjang', function (Blueprint $table) {
            $table->decimal('kuota_ikhwan_kg', 6, 2)->nullable();
            $table->decimal('kuota_akhwat_kg', 6, 2)->nullable();
        });
        DB::table('pengaturan_laundry_jenjang')->update(['kuota_ikhwan_kg' => DB::raw('kuota_kg'), 'kuota_akhwat_kg' => DB::raw('kuota_kg')]);
        Schema::table('pengaturan_laundry_jenjang', fn (Blueprint $table) => $table->dropColumn('kuota_kg'));
    }

    public function down(): void
    {
        Schema::table('pengaturan_laundry', fn (Blueprint $table) => $table->decimal('kuota_bawaan_kg', 6, 2)->default(20));
        DB::table('pengaturan_laundry')->update(['kuota_bawaan_kg' => DB::raw('kuota_ikhwan_kg')]);
        Schema::table('pengaturan_laundry', fn (Blueprint $table) => $table->dropColumn(['kuota_ikhwan_kg', 'kuota_akhwat_kg']));

        Schema::table('pengaturan_laundry_jenjang', fn (Blueprint $table) => $table->decimal('kuota_kg', 6, 2)->nullable());
        DB::table('pengaturan_laundry_jenjang')->update(['kuota_kg' => DB::raw('kuota_ikhwan_kg')]);
        Schema::table('pengaturan_laundry_jenjang', fn (Blueprint $table) => $table->dropColumn(['kuota_ikhwan_kg', 'kuota_akhwat_kg']));
    }
};
