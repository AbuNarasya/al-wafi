<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UNIT PENAMPUNG NERACA — satu unit bisnis yang memikul seluruh baris
 * LIABILITAS dan KAS/BANK, modul mana pun asalnya.
 *
 * Sebabnya asas pembukuan yang sudah berlaku di sini tanpa pernah ditulis:
 * unit bisnis adalah PUSAT LABA, jadi yang perlu dimensi unit hanya laba rugi.
 * `ReportsService::neraca()` bahkan tak menerima parameter unit sama sekali —
 * neraca memang hanya bermakna di tingkat yayasan. Selama ini `kode_unit` pada
 * baris hutang & kas tetap dipelihara padahal tak ada laporan neraca yang
 * membacanya.
 *
 * Yang dibayar mahal karena itu: pengajuan pembayaran memecah baris hutangnya
 * per unit, sehingga pembayaran SEBAGIAN mustahil dilakukan tanpa memprorata
 * porsi tiap unit — lengkap dengan sisa pembulatan yang harus dititipkan entah
 * ke mana. Dengan hutang & kas duduk di satu unit, prorata itu lenyap.
 *
 * NULL = perilaku lama (baris mengikuti unit dokumen). Sengaja nullable supaya
 * migrasi ini tak mengubah apa pun sebelum pengaturannya diisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('kode_unit_neraca')->nullable();
            $table->foreign('kode_unit_neraca')->references('kode_unit')->on('business_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['kode_unit_neraca']);
            $table->dropColumn('kode_unit_neraca');
        });
    }
};
