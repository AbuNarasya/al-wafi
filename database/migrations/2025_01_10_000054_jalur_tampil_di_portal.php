<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JALUR YANG BOLEH DIPILIH WALI SENDIRI di pendaftaran portal.
 *
 * Tidak semua jalur pantas dipilih sendiri: "Anak Karyawan" membebaskan biaya,
 * "Lanjutan" khusus santri internal. Bawaannya TIDAK tampil — pendaftaran portal
 * tertutup sampai staf mencentang jalur yang memang terbuka untuk umum, alih-alih
 * terbuka lebar sampai ada yang ingat menutupnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->boolean('tampil_di_portal')->default(false)->after('bebas_uang_pangkal');
        });
    }

    public function down(): void
    {
        Schema::table('jalur_pendaftaran', fn (Blueprint $t) => $t->dropColumn('tampil_di_portal'));
    }
};
