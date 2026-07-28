<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * santri.gelombang boleh KOSONG = "Tanpa Gelombang" (santri pindahan & kasus
 * lain di luar skema gelombang). Kosong berarti pencarian potongan gelombang
 * dilewati sama sekali, sehingga tak ada potongan yang menempel keliru.
 * Sengaja NULL, bukan angka sentinel (0/99), agar tak tertukar dengan gelombang
 * sungguhan pada laporan maupun pencocokan potongan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->integer('gelombang')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Santri tanpa gelombang dikembalikan ke gelombang 1 agar kolom bisa NOT NULL lagi.
        \Illuminate\Support\Facades\DB::table('santri')->whereNull('gelombang')->update(['gelombang' => 1]);

        Schema::table('santri', function (Blueprint $table) {
            $table->integer('gelombang')->default(1)->nullable(false)->change();
        });
    }
};
