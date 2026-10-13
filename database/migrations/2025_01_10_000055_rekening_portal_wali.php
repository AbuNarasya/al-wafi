<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REKENING TUJUAN TRANSFER DI PORTAL WALI.
 *
 * `bank_accounts` juga memuat kas tunai & rekening operasional — tak semuanya
 * pantas ditunjukkan kepada wali. Bawaannya TIDAK tampil; keuangan memilihnya
 * di Keuangan → Rekening Portal Wali.
 *
 * `atas_nama`: wali perlu tahu nama pemilik rekening saat mentransfer, dan
 * `nama_rekening` adalah nama internal (mis. "BSI Operasional"), bukan nama
 * yang tercetak di buku tabungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->boolean('tampil_di_portal')->default(false)->after('status');
            $table->string('atas_nama')->nullable()->after('no_rekening');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', fn (Blueprint $t) => $t->dropColumn(['tampil_di_portal', 'atas_nama']));
    }
};
