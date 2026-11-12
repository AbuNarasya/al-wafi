<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda AKUN UJI portal wali (keputusan user 12 Nov 2026): selama mode terbatas (masa uji coba), hanya
 * keluarga bertanda ini yang bisa memakai seluruh fungsi portal. Dicentang admin di Data Wali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wali', function (Blueprint $table) {
            $table->boolean('akun_uji')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('wali', function (Blueprint $table) {
            $table->dropColumn('akun_uji');
        });
    }
};
