<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENAHANAN PENGAMBILAN LAUNDRY (paket 3, keputusan user 3 Nov 2026): santri yang menunggak tagihan kelebihan laundry
 * yang SUDAH LEWAT jatuh tempo sebanyak ≥ N periode tak bisa mengambil cucian bersih (setor cucian kotor tetap boleh).
 * 0 = penahanan mati. Pengecualian mendesak lewat tombol "Izinkan" (hak `laundry-izin`, alasan wajib, tercatat di
 * jejak audit, boleh berkali-kali) — tanpa tabel tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengaturan_laundry', function (Blueprint $table) {
            $table->unsignedTinyInteger('tahan_setelah_periode')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_laundry', fn (Blueprint $table) => $table->dropColumn('tahan_setelah_periode'));
    }
};
