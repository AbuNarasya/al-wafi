<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KELULUSAN. Status `alumni` sudah lama ada di `Tahap::TRANSISI` (`aktif → alumni`)
 * tetapi tak pernah ada satu pun kode yang memanggilnya — santri di tingkat
 * terakhir tak punya jalan keluar selain `undur-diri`, dan itu MEMBALIK jurnal
 * uang pangkalnya. Salah: kelulusan bukan pengunduran diri.
 *
 * `tanggal_lulus` dipisah dari `updated_at` karena tanggal ijazah/kelulusan adalah
 * fakta yang dicetak di dokumen, bukan jejak kapan barisnya terakhir disunting.
 * Tetap nullable: hanya alumni yang memilikinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->date('tanggal_lulus')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->dropColumn('tanggal_lulus');
        });
    }
};
