<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RINCIAN BELANJA untuk portal wali (keputusan user 20 Okt 2026): kasir Wafi Mart mengirim daftar barang bersama
 * potong/kembalikan, ERP Al Wafi menyimpannya sebagai SNAPSHOT (bentuknya di [[App\Services\Modules\RincianBelanja]]).
 * Transaksi sebelum migrasi ini tak punya rincian — portal menampilkan "rincian tidak tersedia".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaksi_dompet_toko', function (Blueprint $table) {
            $table->json('rincian')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_dompet_toko', fn (Blueprint $t) => $t->dropColumn('rincian'));
    }
};
